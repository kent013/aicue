# Codex 実装レビュー: T077 (決済 parity P6 — signup grant 契機変更 F2 + LP 文言)

## アプリの使命 (North Star / AGENTS.md 正本)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**(撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md 正本)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件 (アプリ都合で緩めない。AGENTS.md 正本)

すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404** (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通し、安全境界は `config/ssrf-pin.php` に pin する

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたは本 PR (T077 = 決済 parity フェーズ P6) の**実装レビュアー**である。
詳細設計は Codex 合議 16 ラウンドで APPROVED 済みであり、**設計が正本**。実装が設計どおりかを検証せよ。

**レビュー対象リポジトリのルート**: `/workspace/.claude/worktrees/tasks/T077`
(ファイル読み込みは許可されている。差分に現れない周辺コードを確認したい場合は実ファイルを読んでよい)

**移植元 aigenba**: `/tmp/aigenba` (読み取り専用。設計が「aigenba verbatim」と書く箇所の照合に使える)

### 前提フェーズの状態 (P1-P5 / P7 はマージ済み)

- `BillingAccess::state()` が 5 状態 (`Subscribed`/`ActiveFreePlan`/`PendingCheckout`/`ExpiredCheckout`/`NoSubscription`) を返す。`hasActiveAccess()` は `state()->grantsAccess()` の一本
- 未契約組織は onboarding へ遮断される。無料枠は `organizations.free_plan_code='personal'` の明示申告
- `plans` から `free` 行は撤去済み。`config/quota.php` の `fallback_plan` は `'personal'`
- `TicketLedgerService` は per-source clamp / 消費優先 / commit-wins 済み (P5)
- P1 で導入済み: `organizations.signup_tickets_granted_at` (marker 列) / marker backfill data migration (`2026_07_17_000110_backfill_signup_tickets_granted_at.php`: `signup_grant:%` 行を持つ org の marker を最初の付与日時で埋める) / `grantSignupGrant` の 2 引数化 / `PersonalPlanService::activate()` (org 行ロック → eligibility 再検証 → marker 条件付き先取 → 先取時のみ grant を同一 tx)
- 既存 index: `ticket_ledger_entries_signup_grant_unique` = **partial UNIQUE (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'**
- テスト helper `createOrganizationWithOwner(name, grandfatherFreePlan: true)` の既定は backfill 相当。未契約を検証したいテストは `grandfatherFreePlan: false` を明示する

### 検証コマンドの結果 (実測済み・全 green)

- `composer test`: exit 0 / 2281 tests, 2279 passed, 2 skipped, 0 failed / 9162 assertions
- `composer phpstan`: exit 0 / level 10 / 695 files / No errors (baseline 追加なし・ignoreErrors 追加なし・`@phpstan-ignore` 追加なし)
- `vendor/bin/pint --test`: exit 0
- `pnpm lint` / `pnpm typecheck`: exit 0
- `pnpm test`: exit 0 / 92 files, 838 tests passed
- `pnpm build`: exit 0

## レビュー観点 (この順で評価せよ)

1. **設計どおりか**: 下記「詳細設計 §P6」の変更箇所テーブル・主要な契約・テスト計画と実装が一致しているか。**逸脱があれば「意図的で妥当か / 見落としか」を判定**せよ
2. **禁止事項・セキュリティ不変条件への抵触**: 特に #7 (課金の冪等性)。tenant キー不信 (#1) — webhook payload 由来の値で org を解決/書き込みしていないか
3. **PHPStan level 10 適合**: `mixed` の widen・型を緩めて黙らせた箇所がないか (実測は green だが、型安全に見えて実は危ういナロイングがないか)
4. **テストが不変条件を実際に固定しているか (空振りしていないか)**: assert が実装のバグを検出しうるか。mock の `shouldIgnoreMissing()` 等でテストが骨抜きになっていないか。**削除された assert が守っていた不変条件が別の場所で守られているか**
5. **副作用・後退リスク**

### P6 固有で必ず判定すること

- **signup grant の「org 生涯 1 回」が移行前後で破れていないか (二重付与の窓)**。特に:
  - P1 デプロイ前に登録した org / P1〜P6 の間に登録した org / P6 後に登録する org のそれぞれで、marker と ledger 行の整合が保たれるか
  - ローリングデプロイ中 (旧コードと新コードが同時に動く窓) に二重付与が起きないか
  - free activate と paid webhook が並行したときに二重付与が起きないか (両経路の claim パターンが本当に対称か)
- **marker と部分 UNIQUE index の関係**: 主 (marker) と保険 (index) の二重防御が、キー形式が `signup_grant:org:{id}` → `signup_grant:personal:{id}` / `signup_grant:{stripeSubId}` へ変わっても維持されるか
- **旧 `invoice.paid` 経路の退役が漏れなく行われたか**: signup grant の残骸 (未使用の依存注入・コメント・テスト・doc) が残っていないか。月次付与 (`monthly:{invoiceId}`) が壊れていないか
- **「marker だけ立って永久に付与されない org」を作る経路が 1 つも残っていないか** (設計が最重要チェック項目と名指ししている後退)

## 出力フォーマット

```
## 総合判定
APPROVED / CHANGES_REQUESTED (どちらか一方)

## [Critical] ...   ← マージ前に必ず直すべき欠陥 (無ければ「なし」と明記)
- 該当箇所 (ファイル:行)
- 何が問題か / どういう入力・状態で壊れるか (具体的な失敗シナリオ)
- 直し方の提案

## [Warning] ...    ← 直すべきだがブロッカーではない
## [Suggestion] ... ← 任意
## 良い点
```

**根拠のない指摘は書くな**。指摘には必ず「どの入力・状態で何が壊れるか」を書け。
「設計どおりだが設計自体が誤っている」と判断した場合は、そう明示した上で根拠を示せ。

---

# 詳細設計 §P6 (正本)

### P6 signup grant 契機変更（F2: 付与を `customer.subscription.created` / free activate へ）+ LP 文言

付与契機を「登録 tx 内」→「プラン有効化時（free activate / paid サブスク成立）」へ移す。真実源は P1 で導入済みの
`organizations.signup_tickets_granted_at`（org 単位で生涯 1 回・両経路共用）。LP/料金表の「新規登録でチケット N 枚が無料」は
この変更で事実と乖離するため**同一 PR で修正**する。

**D29（Round 13 Warning の解消 = paid 経路の契機。決定済み・未決を残さない）**: **`customer.subscription.created` へ寄せる（aigenba verbatim）**。
v1 が採用した「AI-CUE 既存の `invoice.paid`（`billing_reason=subscription_create`）を維持」は**私の設計判断であり AGENTS.md の
禁止事項・セキュリティ不変条件に一切抵触しない**ため、v2 原則 2 により**撤回**する。根拠と成立確認:

- aigenba の付与契機は `customer.subscription.created` 単独（`/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:324-326`
  `if ($eventType === HandledStripeWebhookEvent::SubscriptionCreated->value) { … grantSignupInitialTickets(…) }`）。
  aigenba の `invoice.paid`（同 `:941` `handleInvoicePaid`）は**月次付与とオートリチャージのみ**で signup grant を持たない。
- **AI-CUE のスキーマ・配線で成立する**: 本契機に必要な入力は (1) `organizations.signup_tickets_granted_at`（P1 で導入済）、
  (2) stripe subscription id = `data.object.id`、(3) org 解決 = `data.object.customer` → 既存 `StripeWebhookProcessor::resolveOrganization()`
  （`app/Services/Billing/StripeWebhookProcessor.php:507-515`）の 3 点のみ。**いずれも既に存在する**。
- **購読集合の変更も不要**: `HandledStripeWebhookEvent::SubscriptionCreated`（`app/Enums/Billing/HandledStripeWebhookEvent.php:25`）は
  既に enum・購読集合・`process()` の match arm（`StripeWebhookProcessor.php:174-175`）に存在する。
  `WebhookEventSubscriptionInvariantTest` は**無改変で green**。
- **副次的に v1 の脆弱性が消える**: `invoice.paid` 契機は sub id を `data.object.parent.subscription_details.subscription` /
  `data.object.subscription` / default subscription の **3 段 fallback + fail-closed skip** で解決する必要があった。
  `customer.subscription.created` では sub id は `data.object.id` に**必ず**あるため、fallback 機構ごと不要になる。

**D30（Round 13 Warning の解消 = subscription 行 marker。決定済み）**: **`subscriptions.signup_initial_tickets_granted_at` は移植しない。**
根拠は「機能的に不要」（= 私の判断。使わない）**ではなく「AI-CUE の webhook 配線では書き込み点が成立しない」**:

- aigenba は自前の `StripeWebhookController` が `applySubscriptionSnapshot()` を先に呼び、その中で行を**新規作成**する
  （`/tmp/aigenba/app/Services/Billing/SubscriptionService.php:344-349` `$sub = Subscription::create($attrs);`）。
  よって `:326` の grant 時点で subscription 行は**必ず存在**し、`:447-455` の行 marker が書ける。
- AI-CUE は `StripeWebhookProcessor` を **Cashier の `WebhookReceived` listener**として配線している
  （`app/Providers/AppServiceProvider.php:188`）。Cashier は `WebhookReceived::dispatch($payload)` を
  **`handleCustomerSubscriptionCreated()` より前**に発火する（`vendor/laravel/cashier/src/Http/Controllers/WebhookController.php:44-49`)、
  かつ行の作成は同 `:80-91` の `$user->subscriptions()->updateOrCreate(['stripe_id' => …])` が担う。
  **= `customer.subscription.created` の処理時点で AI-CUE のローカル `subscriptions` 行は存在しない**
  （`StripeWebhookProcessor` の docblock `:43-44` / `syncSubscriptionPeriod` の docblock `:299-302` が同じ事実を明記済み。
  P2 の契約 `applySubscriptionSnapshot` も「行の作成は Cashier の責務・行不在なら skip」= 詳細設計 §P2 変更箇所の adaptation (b)）。
- したがって列を追加しても**本番では恒久的に NULL** にしかならない（`claim()` が processed event の再送を skip するため
  再配信でも埋まらない）。**状態について嘘をつく列**を作ることになり、parity にも観測性にも寄与しない。
- **最小 adaptation**: 列を追加せず、`grantSignupInitialTickets()` の引数から `Subscription $sub` を落とす（下記シグネチャ）。
  aigenba がこの列に載せている情報（「どの stripe subscription がこの org の生涯 1 回の付与を起こしたか」）は、
  **verbatim 移植する冪等キー `signup_grant:{stripeSubId}`**（`ticket_ledger_entries.idempotency_key`）が同一内容で保持する。
- **この決定を覆す条件（明示）**: P2 の「行作成は Cashier」を撤回し `applySubscriptionSnapshot` に aigenba の
  `Subscription::create($attrs)` を移植すれば列は書けるようになる。しかし Cashier の `subscription_items` 作成は
  `if (! $user->subscriptions->contains('stripe_id', …))` の**内側**にある（`WebhookController.php:71,94-101`）ため、
  先に行を作ると **`subscription_items` が永久に作られなくなる**。P6 の範囲外の回帰であり採らない。

**接地で判明した重要事実（安全性の根拠）**: AI-CUE は既に
`ticket_ledger_entries_signup_grant_unique` = **partial UNIQUE (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'**
を持つ（`database/migrations/2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php:44-47`）。
aigenba の鍵形式（`signup_grant:{stripeSubId}` / `signup_grant:personal:{orgId}`）は**いずれもこの述語にマッチする**ため、
鍵を aigenba 形式へ変えても **DB 層の「org 生涯 1 回」は維持されたまま**（marker が主・index が保険の二重防御）。

#### 変更箇所

| ファイル | 変更内容 | 移植元 (aigenba) |
|---|---|---|
| `app/Actions/Fortify/CreateNewUser.php:96-107` | `provisionPersonalOrganization()` 直後の **`grantSignupGrant($organization, …)` と P1 移行期規約で同 tx に置いた `claimSignupGrantMarker()` を「一体で」撤去**。個人組織生成のみ残す。docblock の「初回 signup grant」記述と L101-105 のコメントを削除。コンストラクタの `TicketLedgerService $tickets`（`:41`）と `use App\Services\Billing\TicketLedgerService;`（`:9`）、`PersonalPlanService` の import も除去 | 対応なし（aigenba の登録経路は grant しない） |
| `app/Services/Billing/PersonalPlanService.php`（P1 で完成済） | **`claimSignupGrantMarker()` を `public` → `private` へ戻す（D13。移行専用 API の撤去）のみ**。`activate()` 内の marker 条件付き先取 + `grantSignupGrant` は **P1 で完成済み**（P1 と P6 で activate 処理を二重定義しない）。**それ以外は変更なし・回帰確認のみ** | `/tmp/aigenba/app/Services/Billing/PersonalPlanService.php:122-125`（`activate()` 内 private） |
| `app/Services/Billing/TicketLedgerService.php` | **P1 で 2 引数化済み → P6 はコード変更なし**。`signup_grant:` prefix 契約の回帰確認のみ | — |
| `app/Services/Billing/SubscriptionService.php`（P2 で新設済） | **`grantSignupInitialTickets(Organization $org, string $stripeSubId): void` を追加**。`DB::transaction` 内で `DB::table('organizations')->where('id', $org->id)->lockForUpdate()->get()` → marker 条件付き UPDATE → `$claimed === 1` のときのみ `$this->tickets->grantSignupGrant($org, 'signup_grant:'.$stripeSubId)`。**adaptation は D30 の 1 点のみ（`Subscription $sub` 引数と行 marker ブロックを持たない）**。ctor に `TicketLedgerService` を注入 | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:432-446`（org lock → claim → grant のブロックは verbatim） |
| `app/Services/Billing/StripeWebhookProcessor.php:174-176`（match arm） | `SubscriptionCreated, SubscriptionUpdated => $this->syncSubscriptionState($payload)` を **`SubscriptionCreated => $this->syncSubscriptionState($payload, HandledStripeWebhookEvent::SubscriptionCreated)` / `SubscriptionUpdated => $this->syncSubscriptionState($payload, HandledStripeWebhookEvent::SubscriptionUpdated)` の 2 arm へ分割** | `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:195,249`（`$eventType` を handler へ引き回す形） |
| `app/Services/Billing/StripeWebhookProcessor.php:186-196`（`syncSubscriptionState`） | 第 2 引数 `HandledStripeWebhookEvent $event` を受け、**P2 の `applySubscriptionSnapshot()` 呼び出しの後**に `if ($event === HandledStripeWebhookEvent::SubscriptionCreated) { … }` で (a) `$stripeSubId = $this->stringAt($payload, 'data.object.id')`（null なら early return）、(b) `$org = $this->resolveOrganization($payload)`（null なら early return = 既存作法）、(c) `$this->subscriptions->grantSignupInitialTickets($org, $stripeSubId)` を実行。**順序（snapshot → grant）は aigenba verbatim** | `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:249,324-326` |
| `app/Services/Billing/StripeWebhookProcessor.php:266-271`（`grantMonthlyTickets` 内） | **`if ($billingReason === 'subscription_create') { $this->tickets->grantSignupGrant($organization); }` ブロックを削除**（D29。`invoice.paid` は signup grant に一切関与しなくなる = aigenba の `handleInvoicePaid` と同形）。`GRANTING_BILLING_REASONS`（`:68`）・月次付与（`monthly:{invoiceId}`）・`$plan->monthly_ticket_grant <= 0` guard は**無変更**。docblock `:36`「invoice.paid: … (+ 初回は signup grant)」を「invoice.paid: プランの monthly_ticket_grant を月次付与」へ、クラス docblock `:32-34` に created 契機の signup grant を追記 | `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:941-1010`（invoice.paid は月次のみ） |
| `resources/js/pages/Welcome.svelte:348-351` | `Free プランで今すぐ試せます。新規登録でチケット {page.signupGrantTickets} 枚が無料 (AI 解析 1 枚・動画レンダ 3 枚を消費)。` → **`Personal プラン (無料) で今すぐ試せます。プランを有効化すると、初回 1 回だけチケット {page.signupGrantTickets} 枚が無料でついてきます (AI 解析 1 枚・動画レンダ 3 枚を消費)。`** | — |
| `resources/js/pages/Pricing.svelte:168-169`（`data-testid="signup-grant-note"`） | `新規登録でチケット {N} 枚が無料でついてきます (付与から {D} 日間有効)` → **`プランを有効化すると、初回 1 回だけチケット {N} 枚が無料でついてきます (付与から {D} 日間有効)`**。Welcome:349 と同一の乖離であり同一 PR で直す | — |
| `resources/js/pages/Pricing.svelte:54`（FAQ「無料で試せますか？」） | `はい。Free プランは基本料金なしで…さらに新規登録でチケット ${N} 枚 (${D} 日間有効) が無料でついてくるので…` → **`はい。Personal プランは基本料金なしでご利用いただけます。さらにプランを有効化すると初回 1 回だけチケット ${N} 枚 (${D} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`** | — |

**用語の確定（未決にしない）**: LP は **「Personal プラン (無料)」** に統一する。根拠: (1) P6 は依存順で **P4 の後**であり、P4 で `free` Plan 行は撤去済み（D11）= 「Free プラン」は実在しないプラン名になる、(2) P1 の `PersonalPlanService::FREE_PLAN_CODE = 'personal'` / `PlanCode::Personal`、(3) P3 の Onboarding UI が「Personal 自己申告」= aigenba `Onboarding/Checkout.svelte:143` の「パーソナルプラン」と同語。

**D28 との整合（明示）**: 新文言は **「初回 1 回だけ」** を明記する。D28 で全 tier `monthly_ticket_grant=0` = 月次付与は存在しないため、繰り返し付与を示唆する表現を LP に残さない（`Pricing.svelte:29` の「月 {N} 枚のチケット付与」feature 行は **P1 で削除済み**）。

**hero CTA は文言変更不要**: L137 nav / L160 `hero-register` / L358 pricing-cta はいずれも「無料で始める」であり、Personal(free) が実在する以上 P6 後も事実。**チケットを約束していないため乖離しない**。`?plan=` handoff（`/register?plan=personal`）と `/pricing` 誘導は **P7 / D16** の責務で本フェーズはリンク先を変えない。

#### 波及変更

- **DB migration**: **ゼロ**（D30 で subscription 行 marker を追加しないため）。`organizations.signup_tickets_granted_at`（P1）・`ticket_ledger_entries_signup_grant_unique`（既存）を使うだけ = revert がコード revert のみで完結。
- **TypeScript 型定義**: **なし**。`resources/js/types/marketing.ts:9,37` の `signupGrantTickets` は**名称・型とも維持**（aigenba も config key `billing.signup_grant_tickets` / `TicketService::signupGrantTicketCount()` の命名を契機変更後も保持。意味は「初回無償付与の枚数」で不変）。
- **DTO / JsonResource**: **なし**。`LandingPageDto`（`signupGrantTickets`）/ `PricingPageDto`（`signupGrantTickets`, `signupGrantExpiryDays`）は無変更。新文言に expiry を出さないため `LandingPageDto` への列追加も不要。`PersonalPlanActivationResultDto{granted: bool}` は P1 導入済（本 PR で `granted` が初めて意味を持つ）。
- **Inertia props**: **なし**。`HandleInertiaRequests::share` は `signupGrantTickets` を共有していない（渡しているのは `HomeController.php:57` と `Marketing/PricingController.php:49-50` のページ単位 props のみ）。両 Controller とも `TicketPricingService::signupGrantTickets()` / `signupGrantExpiryDays()`（`app/Services/Billing/TicketPricingService.php:61,71`）を呼ぶだけで変更不要。
- **DI**: `CreateNewUser` から `TicketLedgerService` 依存が消える（自動解決のため binding 変更なし）。`StripeWebhookProcessor` に `SubscriptionService` を注入（`TicketLedgerService` は monthly/purchased/clawback 用に残す）。`SubscriptionService` に `TicketLedgerService` を注入。
- **テストファイル（全件）**:
  - 更新: `tests/Feature/Auth/RegistrationTest.php:26-31`
  - 更新: `tests/Feature/Auth/RegistrationInvitationPrefillTest.php:176-180`
  - 更新: `tests/Feature/Billing/TicketGrantTest.php:82-136`
  - 更新: `tests/Feature/Billing/WebhookIdempotencyTest.php:128-170`（signup grant の契機を `invoice.paid` → `customer.subscription.created` へ）
  - 更新: `tests/Feature/Billing/SignupGrantOncePerOrgTest.php`（P1 新規。`claimSignupGrantMarker()` を**直接呼んでいる箇所があれば `activate()` 経由へ書き換える** = D13 private 化の必然的波及。**レビュー時の確認項目**）
  - 更新: `tests/js/pages/Welcome.test.ts:43-45` / `tests/js/pages/Pricing.test.ts:78-81`
  - コメントのみ更新（期待は不変・green のまま）: `tests/Feature/Organization/InvitationTest.php:387-392`
  - **無変更で green を維持すべき**: `tests/Feature/Architecture/SignupGrantUniqueIndexInvariantTest.php`（index 述語 `LIKE 'signup_grant:%'` は新鍵もカバー = 本 PR の安全性の根拠。**赤くなったら設計違反**）、`tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php`（enum 無変更）、`tests/Feature/Marketing/LandingPageTest.php:18`、`tests/Feature/Marketing/PricingPageTest.php:68-69`（props 不変）
  - 新規: `tests/Feature/Billing/SignupGrantOnActivationTest.php`

#### 主要な契約

```php
// TicketLedgerService (P1 で 2 引数化済。鍵は呼び出し側が渡す。claim 判定は持たない)
public function grantSignupGrant(Organization $organization, string $idempotencyKey): void

// SubscriptionService (aigenba: SubscriptionService.php:432。D30 により $sub 引数を落とす)
public function grantSignupInitialTickets(Organization $org, string $stripeSubId): void

// PersonalPlanService (P1 で完成済。P6 は claimSignupGrantMarker の可視性のみ変更)
public function activate(Organization $org, User $declarer): PersonalPlanActivationResultDto
private function claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool  // D13

// StripeWebhookProcessor (private。match arm から event 種別を引き回す)
private function syncSubscriptionState(array $payload, HandledStripeWebhookEvent $event): void
```

`grantSignupInitialTickets()` の中核（aigenba `SubscriptionService.php:434-446` verbatim）:

```php
DB::transaction(function () use ($org, $stripeSubId): void {
    // org 行 lock で free 有効化経路 (PersonalPlanService::activate) との付与競合を直列化。
    DB::table('organizations')->where('id', $org->id)->lockForUpdate()->get();

    $claimed = DB::table('organizations')
        ->where('id', $org->id)
        ->whereNull('signup_tickets_granted_at')
        ->update(['signup_tickets_granted_at' => CarbonImmutable::now()]);
    if ($claimed === 1) {
        $this->tickets->grantSignupGrant($org, 'signup_grant:'.$stripeSubId);
    }
});
```

- **付与契機（D29）**: free = `PersonalPlanService::activate()`（P3 で `onboarding.activate-personal` へ配線済）/ paid = `customer.subscription.created`。**`invoice.paid` は signup grant に関与しない**。
- **冪等キー**: free = `signup_grant:personal:{orgId}` / paid = `signup_grant:{stripeSubId}`（aigenba verbatim）。旧 `signup_grant:org:{orgId}` は**新規発行しない**が、既存行は partial index の述語内に留まるため引き続き二重付与を弾く。
- **claim パターン（両経路で同一・交渉不可）**: 単一 `DB::transaction` 内で `org 行 lockForUpdate()` → `UPDATE organizations SET signup_tickets_granted_at=now() WHERE id=? AND signup_tickets_granted_at IS NULL` → `affected === 1` のときのみ `grantSignupGrant()`。**grant が例外なら marker ごと rollback**（付与漏れの marker を残さない）。
- **DB 列 / index**: **追加なし**（D30）。**migration ゼロのフェーズ**。
- **ルート**: 変更なし。

#### PHPStan 適合チェック（level 10）

- `grantSignupGrant` の 2 引数シグネチャ（`string` 明示 + `Assert::stringNotEmpty` / `Assert::startsWith($key, 'signup_grant:')` で narrow）は **P1 で導入済み**。P6 での型変更はない。config 読みは既存どおり `Assert::integer` / `Assert::greaterThan`（`mixed` を widen しない）。
- `DB::table(...)->update()` は `int` を返すため `$claimed === 1` は型安全（`> 0` にしない = 意味を「先取した」に固定）。
- webhook の sub id は `data_get` の `mixed` を既存 `stringAt(): ?string`（`StripeWebhookProcessor.php:530-535`）で narrow し、**`?string` のまま握らず null 分岐で早期 return**（`$stripeSubId` を non-nullable にしてから `grantSignupInitialTickets()` へ渡す）。`?->` で握り潰さない。
- **D30 の副次効果**: `grantSignupInitialTickets` が `Subscription` を受けないため、P2 で必要だった `$org->subscription('default')` の `Laravel\Cashier\Subscription|null` → `instanceof App\Models\Billing\Subscription` narrow が本経路には**発生しない**（narrow 漏れの余地が消える）。
- `syncSubscriptionState()` の第 2 引数を `HandledStripeWebhookEvent` にすることで `$event === HandledStripeWebhookEvent::SubscriptionCreated` は **enum instance 比較**になり、文字列比較由来の `alwaysFalse` を生まない（aigenba が `string $eventType` なのは同社の dispatcher が文字列ベースであるため。AI-CUE は `process()` の match が既に enum を保持している = 名前解決上の adaptation）。
- `activate()` は配列でなく `PersonalPlanActivationResultDto` を返す（禁止事項 4 の DTO 返却）。generics 新規導入なし。
- `CreateNewUser` は `TicketLedgerService` の import / promoted property を**残さず削除**（未使用 property は level 10 で検出される）。
- `claimSignupGrantMarker()` の private 化により、`PersonalPlanService` 外からの呼び出しが 1 件でも残ると **level 10 が `privateMethod.notAccessible` で検出**する（撤去漏れが静的に落ちる = D13 の安全網）。

#### テスト計画

**先に red を作る（新規）** — `tests/Feature/Billing/SignupGrantOnActivationTest.php`:

1. `登録だけではチケットが付与されず marker も立たない` — 登録 POST 後、個人組織の `balance() === 0` かつ `signup_tickets_granted_at === null`（現行実装では red）。
2. `Personal 有効化で marker 先取と同時に signup_grant:personal:{orgId} が付与される` — `activate()` が `granted === true`、`balance() === config('billing.signup_grant_tickets')`、`idempotency_key === "signup_grant:personal:{$org->id}"`、`expires_at` が `signup_grant_expiry_days` 後。
3. **`marker 済み org を再 activate しても付与されない`** — 2 の後にもう一度 `activate()` → `granted === false` / ledger 1 行 / 残高不変。
4. `paid サブスク成立 (customer.subscription.created) で signup_grant:{stripeSubId} が付与される` — marker が立ち、`idempotency_key === "signup_grant:{$stripeSubId}"`、残高 = N。
5. **`解約→再契約で再付与されない`**（aigenba が backfill で塞いだ穴の回帰） — `customer.subscription.created(sub_A)` で付与 → `customer.subscription.deleted` → **別 sub id (`sub_B`) で再度 `customer.subscription.created`** → **ledger の `signup_grant:%` 行は 1 件のまま・残高不変**。marker を一時的に null に戻しても partial index が弾くことを別 assert で固定（**二重防御の回帰**）。
6. **`free activate と paid webhook の競合で二重付与しない`** — 同一 org に marker 未設定の状態から `activate()` と `customer.subscription.created` を連続適用（**順序 2 通り**）→ **先着のみ `granted`・ledger 1 行・残高 = N**。後着は例外にせず正常終了する。
7. `付与が失敗すると marker も残らない` — `grantSignupGrant` が throw する fake に差し替えて `activate()` → 例外後 `signup_tickets_granted_at === null`（同一 tx rollback の固定）。
8. `sub id が解決できない customer.subscription.created は付与しない (fail-closed)` — payload から `data.object.id` を落とす → ledger 0 行・marker null。
9. **`invoice.paid では signup grant が走らない (D29 の回帰)`** — `billing_reason=subscription_create` の `invoice.paid` を単独適用 → **ledger の `signup_grant:%` 行 0 件・marker null**。月次付与経路（`monthly:{invoiceId}`）が生きていることは `WebhookIdempotencyTest` 側で担保。
10. `P1〜P6 の移行期に登録された org (marker 済み・旧鍵で付与済み) を activate しても再付与されない` — Factory で `signup_tickets_granted_at` + `signup_grant:org:{id}` 行を持つ org を作り `activate()` → `granted === false` / 残高不変。

**既存テストの更新（削除しない）**:

- `tests/Feature/Auth/RegistrationTest.php:26-31` — 「LP が約束する新規登録で無償チケット」の期待を **`balance($personalOrg) === 0` + `signup_tickets_granted_at === null`** へ反転（コメントも「付与はプラン有効化時」に更新）。`current_organization_id` の期待（`:33`）は維持。
- `tests/Feature/Auth/RegistrationInvitationPrefillTest.php:176-180` — 同上（「個人組織が生成され signup grant 済み」→「個人組織が生成されるが未付与」）。
- `tests/Feature/Organization/InvitationTest.php:387-392` — 期待値（0 件）は**不変で green**。コメントの根拠を「招待経由では付与しない」→「付与契機はプラン有効化時」へ更新。
- `tests/Feature/Billing/TicketGrantTest.php:82-136` — 3 test を新鍵へ。(a) `grantSignupGrant($org, "signup_grant:personal:{$org->id}")` の二重呼び出しで 1 行、(b) config 不正で停止、(c) **異なる鍵（`signup_grant:personal:{id}` と `signup_grant:sub_x`）でも部分 UNIQUE index が高々 1 行に抑える**（旧 `signup_grant:sub_legacy` 直挿入 assert はそのまま活かす）。**追加**: `signup_grant:` prefix を持たない鍵は `InvalidArgumentException`。
- `tests/Feature/Billing/WebhookIdempotencyTest.php:128-170` — signup grant の arrange を `invoicePaidPayload` → **`customer.subscription.created` payload**（`data.object.id` = sub id / `data.object.customer` = org の `stripe_id`）へ差し替え、期待鍵を **`signup_grant:org:{id}` → `signup_grant:{stripeSubId}`** へ更新 + `signup_tickets_granted_at` が立つ assert を追加。「event_id 違いの同一イベント再通知で二重付与しない」既存 assert は**契機を変えて維持**。月次付与の冪等（`monthly:{invoiceId}`）の test は `invoice.paid` のまま**無変更**。
- `tests/Feature/Billing/SignupGrantOncePerOrgTest.php`（P1 新規） — `claimSignupGrantMarker()` の直接呼び出しを `activate()` 経由へ（D13）。**org 生涯 1 回の期待は不変**。
- `tests/js/pages/Welcome.test.ts:43-45` — `landing-pricing-cta` の期待文字列を `"初回 1 回だけチケット 10 枚が無料"` へ。`:50-51` の「無料で始める」CTA assert は**不変**（hero CTA は事実のまま）。
- `tests/js/pages/Pricing.test.ts:78-81` — `signup-grant-note` の期待を `"プランを有効化すると、初回 1 回だけチケット 10 枚が無料でついてきます (付与から 30 日間有効)"` へ。`:76-77` のコメント（「文言も『新規登録で』で挙動と整合させる」）を新契機の根拠へ書き換える。

**LP 文言と実挙動の一致（乖離の再発検知）**: `SignupGrantOnActivationTest` に「LP が約束する枚数 = 有効化で実際に付与される枚数」を `config('billing.signup_grant_tickets')` 経由で突き合わせる assert を置き（`TicketPricingService::signupGrantTickets()` と `TicketLedgerService::grantSignupGrant()` が同一 config key を読むことの固定）、`Welcome.test.ts` / `Pricing.test.ts` は同じ config 由来値（10 / 30）を props に渡す。**固定値を直書きしない**ことで config 変更時に文言と実挙動が同時に追随する。

#### リスク

| リスク | 緩和 |
|---|---|
| **marker だけ残り付与されない org が生まれる**（最悪の後退。ユーザーが永久にチケットを得られない） | claim と grant を**同一 tx** に置く（grant 例外 → marker rollback）。新規テスト 7 で固定。さらに `CreateNewUser` から **marker 設定と grant を必ず一体で撤去**する（marker 設定だけ残すと全新規 org が「marked but never granted」になる。**レビュー時の最重要チェック項目**） |
| **D29 で incomplete / trialing サブスクにも付与される**（旧 `invoice.paid` 契機は「入金成立」を意味していた）。未入金のまま放置された sub が org の生涯 1 回を消費しうる | **aigenba verbatim の意図した結果**（aigenba も `customer.subscription.created` で status を問わず付与し、terminated のみ除外する = `StripeWebhookController.php:243-249,324`）。v2 原則 5「aigenba にある問題は AI-CUE 側で先回り修正しない」。AI-CUE 側の terminated 契機は `customer.subscription.deleted` のみ（P2）のため created 時点で terminated は成立せず、分岐差も生じない。実害は「10 枚（`billing.signup_grant_tickets`）・30 日期限」に限定され、partial UNIQUE index が org 単位の上限を DB で強制する |
| **subscription 行 marker 不在によりサポート調査の粒度が落ちる**（D30） | 情報内容は冪等キー `signup_grant:{stripeSubId}` が保持する（`ticket_ledger_entries` は append-only）。「どの sub が付与を起こしたか」は `SELECT idempotency_key … WHERE organization_id=?` で復元可能。列を足しても Cashier の event 順序（`WebhookController.php:44` → `:80`）により**恒久 NULL** にしかならず、調査粒度は上がらない |
| **revert 時の付与漏れ**: P6 後〜revert までに登録した org は marker/付与とも無く、旧コードは登録時にしか付与しない | データ変更ゼロ（migration なし）のためコード revert は即時。残余は `signup_grant:org:{id}` 鍵での一括付与で救済可能（partial index が二重付与を弾くため**無条件に流して安全**） |
| **`customer.subscription.created` で sub id / org が解決できず grant を skip** | sub id は `data.object.id` = Stripe の subscription object 本体の必須フィールド（`invoice.paid` 契機で必要だった 3 段 fallback は不要）。org 不明は既存 `resolveOrganization()` の「他環境 webhook は受理のみ」作法と同一。新規テスト 8 |
| **LP 文言変更でコンバージョンが落ちる**（「新規登録で無料」→「有効化すると無料」） | 文言と実挙動の乖離は F-07 の根本原因そのもの（概念設計）。「無料で始める」CTA と「Personal プラン (無料) で今すぐ試せます」は維持しており、無料訴求の強度は保たれる |
| `TicketPricingService::signupGrantTickets()` の命名が契機と食い違って読める | aigenba も `billing.signup_grant_tickets` / `signupGrantTicketCount()` を契機変更後も保持。rename は TS 型・DTO・2 Controller・4 テストへ波及するだけで parity を損なうため**しない**。docblock（`TicketPricingService.php:58-60`）で「付与契機はプラン有効化時」と明記して補う |

---


---

# 実装差分 (git diff main...HEAD)

```diff
diff --git a/app/Actions/Fortify/CreateNewUser.php b/app/Actions/Fortify/CreateNewUser.php
index f36a30b..7ed1e17 100644
--- a/app/Actions/Fortify/CreateNewUser.php
+++ b/app/Actions/Fortify/CreateNewUser.php
@@ -7,8 +7,6 @@
 use App\Models\User;
 use App\Rules\MatchesInvitationEmail;
 use App\Rules\UniqueEncryptedEmail;
-use App\Services\Billing\PersonalPlanService;
-use App\Services\Billing\TicketLedgerService;
 use App\Services\Onboarding\IntendedPlanResolver;
 use App\Services\Organization\OrganizationMembershipService;
 use App\Services\Organization\OrganizationProvisioningService;
@@ -44,8 +42,6 @@ class CreateNewUser implements CreatesNewUsers
     public function __construct(
         private readonly OrganizationProvisioningService $provisioning,
         private readonly OrganizationMembershipService $membership,
-        private readonly TicketLedgerService $tickets,
-        private readonly PersonalPlanService $personalPlan,
         private readonly IntendedPlanResolver $intendedPlanResolver,
     ) {}
 
@@ -107,25 +103,14 @@ public function create(array $input): User
 
                 if ($joined === null) {
                     // 個人用組織を同一 transaction 内で原子的に生成する
-                    // (user だけ存在し組織なしの中間状態を作らない)
-                    $organization = $this->provisioning->provisionPersonalOrganization($user);
-
-                    // 初回 signup grant (無償 10 枚 / 30 日)。LP が約束する「新規登録で 10 枚」を実現する。
-                    // grantSignupGrant は純粋な ledger insert (通知・イベント・外部 I/O なし) のため登録 tx 内で完結し、
-                    // 冪等性は idempotency_key + 部分 UNIQUE index が DB レベルで保証する。
-                    // 招待経由 (join) は個人組織を作らず所属組織の残高を共有するため、ここでは付与しない
-                    // (招待 N 人 = N×10 の増幅を避ける)。
+                    // (user だけ存在し組織なしの中間状態を作らない)。
                     //
-                    // 移行期規約: 付与契機は登録時のまま維持しつつ、org 単位 1 回マーカー
-                    // (organizations.signup_tickets_granted_at) を同一 tx で先取する。マーカーを
-                    // 先取できたときのみ付与することで、free 有効化 (PersonalPlanService::activate)
-                    // 経路との二重付与を防ぐ (マーカー先取と付与が同一 tx = 原子的)。
-                    $organizationId = $organization->getKey();
-                    Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');
-
-                    if ($this->personalPlan->claimSignupGrantMarker($organization)) {
-                        $this->tickets->grantSignupGrant($organization, "signup_grant:org:{$organizationId}");
-                    }
+                    // 初回 signup grant はここでは付与しない (P6/F2)。付与契機はプラン有効化時
+                    // (free = PersonalPlanService::activate / paid = customer.subscription.created)
+                    // であり、marker (organizations.signup_tickets_granted_at) の先取と付与は
+                    // その経路の同一 tx に閉じている。**marker 設定だけをここに残してはならない**
+                    // (付与されない marker 済み org = 永久に付与を受けられない org になる)。
+                    $this->provisioning->provisionPersonalOrganization($user);
                 }
 
                 return $user;
diff --git a/app/Services/Billing/PersonalPlanService.php b/app/Services/Billing/PersonalPlanService.php
index d4f8d71..69801b1 100644
--- a/app/Services/Billing/PersonalPlanService.php
+++ b/app/Services/Billing/PersonalPlanService.php
@@ -116,12 +116,13 @@ public function retireForPaidSubscription(Organization $org): void
      * 初回付与マーカー (`signup_tickets_granted_at`) を条件付き UPDATE で先取する。
      * 先取できた (= この呼び出しが org 生涯で初回) ときのみ true。
      *
-     * **移行期専用の public API**: signup grant の付与契機が登録時 (CreateNewUser) のままの間、
-     * 登録経路からも marker を立てる必要があるため public にしている。付与契機を activate へ
-     * 移す P6 で private へ戻す (詳細設計 D13)。呼び出し側は org 行 lockForUpdate 下・付与と
-     * 同一 transaction で使うこと (先取と付与が原子的でないと二重付与の窓ができる)。
+     * **private (D13)**: 移行期に登録経路 (CreateNewUser) から marker を立てるため一時的に
+     * public にしていたが、P6 で付与契機が activate / customer.subscription.created へ移ったため
+     * 本クラス内へ戻した。呼び出しは org 行 lockForUpdate 下・付与と同一 transaction であること
+     * (先取と付与が原子的でないと二重付与の窓ができる)。paid 経路の同型実装は
+     * SubscriptionService::grantSignupInitialTickets が持つ。
      */
-    public function claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool
+    private function claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool
     {
         $claimed = DB::table('organizations')
             ->where('id', $org->getKey())
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 3da396b..3575bd9 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -30,9 +30,11 @@
  * 2. type 別 handler:
  *    - customer.subscription.created/updated: payload → SubscriptionSnapshot を
  *      SubscriptionService::applySubscriptionSnapshot へ渡して状態同期 +
- *      recordPaymentMethodSnapshot で決済手段有無を記録
+ *      recordPaymentMethodSnapshot で決済手段有無を記録。
+ *      **created のみ**、状態同期のあとに初回無償チケット (signup grant) を
+ *      SubscriptionService::grantSignupInitialTickets で付与する (P6/F2 の paid 側付与契機)
  *    - customer.subscription.deleted: 同上 (terminated=true。plan_code 解除 + schedule クリア)
- *    - invoice.paid: プランの monthly_ticket_grant を月次付与 (+ 初回は signup grant)
+ *    - invoice.paid: プランの monthly_ticket_grant を月次付与 (signup grant には関与しない)
  *    - invoice.payment_failed: 支払い失敗通知 (BillingNotificationDispatcher 経由の send-once)
  *    - charge.refunded: 買い切りチケットの返金逆仕訳 (clawback)
  * 3. 失敗時は status=failed + failure_reason 記録 + report して再 throw (Cashier 既定どおり
@@ -69,7 +71,6 @@ class StripeWebhookProcessor
     public function __construct(
         private readonly TicketLedgerService $tickets,
         private readonly BillingNotificationDispatcher $notifications,
-        private readonly PersonalPlanService $personalPlan,
         private readonly SubscriptionService $subscriptions,
     ) {}
 
@@ -172,9 +173,18 @@ private function process(string $type, array $payload): void
         // 処理イベント集合の単一出典は HandledStripeWebhookEvent (購読集合の導出元)。
         // case を足したらここに arm を足す (handled ⊆ subscribed は invariant test が担保)
         match (HandledStripeWebhookEvent::tryFrom($type)) {
-            HandledStripeWebhookEvent::SubscriptionCreated,
-            HandledStripeWebhookEvent::SubscriptionUpdated => $this->syncSubscriptionState($payload, terminated: false),
-            HandledStripeWebhookEvent::SubscriptionDeleted => $this->syncSubscriptionState($payload, terminated: true),
+            HandledStripeWebhookEvent::SubscriptionCreated => $this->syncSubscriptionState(
+                $payload,
+                HandledStripeWebhookEvent::SubscriptionCreated,
+            ),
+            HandledStripeWebhookEvent::SubscriptionUpdated => $this->syncSubscriptionState(
+                $payload,
+                HandledStripeWebhookEvent::SubscriptionUpdated,
+            ),
+            HandledStripeWebhookEvent::SubscriptionDeleted => $this->syncSubscriptionState(
+                $payload,
+                HandledStripeWebhookEvent::SubscriptionDeleted,
+            ),
             HandledStripeWebhookEvent::InvoicePaid => $this->grantMonthlyTickets($payload),
             HandledStripeWebhookEvent::ChargeRefunded => $this->clawbackRefundedTickets($payload),
             HandledStripeWebhookEvent::InvoicePaymentFailed => $this->handleInvoicePaymentFailed($payload),
@@ -193,16 +203,24 @@ private function process(string $type, array $payload): void
      * Cashier の同期処理より先に発火するため、created イベント時点では行が無いことがある
      * (best-effort: 直後の customer.subscription.updated / 次周期の更新で追随する)。
      *
+     * `customer.subscription.created` では状態同期のあとに初回無償チケット
+     * (signup grant) を付与する (P6/F2)。付与の可否判定・冪等性は SubscriptionService が持つ。
+     *
      * @param  array<mixed>  $payload
-     * @param  bool  $terminated  customer.subscription.deleted のとき true (終了契機はこれのみ)
+     * @param  HandledStripeWebhookEvent  $event  created / updated / deleted のいずれか
+     *                                            (deleted のみ terminated = 終了契機)
      */
-    private function syncSubscriptionState(array $payload, bool $terminated): void
+    private function syncSubscriptionState(array $payload, HandledStripeWebhookEvent $event): void
     {
+        $terminated = $event === HandledStripeWebhookEvent::SubscriptionDeleted;
+
         $organization = $this->resolveOrganization($payload);
         if ($organization === null) {
             return;
         }
 
+        // sub id は subscription object 本体の必須フィールド。取れない payload は fail-closed
+        // (状態同期も signup grant も行わない)。
         $stripeId = $this->stringAt($payload, 'data.object.id');
         if ($stripeId === null) {
             return;
@@ -222,6 +240,11 @@ private function syncSubscriptionState(array $payload, bool $terminated): void
 
         $this->subscriptions->applySubscriptionSnapshot($organization, $snapshot, terminated: $terminated);
 
+        // 初回無償チケットの付与契機 (paid 側)。順序 (snapshot → grant) は aigenba verbatim。
+        if ($event === HandledStripeWebhookEvent::SubscriptionCreated) {
+            $this->subscriptions->grantSignupInitialTickets($organization, $stripeId);
+        }
+
         if ($terminated) {
             return; // 終了系では PM snapshot を記録しない (monotonic writer は契約中のみ)
         }
@@ -298,11 +321,13 @@ private function intAt(array $payload, string $path): ?int
 
     /**
      * invoice.paid: 契約プランの monthly_ticket_grant を月次付与する。
-     * 初回 (billing_reason=subscription_create) はあわせて signup grant を付与する。
+     *
+     * **signup grant には一切関与しない (P6/D29)**。初回無償チケットの付与契機は
+     * プラン有効化時 (free = PersonalPlanService::activate / paid =
+     * customer.subscription.created) のみ。
      *
      * 冪等性は claim() の event_id UNIQUE に加え、台帳の idempotency_key
-     * (monthly:{invoiceId} / signup_grant:{subscriptionId}) が保証する
-     * (event_id 違いの同一 invoice 再通知でも二重付与しない)。
+     * (monthly:{invoiceId}) が保証する (event_id 違いの同一 invoice 再通知でも二重付与しない)。
      *
      * @param  array<mixed>  $payload
      */
@@ -318,31 +343,6 @@ private function grantMonthlyTickets(array $payload): void
             return; // サブスク以外の請求 (one-time 等) では付与しない
         }
 
-        // 初回 signup grant (「まず触れる」導線)。冪等キーは org スコープのため subscription id は不要。
-        // 1 組織 1 回の不変条件は idempotency_key + 部分 UNIQUE index が保証する。
-        // (通常は登録時に付与済のため、ここは非個人組織のサブスク等に対する no-op ないし 1 回付与の安全網)
-        if ($billingReason === 'subscription_create') {
-            $organizationId = $organization->getKey();
-            Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');
-
-            // 移行期規約 (CreateNewUser / PersonalPlanService::activate と同一): org 行ロック下の
-            // 単一 transaction で「marker の条件付き先取 → 先取できたときのみ付与」を原子的に行う。
-            // marker (organizations.signup_tickets_granted_at) が付与の唯一の真実源であるため:
-            //  - marker を立てないと、「登録経由でない org (追加組織) が初回契約で付与を受ける」経路で
-            //    付与済みなのに marker が NULL のまま残り、後続の activate() が claim に成功して
-            //    granted=true を返すのに ledger の org スコープ UNIQUE が実 insert を止める
-            //    (= 残高は動かないのに「付与した」と応答する) 不整合が生じる。
-            //  - 逆に marker だけ先に commit されて付与が失敗すると、marker が立っているため
-            //    再送でも二度と付与されない (= 付与の取りこぼしが恒久化する)。よって同一 tx に閉じる。
-            DB::transaction(function () use ($organizationId): void {
-                $locked = Organization::query()->lockForUpdate()->findOrFail($organizationId);
-
-                if ($this->personalPlan->claimSignupGrantMarker($locked)) {
-                    $this->tickets->grantSignupGrant($locked, "signup_grant:org:{$organizationId}");
-                }
-            });
-        }
-
         $plan = $this->resolveInvoicePlan($payload, $organization);
         if ($plan === null || $plan->monthly_ticket_grant <= 0) {
             return;
diff --git a/app/Services/Billing/SubscriptionService.php b/app/Services/Billing/SubscriptionService.php
index cc1341a..99a340b 100644
--- a/app/Services/Billing/SubscriptionService.php
+++ b/app/Services/Billing/SubscriptionService.php
@@ -35,8 +35,47 @@ class SubscriptionService
 
     public function __construct(
         private readonly StripeGatewayInterface $gateway,
+        private readonly TicketLedgerService $tickets,
     ) {}
 
+    /**
+     * paid サブスク成立 (customer.subscription.created) 時の初回無償チケット付与。
+     *
+     * 付与は「org 単位で生涯 1 回」: 真実源は `organizations.signup_tickets_granted_at` で、
+     * org 行 lock 下の条件付き UPDATE を先取できた経路のみ grant する
+     * (free 有効化経路 PersonalPlanService::activate と共用の真実源・同型の claim パターン)。
+     * 解約→再契約 (別 subscription id) でも marker が立っているため再付与されない。
+     *
+     * claim と grant は同一 transaction に閉じる。grant が失敗したら marker ごと rollback され、
+     * 「marker だけ立って永久に付与されない org」を作らない。
+     *
+     * 冪等キー `signup_grant:{stripeSubId}` は監査上の由来表現であり、二重付与の防波堤は
+     * marker (主) と ticket_ledger_entries の部分 UNIQUE index
+     * (organization_id WHERE idempotency_key LIKE 'signup_grant:%') (保険) の二重防御。
+     *
+     * subscription 行側の marker は持たない (D30): AI-CUE では subscriptions 行の作成は Cashier の
+     * WebhookController が担い、本経路 (WebhookReceived listener) はそれより先に走るため
+     * created 時点で行が存在せず、列を足しても恒久 NULL にしかならない。
+     */
+    public function grantSignupInitialTickets(Organization $org, string $stripeSubId): void
+    {
+        Assert::stringNotEmpty($stripeSubId);
+
+        DB::transaction(function () use ($org, $stripeSubId): void {
+            // org 行 lock で free 有効化経路 (PersonalPlanService::activate) との付与競合を直列化。
+            DB::table('organizations')->where('id', $org->getKey())->lockForUpdate()->get();
+
+            $claimed = DB::table('organizations')
+                ->where('id', $org->getKey())
+                ->whereNull('signup_tickets_granted_at')
+                ->update(['signup_tickets_granted_at' => CarbonImmutable::now()]);
+
+            if ($claimed === 1) {
+                $this->tickets->grantSignupGrant($org, 'signup_grant:'.$stripeSubId);
+            }
+        });
+    }
+
     /**
      * subscription の利用可否 (entitlement) を確定する **唯一の経路**。
      *
diff --git a/app/Services/Billing/TicketLedgerService.php b/app/Services/Billing/TicketLedgerService.php
index 8af3a90..a01f75e 100644
--- a/app/Services/Billing/TicketLedgerService.php
+++ b/app/Services/Billing/TicketLedgerService.php
@@ -94,22 +94,29 @@ public function grantMonthly(
     /**
      * 初回 signup grant (「まず触れる」導線の無償チケット)。
      *
-     * 通常登録の完了時 (個人組織生成直後) と、Stripe サブスク作成の支払い確定時
-     * (invoice.paid, billing_reason=subscription_create) の双方から呼ばれる。
+     * 付与契機は**プラン有効化時のみ** (P6/F2): free = PersonalPlanService::activate /
+     * paid = customer.subscription.created (SubscriptionService::grantSignupInitialTickets)。
+     * 登録 (CreateNewUser) と invoice.paid はこの経路を呼ばない。
      * 枚数は config('billing.signup_grant_tickets')、期限は now + config('billing.signup_grant_expiry_days') 日。
      *
-     * **1 組織につき高々 1 回**の不変条件は、冪等キー ($idempotencyKey) の UNIQUE と、
+     * **1 組織につき高々 1 回**の不変条件は、呼び出し側が先取する marker
+     * (organizations.signup_tickets_granted_at) を主とし、冪等キー ($idempotencyKey) の UNIQUE と
      * ticket_ledger_entries の部分 UNIQUE index (organization_id WHERE idempotency_key LIKE 'signup_grant:%')
-     * が DB レベルで原子的に保証する。旧キー (signup_grant:{subId}) 行が既にある組織でも、部分 index が
-     * 同一述語でカバーするため insertOrIgnore が二重付与を弾く (アプリ層の存在チェックは不要)。
+     * が DB レベルで原子的に保証する (保険)。旧キー (signup_grant:org:{orgId}) 行が既にある組織でも、
+     * 部分 index が同一述語でカバーするため insertOrIgnore が二重付与を弾く (アプリ層の存在チェックは不要)。
      *
      * $idempotencyKey は経路を表す `signup_grant:` 接頭辞付きのキーを呼び出し側が渡す
-     * (登録経路 = `signup_grant:org:{orgId}` / free 有効化 = `signup_grant:personal:{orgId}`)。
+     * (free 有効化 = `signup_grant:personal:{orgId}` / paid = `signup_grant:{stripeSubId}`)。
      * 部分 UNIQUE index が述語 `LIKE 'signup_grant:%'` で経路を跨いで org 生涯 1 回に閉じるため、
      * キーの違いは監査上の由来表現であって二重付与の窓にはならない。
      */
     public function grantSignupGrant(Organization $organization, string $idempotencyKey): void
     {
+        // 接頭辞は部分 UNIQUE index の述語 (LIKE 'signup_grant:%') と対応する契約。外れたキーで
+        // 付与すると「org 生涯 1 回」の DB 保証をすり抜けるため fail-closed で停止する。
+        Assert::stringNotEmpty($idempotencyKey);
+        Assert::startsWith($idempotencyKey, 'signup_grant:', 'signup grant の冪等キーは signup_grant: で始めてください');
+
         $count = config('billing.signup_grant_tickets');
         Assert::integer($count, 'config billing.signup_grant_tickets は整数で設定してください');
         Assert::greaterThan($count, 0, 'signup_grant_tickets は 1 以上で設定してください');
diff --git a/app/Services/Billing/TicketPricingService.php b/app/Services/Billing/TicketPricingService.php
index 7381667..a261d0a 100644
--- a/app/Services/Billing/TicketPricingService.php
+++ b/app/Services/Billing/TicketPricingService.php
@@ -57,6 +57,10 @@ public function spotUnitAmount(): int
     /**
      * 初回 signup grant の枚数 (config billing.signup_grant_tickets)。
      * TicketLedgerService::grantSignupGrant と同じ config key を読む表示用の口。
+     *
+     * **付与契機はプラン有効化時** (free = PersonalPlanService::activate /
+     * paid = customer.subscription.created) で org 生涯 1 回 (P6/F2)。
+     * 「signup」は登録時付与だった頃の名残の命名であり、契機を表さない。
      */
     public function signupGrantTickets(): int
     {
diff --git a/resources/js/pages/Pricing.svelte b/resources/js/pages/Pricing.svelte
index d3ccc98..a957e9b 100644
--- a/resources/js/pages/Pricing.svelte
+++ b/resources/js/pages/Pricing.svelte
@@ -50,7 +50,7 @@
     const faqs = $derived([
         {
             q: "無料で試せますか？",
-            a: `はい。Free プランは基本料金なしでご利用いただけます。さらに新規登録でチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
+            a: `はい。Personal プランは基本料金なしでご利用いただけます。さらにプランを有効化すると初回 1 回だけチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
         },
         {
             q: "チケットは何に使いますか？",
@@ -166,7 +166,8 @@
                 class="mt-4 rounded-lg border border-primary/30 bg-primary-soft px-4 py-3 text-center text-body text-text"
                 data-testid="signup-grant-note"
             >
-                新規登録でチケット {page.signupGrantTickets} 枚が無料でついてきます (付与から {page.signupGrantExpiryDays}
+                プランを有効化すると、初回 1 回だけチケット {page.signupGrantTickets} 枚が無料でついてきます (付与から
+                {page.signupGrantExpiryDays}
                 日間有効)
             </p>
             {#if tierRows.length > 0}
diff --git a/resources/js/pages/Welcome.svelte b/resources/js/pages/Welcome.svelte
index d8c3ddf..fbe42ee 100644
--- a/resources/js/pages/Welcome.svelte
+++ b/resources/js/pages/Welcome.svelte
@@ -346,8 +346,8 @@
     <section class="rounded-lg bg-surface px-6 py-14 text-center" data-testid="landing-pricing-cta">
         <h2 class="text-h2 text-text">無料で始められます。</h2>
         <p class="mx-auto mt-3 max-w-2xl text-body text-text-secondary">
-            Free プランで今すぐ試せます。新規登録でチケット {page.signupGrantTickets} 枚が無料
-            (AI 解析 1 枚・動画レンダ 3 枚を消費)。
+            Personal プラン (無料) で今すぐ試せます。プランを有効化すると、初回 1 回だけチケット
+            {page.signupGrantTickets} 枚が無料でついてきます (AI 解析 1 枚・動画レンダ 3 枚を消費)。
         </p>
         <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
             {#if page.isAuthenticated}
diff --git a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
index 53415f4..64eafbb 100644
--- a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
+++ b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
@@ -174,10 +174,10 @@ function makeInvitationWithToken(string $email = 'invitee@example.com'): array
     // 招待組織のメンバーシップには含まれない
     expect($organization->users()->whereKey($user->getKey())->exists())->toBeFalse();
 
-    // 個人組織が生成され signup grant 済み
+    // 個人組織は生成されるが未付与 (P6/F2: 付与契機はプラン有効化時)
     $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
-    expect(app(TicketLedgerService::class)->balance($personalOrg)->totalAvailable())
-        ->toBe(config()->integer('billing.signup_grant_tickets'));
+    expect(app(TicketLedgerService::class)->balance($personalOrg)->totalAvailable())->toBe(0);
+    expect($personalOrg->signup_tickets_granted_at)->toBeNull();
 
     // current_organization_id は個人組織側 (招待組織側でない)
     expect($user->current_organization_id)->toBe($personalOrg->id);
diff --git a/tests/Feature/Auth/RegistrationTest.php b/tests/Feature/Auth/RegistrationTest.php
index 532ce26..8236be4 100644
--- a/tests/Feature/Auth/RegistrationTest.php
+++ b/tests/Feature/Auth/RegistrationTest.php
@@ -24,11 +24,12 @@
     expect($user->terms_accepted_at)->not->toBeNull();
     expect($user->consent_version)->toBe(config()->string('legal.consent_version'));
 
-    // LP が約束する「新規登録で無償チケット」を個人組織へ付与する。
-    // 固定値ではなく config 由来値を期待に使う (設定変更後も意味が一貫する)。
+    // P6/F2: 登録では初回無償チケットを付与しない (付与契機はプラン有効化時 =
+    // free は PersonalPlanService::activate / paid は customer.subscription.created)。
+    // marker も立てない (marker だけ立つと永久に付与されない org になる)。
     $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
-    expect(app(TicketLedgerService::class)->balance($personalOrg)->totalAvailable())
-        ->toBe(config()->integer('billing.signup_grant_tickets'));
+    expect(app(TicketLedgerService::class)->balance($personalOrg)->totalAvailable())->toBe(0);
+    expect($personalOrg->signup_tickets_granted_at)->toBeNull();
 
     // [分岐 B 固定] 通常登録では現在組織が個人組織に確定する (招待成立分岐と排他)
     expect($user->current_organization_id)->toBe($personalOrg->id);
diff --git a/tests/Feature/Billing/SignupGrantOnActivationTest.php b/tests/Feature/Billing/SignupGrantOnActivationTest.php
new file mode 100644
index 0000000..7ee1149
--- /dev/null
+++ b/tests/Feature/Billing/SignupGrantOnActivationTest.php
@@ -0,0 +1,285 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\PersonalPlanService;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Billing\TicketPricingService;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Collection;
+use Laravel\Cashier\Events\WebhookReceived;
+
+/*
+|--------------------------------------------------------------------------
+| P6 (F2): 初回無償チケットの付与契機を「プラン有効化時」へ移す
+|--------------------------------------------------------------------------
+|
+| 付与契機:
+|   - free : PersonalPlanService::activate()          → signup_grant:personal:{orgId}
+|   - paid : customer.subscription.created (webhook)  → signup_grant:{stripeSubId}
+|
+| 登録 (CreateNewUser) と invoice.paid は signup grant に一切関与しない (D29)。
+| 真実源は organizations.signup_tickets_granted_at (条件付き UPDATE の先取)。
+| 二重防御として ticket_ledger_entries の部分 UNIQUE index
+| (organization_id WHERE idempotency_key LIKE 'signup_grant:%') が経路を跨いで
+| org 生涯 1 行に閉じる。
+*/
+
+function activationGrantCustomer(string $stripeId = 'cus_activation_grant'): Organization
+{
+    // 未契約 (無料枠の自己申告もまだ) の組織を作る = activate() の対象になれる状態
+    [$organization] = createOrganizationWithOwner('付与契機テスト組織', grandfatherFreePlan: false);
+    // stripe_id は Cashier customer column (状態キー)。テストでは明示代入する
+    $organization->stripe_id = $stripeId;
+    $organization->save();
+
+    return $organization;
+}
+
+/**
+ * paid サブスク成立 (customer.subscription.created)。
+ * signup grant に必要なのは data.object.id (sub id) と data.object.customer のみ。
+ *
+ * @return array<string, mixed>
+ */
+function subscriptionCreatedPayload(
+    string $eventId = 'evt_sub_created_grant',
+    string $stripeSubId = 'sub_activation_a',
+    string $stripeId = 'cus_activation_grant',
+): array {
+    return [
+        'id' => $eventId,
+        'type' => 'customer.subscription.created',
+        'data' => [
+            'object' => [
+                'id' => $stripeSubId,
+                'customer' => $stripeId,
+                'status' => 'active',
+            ],
+        ],
+    ];
+}
+
+function activationSignupEntries(Organization $organization): Collection
+{
+    return $organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'signup_grant:%')
+        ->get();
+}
+
+function activationBalance(Organization $organization): int
+{
+    return app(TicketLedgerService::class)->balance($organization)->totalAvailable();
+}
+
+test('登録だけではチケットが付与されず marker も立たない', function (): void {
+    $this->post('/register', [
+        'name' => '山田 太郎',
+        'email' => 'p6-signup@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ])->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', 'p6-signup@example.com')->firstOrFail();
+    $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    expect(activationBalance($organization))->toBe(0);
+    expect(activationSignupEntries($organization))->toHaveCount(0);
+    expect($organization->signup_tickets_granted_at)->toBeNull();
+});
+
+test('Personal 有効化で marker 先取と同時に signup_grant:personal:{orgId} が付与される', function (): void {
+    $organization = activationGrantCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    $result = app(PersonalPlanService::class)->activate($organization, $owner);
+
+    expect($result->granted)->toBeTrue();
+
+    // LP が約束する枚数 (TicketPricingService) = 実際に付与される枚数 (TicketLedgerService)。
+    // 固定値を直書きしないことで config 変更時に文言と実挙動が同時に追随する。
+    $promised = app(TicketPricingService::class)->signupGrantTickets();
+    expect(activationBalance($organization))->toBe($promised);
+    expect($promised)->toBe(config()->integer('billing.signup_grant_tickets'));
+
+    $entries = activationSignupEntries($organization);
+    expect($entries)->toHaveCount(1);
+    expect($entries->first()?->idempotency_key)->toBe("signup_grant:personal:{$organization->id}");
+    expect($entries->first()?->expires_at?->toDateString())->toBe(
+        CarbonImmutable::now()->addDays(app(TicketPricingService::class)->signupGrantExpiryDays())->toDateString(),
+    );
+
+    expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();
+});
+
+test('marker 済み org を再 activate しても付与されない', function (): void {
+    $organization = activationGrantCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    app(PersonalPlanService::class)->activate($organization, $owner);
+    $balanceBefore = activationBalance($organization);
+
+    $second = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);
+
+    expect($second->granted)->toBeFalse();
+    expect(activationSignupEntries($organization))->toHaveCount(1);
+    expect(activationBalance($organization))->toBe($balanceBefore);
+});
+
+test('paid サブスク成立 (customer.subscription.created) で signup_grant:{stripeSubId} が付与される', function (): void {
+    $organization = activationGrantCustomer();
+
+    event(new WebhookReceived(subscriptionCreatedPayload()));
+
+    $entries = activationSignupEntries($organization);
+    expect($entries)->toHaveCount(1);
+    expect($entries->first()?->idempotency_key)->toBe('signup_grant:sub_activation_a');
+    expect(activationBalance($organization))->toBe(config()->integer('billing.signup_grant_tickets'));
+    expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();
+});
+
+test('解約→再契約で再付与されない (marker と部分 UNIQUE index の二重防御)', function (): void {
+    $organization = activationGrantCustomer();
+
+    event(new WebhookReceived(subscriptionCreatedPayload('evt_sub_a', 'sub_activation_a')));
+    expect(activationSignupEntries($organization))->toHaveCount(1);
+    $balanceAfterFirst = activationBalance($organization);
+
+    // 解約
+    $deleted = subscriptionCreatedPayload('evt_sub_a_deleted', 'sub_activation_a');
+    $deleted['type'] = 'customer.subscription.deleted';
+    $deleted['data']['object']['status'] = 'canceled';
+    event(new WebhookReceived($deleted));
+
+    // 別 subscription で再契約 → marker が立っているため付与されない
+    event(new WebhookReceived(subscriptionCreatedPayload('evt_sub_b', 'sub_activation_b')));
+
+    expect(activationSignupEntries($organization))->toHaveCount(1);
+    expect(activationBalance($organization))->toBe($balanceAfterFirst);
+
+    // 二重防御の回帰: marker を人為的に落としても部分 UNIQUE index が二重付与を弾く
+    $organization->forceFill(['signup_tickets_granted_at' => null])->save();
+    event(new WebhookReceived(subscriptionCreatedPayload('evt_sub_c', 'sub_activation_c')));
+
+    expect(activationSignupEntries($organization))->toHaveCount(1);
+    expect(activationBalance($organization))->toBe($balanceAfterFirst);
+});
+
+test('free activate 先着 → paid webhook 後着でも二重付与しない', function (): void {
+    $organization = activationGrantCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    $result = app(PersonalPlanService::class)->activate($organization, $owner);
+    expect($result->granted)->toBeTrue();
+    $balanceBefore = activationBalance($organization);
+
+    event(new WebhookReceived(subscriptionCreatedPayload()));
+
+    expect(activationSignupEntries($organization))->toHaveCount(1);
+    expect(activationSignupEntries($organization)->first()?->idempotency_key)
+        ->toBe("signup_grant:personal:{$organization->id}");
+    expect(activationBalance($organization))->toBe($balanceBefore);
+});
+
+test('paid webhook 先着 → free activate 後着でも二重付与しない', function (): void {
+    $organization = activationGrantCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    event(new WebhookReceived(subscriptionCreatedPayload()));
+    expect(activationSignupEntries($organization))->toHaveCount(1);
+    $balanceBefore = activationBalance($organization);
+
+    // 後着は例外にせず正常終了する (granted=false)
+    $result = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);
+
+    expect($result->granted)->toBeFalse();
+    expect(activationSignupEntries($organization))->toHaveCount(1);
+    expect(activationSignupEntries($organization)->first()?->idempotency_key)
+        ->toBe('signup_grant:sub_activation_a');
+    expect(activationBalance($organization))->toBe($balanceBefore);
+});
+
+test('付与が失敗すると marker も残らない (free activate: 同一 tx rollback)', function (): void {
+    $organization = activationGrantCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    $this->mock(TicketLedgerService::class, function ($mock): void {
+        $mock->shouldReceive('grantSignupGrant')
+            ->once()
+            ->andThrow(new RuntimeException('grant failed'));
+        $mock->shouldIgnoreMissing();
+    });
+
+    expect(fn () => app(PersonalPlanService::class)->activate($organization, $owner))
+        ->toThrow(RuntimeException::class);
+
+    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
+});
+
+test('付与が失敗すると marker も残らない (paid webhook: 同一 tx rollback)', function (): void {
+    $organization = activationGrantCustomer();
+
+    $this->mock(TicketLedgerService::class, function ($mock): void {
+        $mock->shouldReceive('grantSignupGrant')
+            ->once()
+            ->andThrow(new RuntimeException('grant failed'));
+        $mock->shouldIgnoreMissing();
+    });
+
+    try {
+        event(new WebhookReceived(subscriptionCreatedPayload()));
+    } catch (Throwable) {
+        // 冪等マシンの failed 記録経路。marker の原子性が本テストの関心
+    }
+
+    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
+});
+
+test('sub id が解決できない customer.subscription.created は付与しない (fail-closed)', function (): void {
+    $organization = activationGrantCustomer();
+
+    $payload = subscriptionCreatedPayload('evt_sub_nosubid');
+    unset($payload['data']['object']['id']);
+
+    event(new WebhookReceived($payload));
+
+    expect(activationSignupEntries($organization))->toHaveCount(0);
+    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
+});
+
+test('invoice.paid では signup grant が走らない (D29 の回帰)', function (): void {
+    $organization = activationGrantCustomer();
+
+    event(new WebhookReceived([
+        'id' => 'evt_invoice_paid_no_signup',
+        'type' => 'invoice.paid',
+        'data' => [
+            'object' => [
+                'id' => 'in_no_signup',
+                'customer' => 'cus_activation_grant',
+                'billing_reason' => 'subscription_create',
+            ],
+        ],
+    ]));
+
+    expect(activationSignupEntries($organization))->toHaveCount(0);
+    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
+});
+
+test('移行期に旧鍵で付与済みの org を activate しても再付与されない', function (): void {
+    $organization = activationGrantCustomer();
+    $owner = $organization->users()->firstOrFail();
+
+    // P1〜P6 の移行期に登録された org 相当 (旧鍵 signup_grant:org:{id} + marker 済み)
+    app(TicketLedgerService::class)->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
+    $organization->forceFill(['signup_tickets_granted_at' => CarbonImmutable::now()])->save();
+    $balanceBefore = activationBalance($organization);
+
+    $result = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);
+
+    expect($result->granted)->toBeFalse();
+    expect(activationSignupEntries($organization))->toHaveCount(1);
+    expect(activationBalance($organization))->toBe($balanceBefore);
+});
diff --git a/tests/Feature/Billing/SignupGrantOncePerOrgTest.php b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
index b820a66..7a71198 100644
--- a/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
+++ b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
@@ -6,7 +6,6 @@
 use App\Models\User;
 use App\Services\Billing\PersonalPlanService;
 use App\Services\Billing\TicketLedgerService;
-use App\Services\Organization\OrganizationProvisioningService;
 use Carbon\CarbonImmutable;
 use Laravel\Cashier\Events\WebhookReceived;
 
@@ -20,13 +19,15 @@
 | (organization_id WHERE idempotency_key LIKE 'signup_grant:%') が経路・キー種別を跨いで
 | org 生涯 1 行に閉じる。
 |
-| **移行期規約 (P6 まで)**: 付与契機は登録時 (CreateNewUser) のまま維持し、同一 tx で
-| マーカーを先取する。free 有効化 (PersonalPlanService::activate) は先取できたときのみ付与する。
+| **P6 以降の付与契機**: free = PersonalPlanService::activate()、
+| paid = customer.subscription.created (SubscriptionService::grantSignupInitialTickets)。
+| 登録 (CreateNewUser) と invoice.paid は付与にも marker にも関与しない。
 */
 
 function grantOnceCustomer(string $stripeId = 'cus_grant_once'): Organization
 {
-    [$organization] = createOrganizationWithOwner();
+    // 未契約 (無料枠の自己申告もまだ) の組織 = activate() の対象になれる状態
+    [$organization] = createOrganizationWithOwner('テスト組織', grandfatherFreePlan: false);
     // stripe_id は Cashier customer column (状態キー)。テストでは明示代入する
     $organization->stripe_id = $stripeId;
     $organization->save();
@@ -35,21 +36,24 @@ function grantOnceCustomer(string $stripeId = 'cus_grant_once'): Organization
 }
 
 /**
- * 初回契約の invoice.paid (billing_reason=subscription_create)。
- * signup grant は plan 解決より前に走るため lines は不要 (月次付与は plan なしで no-op)。
+ * paid サブスク成立 (customer.subscription.created)。
+ * signup grant に必要なのは data.object.id (sub id) と data.object.customer のみ。
  *
  * @return array<string, mixed>
  */
-function grantOnceInvoicePaidPayload(string $eventId = 'evt_grant_once', string $stripeId = 'cus_grant_once'): array
-{
+function grantOnceSubscriptionCreatedPayload(
+    string $eventId = 'evt_grant_once',
+    string $stripeId = 'cus_grant_once',
+    string $stripeSubId = 'sub_grant_once',
+): array {
     return [
         'id' => $eventId,
-        'type' => 'invoice.paid',
+        'type' => 'customer.subscription.created',
         'data' => [
             'object' => [
-                'id' => 'in_grant_once',
+                'id' => $stripeSubId,
                 'customer' => $stripeId,
-                'billing_reason' => 'subscription_create',
+                'status' => 'active',
             ],
         ],
     ];
@@ -62,7 +66,7 @@ function grantOnceSignupEntryCount(Organization $organization): int
         ->count();
 }
 
-test('移行期: 登録時に付与され、同一 tx でマーカーも立つ', function (): void {
+test('登録では付与もマーカーも起きない (付与契機はプラン有効化時)', function (): void {
     $this->post('/register', [
         'name' => '山田 太郎',
         'email' => 'grant-once@example.com',
@@ -73,17 +77,12 @@ function grantOnceSignupEntryCount(Organization $organization): int
     $user = User::whereBlind('email', 'email_index', 'grant-once@example.com')->firstOrFail();
     $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
 
-    // 付与契機・枚数は不変 (現行挙動)
-    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())
-        ->toBe(config()->integer('billing.signup_grant_tickets'));
-    expect($organization->ticketLedgerEntries()->firstOrFail()->idempotency_key)
-        ->toBe("signup_grant:org:{$organization->id}");
-
-    // 移行期に追加される唯一の効果: マーカーが同時に立つ
-    expect($organization->signup_tickets_granted_at)->not->toBeNull();
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
+    expect(grantOnceSignupEntryCount($organization))->toBe(0);
+    expect($organization->signup_tickets_granted_at)->toBeNull();
 });
 
-test('移行期: 登録済み (マーカー済み) の組織を activate しても再付与されない', function (): void {
+test('登録後に Personal を有効化すると 1 回だけ付与される (再 activate は付与しない)', function (): void {
     $this->post('/register', [
         'name' => '鈴木 花子',
         'email' => 'grant-once-2@example.com',
@@ -93,25 +92,35 @@ function grantOnceSignupEntryCount(Organization $organization): int
 
     $user = User::whereBlind('email', 'email_index', 'grant-once-2@example.com')->firstOrFail();
     $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
-    $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();
 
-    $result = app(PersonalPlanService::class)->activate($organization, $user);
+    $first = app(PersonalPlanService::class)->activate($organization, $user);
+    expect($first->granted)->toBeTrue();
+    expect($organization->ticketLedgerEntries()->firstOrFail()->idempotency_key)
+        ->toBe("signup_grant:personal:{$organization->id}");
+    $balanceAfterFirst = app(TicketLedgerService::class)->balance($organization)->totalAvailable();
+    expect($balanceAfterFirst)->toBe(config()->integer('billing.signup_grant_tickets'));
 
-    expect($result->granted)->toBeFalse();
-    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceBefore);
+    $second = app(PersonalPlanService::class)->activate($organization->refresh(), $user);
+
+    expect($second->granted)->toBeFalse();
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceAfterFirst);
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
 });
 
-test('マーカー済み組織への直接 claim は先取できない (条件付き UPDATE の 0 件)', function (): void {
-    $owner = User::factory()->create();
-    $organization = app(OrganizationProvisioningService::class)->provision($owner, 'マーカー済み組織');
+test('マーカー済み組織は activate でも先取できない (条件付き UPDATE の 0 件)', function (): void {
+    $organization = grantOnceCustomer('cus_marked');
+    $owner = $organization->users()->firstOrFail();
+
+    // マーカーだけを先に立てた状態 (= 既に付与契機が走った org 相当)
+    $organization->forceFill(['signup_tickets_granted_at' => CarbonImmutable::now()])->save();
 
-    expect(app(PersonalPlanService::class)->claimSignupGrantMarker($organization))->toBeTrue();
-    // 2 回目は既にマーカーが立っているため先取できない (= 付与しない)
-    expect(app(PersonalPlanService::class)->claimSignupGrantMarker($organization))->toBeFalse();
+    $result = app(PersonalPlanService::class)->activate($organization->refresh(), $owner);
+
+    expect($result->granted)->toBeFalse();
+    expect(grantOnceSignupEntryCount($organization))->toBe(0);
 });
 
-test('free 有効化済みの組織に paid webhook (subscription_create) が来ても二重付与しない', function (): void {
+test('free 有効化済みの組織に paid webhook (subscription.created) が来ても二重付与しない', function (): void {
     $organization = grantOnceCustomer();
     $owner = $organization->users()->firstOrFail();
 
@@ -119,9 +128,9 @@ function grantOnceSignupEntryCount(Organization $organization): int
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
     $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();
 
-    event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+    event(new WebhookReceived(grantOnceSubscriptionCreatedPayload()));
 
-    // 部分 UNIQUE index が経路 (signup_grant:personal:% ↔ signup_grant:org:%) を跨いで弾く
+    // marker が主・部分 UNIQUE index (signup_grant:personal:% ↔ signup_grant:sub_%) が保険
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceBefore);
 });
@@ -130,12 +139,12 @@ function grantOnceSignupEntryCount(Organization $organization): int
     $organization = grantOnceCustomer();
     $owner = $organization->users()->firstOrFail();
 
-    event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+    event(new WebhookReceived(grantOnceSubscriptionCreatedPayload()));
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
     $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();
 
-    // paid webhook 経路も移行期規約 (marker 先取できたときのみ付与) に従うため、webhook 時点で
-    // マーカーが立つ。よって後続の activate はマーカーを先取できず granted=false になる
+    // paid webhook 経路も同型の claim パターン (marker 先取できたときのみ付与) に従うため、
+    // webhook 時点でマーカーが立つ。よって後続の activate は先取できず granted=false になる
     // (= 真実源であるマーカーと付与実績が一致する)。
     expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();
 
@@ -152,7 +161,7 @@ function grantOnceSignupEntryCount(Organization $organization): int
     expect($organization->signup_tickets_granted_at)->toBeNull();
     expect(grantOnceSignupEntryCount($organization))->toBe(0);
 
-    event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+    event(new WebhookReceived(grantOnceSubscriptionCreatedPayload()));
 
     // 付与が起きたなら、その事実がマーカーにも反映されていること (marker = 付与の唯一の真実源)
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
@@ -174,7 +183,7 @@ function grantOnceSignupEntryCount(Organization $organization): int
 
     // webhook 処理は例外を握って failed 記録する契約のため、ここでは例外の有無を問わない
     try {
-        event(new WebhookReceived(grantOnceInvoicePaidPayload()));
+        event(new WebhookReceived(grantOnceSubscriptionCreatedPayload()));
     } catch (Throwable) {
         // 冪等マシンの failed 記録経路。marker の原子性が本テストの関心
     }
@@ -188,7 +197,8 @@ function grantOnceSignupEntryCount(Organization $organization): int
     $granted = grantOnceCustomer('cus_backfill_granted');
     $notGranted = Organization::factory()->create();
 
-    // 既存の付与履歴を作る (サービス経由。台帳は append-only)
+    // 既存の付与履歴を作る (サービス経由。台帳は append-only)。
+    // 旧鍵 (signup_grant:org:{id}) = P6 以前に登録経路で付与された移行期データ相当。
     $grantedAt = CarbonImmutable::parse('2026-05-01 09:00:00');
     $this->travelTo($grantedAt);
     app(TicketLedgerService::class)->grantSignupGrant($granted, "signup_grant:org:{$granted->id}");
diff --git a/tests/Feature/Billing/TicketGrantTest.php b/tests/Feature/Billing/TicketGrantTest.php
index 746bf5f..dccb5b2 100644
--- a/tests/Feature/Billing/TicketGrantTest.php
+++ b/tests/Feature/Billing/TicketGrantTest.php
@@ -79,20 +79,20 @@ function grantService(): TicketLedgerService
     expect(grantService()->balance($organization)->totalAvailable())->toBe(7);
 });
 
-test('grantSignupGrant は config の枚数・期限で org スコープキーで冪等付与する', function (): void {
+test('grantSignupGrant は config の枚数・期限で free 有効化キーで冪等付与する', function (): void {
     [$organization] = createOrganizationWithOwner();
     config()->set('billing.signup_grant_tickets', 10);
     config()->set('billing.signup_grant_expiry_days', 30);
 
-    // 冪等キーは呼び出し側が渡す (org スコープ = signup_grant:org:{id})。二重呼び出しでも 1 行のみ。
-    grantService()->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
-    grantService()->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
+    // 冪等キーは呼び出し側が渡す (free 有効化 = signup_grant:personal:{id})。二重呼び出しでも 1 行のみ。
+    grantService()->grantSignupGrant($organization, "signup_grant:personal:{$organization->id}");
+    grantService()->grantSignupGrant($organization, "signup_grant:personal:{$organization->id}");
 
     expect(grantService()->balance($organization)->totalAvailable())->toBe(10);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
     $entry = $organization->ticketLedgerEntries()->firstOrFail();
     expect($entry->source)->toBe(TicketSource::Monthly);
-    expect($entry->idempotency_key)->toBe("signup_grant:org:{$organization->id}");
+    expect($entry->idempotency_key)->toBe("signup_grant:personal:{$organization->id}");
     expect($entry->expires_at?->toDateString())
         ->toBe(CarbonImmutable::now()->addDays(30)->toDateString());
 
@@ -105,7 +105,16 @@ function grantService(): TicketLedgerService
     [$organization] = createOrganizationWithOwner();
     config()->set('billing.signup_grant_tickets', 0);
 
-    expect(fn () => grantService()->grantSignupGrant($organization, "signup_grant:org:{$organization->id}"))
+    expect(fn () => grantService()->grantSignupGrant($organization, "signup_grant:personal:{$organization->id}"))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('grantSignupGrant は signup_grant: 接頭辞のないキーを拒否する', function (): void {
+    [$organization] = createOrganizationWithOwner();
+
+    // 接頭辞は部分 UNIQUE index の述語 (LIKE 'signup_grant:%') と対応する契約。
+    // 外れたキーで付与すると「org 生涯 1 回」の DB 保証をすり抜けるため停止する。
+    expect(fn () => grantService()->grantSignupGrant($organization, "monthly:{$organization->id}"))
         ->toThrow(InvalidArgumentException::class);
 });
 
@@ -113,8 +122,13 @@ function grantService(): TicketLedgerService
     [$organization] = createOrganizationWithOwner();
     $svc = grantService();
 
-    // 1 回目: 公開ユースケース経由 (org スコープキー signup_grant:org:{id})
-    $svc->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
+    // 1 回目: free 有効化経路のキー (signup_grant:personal:{id})
+    $svc->grantSignupGrant($organization, "signup_grant:personal:{$organization->id}");
+
+    // paid 経路のキー (signup_grant:{stripeSubId}) でも部分 UNIQUE index が弾く
+    $svc->grantSignupGrant($organization, 'signup_grant:sub_x');
+    expect($organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(1);
 
     // 2 回目: 旧キー形式を直接投入 → 部分 UNIQUE index (organization_id WHERE idempotency_key
     // LIKE 'signup_grant:%') が別キーでも弾く (ON CONFLICT DO NOTHING)。
diff --git a/tests/Feature/Billing/WebhookIdempotencyTest.php b/tests/Feature/Billing/WebhookIdempotencyTest.php
index b42b2a4..c522fef 100644
--- a/tests/Feature/Billing/WebhookIdempotencyTest.php
+++ b/tests/Feature/Billing/WebhookIdempotencyTest.php
@@ -140,50 +140,53 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     expect(StripeWebhookEvent::query()->count())->toBe(2);
 });
 
-test('billing_reason=subscription_create の invoice.paid は月次付与に加えて signup grant を冪等付与する', function (): void {
+test('customer.subscription.created は signup grant を冪等付与する (P6/D29 の契機)', function (): void {
     $organization = billingStripeCustomer();
-    enableStandardMonthlyGrant();
 
-    $payload = invoicePaidPayload('evt_signup_1');
-    $payload['data']['object']['billing_reason'] = 'subscription_create';
+    $payload = subscriptionPayload('customer.subscription.created', 'active', 'evt_signup_1');
 
     event(new WebhookReceived($payload));
 
-    // 月次 100 + signup grant (config billing.signup_grant_tickets = 10)
-    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(110);
-    // 冪等キーは org スコープ (呼び出し側が渡す)。subscription id には依存しない。
+    // signup grant (config billing.signup_grant_tickets = 10) のみ。月次付与は invoice.paid の責務。
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())
+        ->toBe(config()->integer('billing.signup_grant_tickets'));
+    // 冪等キーは stripe subscription id 由来 (aigenba verbatim)
     $signup = $organization->ticketLedgerEntries()
-        ->where('idempotency_key', "signup_grant:org:{$organization->id}")
+        ->where('idempotency_key', 'signup_grant:sub_test_1')
         ->firstOrFail();
     expect($signup->delta)->toBe(config('billing.signup_grant_tickets'));
     expect($signup->expires_at)->not->toBeNull();
+    // marker が付与の真実源として立つ
+    expect($organization->refresh()->signup_tickets_granted_at)->not->toBeNull();
 
-    // 別 event_id での再通知でも signup grant は 1 回だけ (idempotency_key 冪等)
+    // 別 event_id での再通知でも signup grant は 1 回だけ (marker + idempotency_key 冪等)
     $retry = $payload;
     $retry['id'] = 'evt_signup_2';
     event(new WebhookReceived($retry));
 
-    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(110);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())
+        ->toBe(config()->integer('billing.signup_grant_tickets'));
+    expect($organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(1);
 });
 
-test('subscription id が無くても org スコープキーで signup grant を付与する', function (): void {
+test('billing_reason=subscription_create の invoice.paid は月次付与のみで signup grant を走らせない (D29)', function (): void {
     $organization = billingStripeCustomer();
     enableStandardMonthlyGrant();
 
-    // subscription id を含まない subscription_create の invoice.paid。
-    // org スコープキー (signup_grant:org:{id}) は subscription id に依存しないため付与される。
-    $payload = invoicePaidPayload('evt_signup_nosub');
+    $payload = invoicePaidPayload('evt_signup_via_invoice');
     $payload['data']['object']['billing_reason'] = 'subscription_create';
 
     event(new WebhookReceived($payload));
 
-    // 月次 100 + signup grant 10
-    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(110);
+    // 月次 100 のみ (signup grant は customer.subscription.created の責務)
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
     expect(
         $organization->ticketLedgerEntries()
             ->where('idempotency_key', 'like', 'signup_grant:%')
             ->count(),
-    )->toBe(1);
+    )->toBe(0);
+    expect($organization->refresh()->signup_tickets_granted_at)->toBeNull();
 });
 
 test('seed 既定 (D28: monthly_ticket_grant=0) では invoice.paid で月次付与行が作られない', function (): void {
@@ -197,10 +200,9 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
     expect($organization->ticketLedgerEntries()
         ->where('idempotency_key', 'like', 'monthly:%')->count())->toBe(0);
-    // signup grant のみが計上される (残高は config の付与枚数と一致)
-    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())
-        ->toBe(config('billing.signup_grant_tickets'));
-    expect($organization->ticketLedgerEntries()->count())->toBe(1);
+    // signup grant も走らない (D29) ため台帳行は 1 行も作られない
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
+    expect($organization->ticketLedgerEntries()->count())->toBe(0);
 });
 
 test('customer.subscription.updated で organizations.plan_code が同期される', function (): void {
diff --git a/tests/Feature/Organization/InvitationTest.php b/tests/Feature/Organization/InvitationTest.php
index c753ee5..120882a 100644
--- a/tests/Feature/Organization/InvitationTest.php
+++ b/tests/Feature/Organization/InvitationTest.php
@@ -383,7 +383,8 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     $user = User::whereBlind('email', 'email_index', 'nofree@example.com')->firstOrFail();
     // 個人組織は生成されない
     expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();
-    // 招待組織の残高に signup grant は乗らない (owner の付与ぶんも招待組織には走っていない)
+    // 招待組織の残高に signup grant は乗らない
+    // (P6/F2: 付与契機はプラン有効化時であり、登録では誰にも付与されない)
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     expect(
         $organization->ticketLedgerEntries()
diff --git a/tests/js/pages/Pricing.test.ts b/tests/js/pages/Pricing.test.ts
index 0bb2308..9f3c0dd 100644
--- a/tests/js/pages/Pricing.test.ts
+++ b/tests/js/pages/Pricing.test.ts
@@ -75,10 +75,11 @@ describe("Pricing", () => {
         expect(table).toHaveTextContent("500 枚以上");
         expect(table).toHaveTextContent("¥50 ／ 枚");
 
-        // 招待経由 (所属組織の残高を共有) は LP CTA の対象外。付与は個人組織を作る
-        // 「新規登録」時に走るため、文言も「新規登録で」で挙動と整合させる。
+        // P6/F2: 付与契機はプラン有効化時 (free = Personal 有効化 / paid = サブスク成立) で
+        // org 生涯 1 回。登録しただけでは付与されないため、文言も「プランを有効化すると、
+        // 初回 1 回だけ」で挙動と整合させる。
         expect(screen.getByTestId("signup-grant-note")).toHaveTextContent(
-            "新規登録でチケット 10 枚が無料でついてきます (付与から 30 日間有効)",
+            "プランを有効化すると、初回 1 回だけチケット 10 枚が無料でついてきます (付与から 30 日間有効)",
         );
     });
 
@@ -91,7 +92,7 @@ describe("Pricing", () => {
         await fireEvent.click(question);
         expect(question).toHaveAttribute("aria-expanded", "true");
         expect(
-            screen.getByText(/Free プランは基本料金なしでご利用いただけます/),
+            screen.getByText(/Personal プランは基本料金なしでご利用いただけます/),
         ).toBeInTheDocument();
 
         await fireEvent.click(question);
diff --git a/tests/js/pages/Welcome.test.ts b/tests/js/pages/Welcome.test.ts
index 2a451ca..8cffe8f 100644
--- a/tests/js/pages/Welcome.test.ts
+++ b/tests/js/pages/Welcome.test.ts
@@ -41,7 +41,7 @@ describe("Welcome (LP)", () => {
         expect(screen.getByRole("heading", { name: "自動で動画に合成" })).toBeInTheDocument();
 
         expect(screen.getByTestId("landing-pricing-cta")).toHaveTextContent(
-            "新規登録でチケット 10 枚が無料",
+            "初回 1 回だけチケット 10 枚が無料",
         );
         expect(screen.getByRole("link", { name: /料金プランを見る/ })).toBeInTheDocument();
     });
```
