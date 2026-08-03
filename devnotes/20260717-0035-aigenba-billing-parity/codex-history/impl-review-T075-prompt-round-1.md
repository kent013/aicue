【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件(アプリ都合で緩めない)】
1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404
3. cross-org 不可: 組織を跨ぐ read/write をしない
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に `laratrust_team_id` を明示(strict_check=true)
6. PII(email/name)は CipherSweet。検索は `whereBlind()`
7. 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富な Laravel アーキテクトです。**実装レビュー**を行ってください。

対象は決済 parity の P4 (ゲート反転 + grandfathering 移行)。**設計書どおりに実装されているか**、
禁止事項・セキュリティ不変条件に抵触していないか、PHPStan level 10 適合か、
テストが不変条件を実際に固定しているか (空振りしていないか)、副作用・後退リスクが無いかを見てください。

特に重点的に見てほしい点:
1. **締め出しゼロ**: backfill が「P4 直前に許可されていた org」を漏れなく grandfather するか。
   設計は SQL 述語を書かず PHP で deriveEntitlement を評価する方式を要求している (D22 の集合同値)。
2. **plan_code 非 null の結論が P4 で変わっていないか** (DoD (3))。
3. backfill 失敗時にゲートが反転しない順序になっているか。
4. テスト fixture の変更 (grandfatherFreePlan: false) が、検証したい不変条件を弱めていないか。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 指摘は [Critical] [Warning] [Suggestion] で分類し、Critical/Warning には修正案を添える
- 日本語で出力

---

## 設計書 §P4 (抜粋)

### P4 ゲート反転 + grandfathering 移行（free 撤去を含む。山場）

前提: P1（`organizations` の `free_plan_code` / `free_plan_activated_at` / `personal_declared_at` / `personal_declared_by_user_id` / `signup_tickets_granted_at` + partial unique index、`PersonalPlanService::activate()` 完成、`PlanCode` 5 case、`plans.is_active`（**personal / starter とも `is_active=true` で seed**）、`config/quota.php` の `personal` / `starter` limits、**D28 = 全 tier `monthly_ticket_grant=0`**）/ P2（**`OnboardingBillingState`（5 状態）と `BillingAccess::state()` の aigenba verbatim 移植** + `BillingCheckoutSession` テーブル + `SubscriptionState` + `SubscriptionService::deriveEntitlement()` + `subscriptions.has_payment_method`（既存行 `true` に backfill 済み））/ P3（`onboarding.{checkout,activate-personal,billing-required}` 導線）がマージ済み。

P4 の内容は 4 点に閉じる（新機能を足さない）:

1. **`BillingAccess::hasActiveAccess()` の移行 OR 1 行（`return $org->plan_code === null;`）を削除**し、**P2 で移植した `state()->grantsAccess()` 一本にする**（= aigenba verbatim の本体になる）。
2. `RequireActiveSubscription` の遮断分岐を aigenba verbatim（`/tmp/aigenba/app/Http/Middleware/RequireActiveSubscription.php:60-91`）へ。JSON/XHR の 402 は維持（D15）。
3. **declarer-less grandfathering backfill**（`free_plan_code='personal'` + declarer NULL → `ActiveFreePlan`）。
4. **free 撤去（D11）**一式（data migration + 残余 0 件検証。rollback は運用手順）。

#### P4 で新たに変わるのは何か（Round 13 Critical #1 の反映。切り分けの確定）

移行 OR（`$org->plan_code === null`）は **`plan_code IS NULL` の org にしか適用されない**。よって OR を消して結論が変わりうるのは **`plan_code IS NULL` の org だけ**であり、**`plan_code` 非 null の org の結論は P2 で確定済みで P4 は 1 ビットも変えない**。

**P2 で既に起きている結論変更（P4 の成果として主張しない。帰属は P2）**

| 入力 | 現行（P2 前。`stripe_status ∈ {active,trialing}` のみを見る `GRANTING_STATUSES`。`BillingAccess.php:36,49-50`） | P2 以降（`deriveEntitlement`） | 帰属 |
|---|---|---|---|
| `plan_code` 非 null + `active`/`trialing` + **trial 終了 + PM 無** | **許可**（status しか見ないため） | **遮断**（`TrialEndedWithoutPaymentMethod`） | **P2**（Round 13 Critical #1。`deriveEntitlement` 導入の時点で起きる） |
| `plan_code` 非 null + **`past_due` + PM 有** | **遮断** | **許可**（`PastDue::grantsAccess()=true` = dunning 継続） | **P2** |

→ 旧稿の「**P4 分類 2 が反転の目的**」という説明は**成立しない**。当該変更は P2 の `deriveEntitlement` 移植で既に確定しており、P4 の OR 削除とは無関係（OR は `plan_code` 非 null org に一度も適用されないため）。本節以降の分類表・DoD・テストはすべてこの訂正後の事実に整列させる。

**P4 の正味の結論変更**

- OR 削除で結論が変わる集合は **`{ plan_code IS NULL ∧ ¬state()->grantsAccess() }`**（= 分類 7-10）に**厳密に閉じる**。
- **backfill がこの集合を P4 のゲートコード活性化より前に空にする**（全行を `ActiveFreePlan` にする）。
- ⇒ **P4 デプロイ時点で結論が変わる既存 org は 0 件**（締め出しゼロ）。**P4 の正味の変更は「backfill 完了後に新規発生する未契約 org（プランを選ばず Personal も申告していない org）が遮断されるようになること」**である。これが「ゲート反転」の実体であり、既存 org の結論変更ではない。

**DoD**: (1) `列/index（P1 済）→ backfill 完了・残余 0 件検証 → ゲートコード deploy` の順序を守り、**backfill が失敗したらゲートを反転しない**（migration の throw でデプロイが中断し、旧リリースが生き続ける）。(2) **`hasActiveAccess()` の結論は、backfill 適用後の全既存 org について P2 と完全同値**（P4 は新規未契約 org のゲートのみを変える）。(3) **`plan_code` 非 null の org について P4 は結論を 1 件も変えない**（`RequireActiveSubscriptionMiddlewareTest` の当該ケースを P4 で 1 つも書き換えないことで機械的に示す）。(4) **D22**（backfill 対象 ID 集合 == 分類表 grandfather 対象 ID 集合の双方向完全一致）を migration テストで機械検証する。

#### 変更箇所

| ファイル（AI-CUE） | 変更 | 移植元（aigenba） |
|---|---|---|
| `/workspace/app/Services/Billing/BillingAccess.php` | **P2 が置いた移行 OR（`return $org->plan_code === null;`）を削除**し、`hasActiveAccess()` を `return $this->state($org)->grantsAccess();` だけにする（`state()` は無改変）。クラス docblock の「plan_code null = fallback free プラン = 支払い不要 tier として許可（`BillingAccess.php:19-23`。devnotes/20260712-0927-bugfix-billing-free-access）」節を**反転記録**（無料枠は `free_plan_code='personal'` で表現し `plan_code` は判定に一切使わない / 旧 devnote は歴史として保持）へ差し替える | `/tmp/aigenba/app/Services/Billing/BillingAccess.php:26-30` |
| `/workspace/app/Http/Middleware/RequireActiveSubscription.php` | 遮断分岐を verbatim 化。`state($org)->grantsAccess()` で通過、不許可なら `manageBilling` 保持者を `onboarding.checkout`、非保持者を `onboarding.billing-required` へ redirect。`billing.index` + `error` flash の誘導（現行 L83）を廃止。**JSON/XHR は 402 を維持**（D15）。`resolveOrganization()`（route binding 優先 → `currentOrganization` → null 素通し。現行 L89-108）・非メンバー 404 defense-in-depth（現行 L95-102）・`session()->reflash()`（現行 L81）は**現行のまま維持**（aigenba も L89 で reflash）。docblock を反転後へ | `/tmp/aigenba/app/Http/Middleware/RequireActiveSubscription.php:60-91`。**`OnboardingReturnResolver` の destination 記憶（L74-81）は移植しない = P7** |
| `/workspace/database/migrations/2026_07_17_000300_backfill_grandfathered_free_plan_code.php`（新規 data migration） | **entitlement を PHP で評価して対象 ID を確定 → その ID 集合で UPDATE**（後述）。`free_plan_code='personal'` / `free_plan_activated_at=now()` を書き、`personal_declared_by_user_id` / `personal_declared_at` は **NULL のまま**。**grant を発火しない**（`signup_tickets_granted_at` に触れない）。末尾で残余 0 件検証、違反で `RuntimeException`。`down()` は意図的 no-op | 構造は `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php`（列追加と分離した data migration + 冪等ガード + `down()` no-op）。grandfather backfill 自体は **AI-CUE 固有の移行**（aigenba はゲート有りでスタート） |
| `/workspace/database/migrations/2026_07_17_000400_remove_free_plan_row.php`（新規 data migration。D11） | `PlanSeeder` は `updateOrCreate` のため既存 DB の `free` 行が消えない。(1) `organizations.plan_code='free'` の参照行 / `free` の `plan_prices` を**事前検証し残存すれば fail-closed（throw）**、(2) `plans` から `code='free'` を削除、(3) **残余 0 件検証**。`down()` は `free` 行（`name='Free'` / `monthly_ticket_grant=0`（D28 後の値）/ `sort_order=0` / `is_active=true`）を復元（config には触らない） | —（AI-CUE 固有） |
| `/workspace/database/seeders/PlanSeeder.php:42-50` | `['code' => 'free']` の `updateOrCreate`（`name='Free'` / `monthly_ticket_grant`（D28 後 0）/ `sort_order=0`）を削除（後継は P1 で seed 済みの `personal`）。docblock L21-27 の「free プランは Stripe Price を持たない = 未契約の既定 = BillingAccess の entitlement 判定の前提」を「free entitlement は `organizations.free_plan_code='personal'` で表現する。`plan_code` は entitlement 判定に使わない」へ | `/tmp/aigenba/database/seeders/PlanSeeder.php`（Personal / Starter / Standard / Business / Enterprise のみ） |
| `/workspace/config/quota.php:27,34` | `fallback_plan` を `'free'` → **`'personal'`**（P1 追加済みの `personal` limits は旧 `free` と同値 = `max_projects=1` / `max_members=3` / `max_storage_bytes=1GiB` = **実効 limits 不変**）。`plans` から `'free'` キーを削除 | — |
| `/workspace/app/Services/Billing/QuotaService.php` | **コード変更なし**。docblock の「plan_code null は fallback_plan（free）」を `personal` 表記へ | — |
| `/workspace/app/Models/Organization.php:109` / `/workspace/app/Services/Billing/StripeWebhookProcessor.php:49` | docblock の「plan_code null = 未契約 = 支払い不要の free tier」を反転後の事実（`plan_code` は quota 解決キーのみ。利用可否は `BillingAccess::state()` が決める）へ | `/tmp/aigenba/app/Models/Organization.php` |
| `/workspace/database/seeders/ManualTestSeeder.php` | Personal プラン組織を `PersonalPlanService::activate($org, $owner)` 経由で有効化（`plan_code` null のまま / declarer = owner / marker + grant は activate 内で 1 回）。手動テスト環境が反転後に締め出されないため | —（AI-CUE 固有 fixture） |
| `/workspace/database/seeders/BughuntBillingSeeder.php` | 「課金なしで通る組織」を **declarer-less grandfathered 相当**（`free_plan_code='personal'` / declarer NULL）で作る（backfill 後の本番状態と同型の fixture にする） | — |
| `/workspace/database/factories/OrganizationFactory.php` | **変更なし**（`activatedPersonal(User $declarer)` / `grandfatheredFree()` は P1/P2 で追加済み） | `/tmp/aigenba/database/factories/OrganizationFactory.php` |
| `/workspace/tests/Pest.php:118` | `createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true)` に拡張。既定で **backfill 相当**（`free_plan_code='personal'` / `free_plan_activated_at` / declarer NULL）を付与。**`activate()` は呼ばない**（呼ぶと signup grant が発火して残高期待が壊れ、declarer partial unique index にも触れる）。ゲート / onboarding テストは `grandfatherFreePlan: false` で真の未契約 org を作る。docblock を反転後へ | — |
| `/workspace/routes/web.php:302-317,343-349` | コメントのみ更新（「free（未契約 = plan_code null）組織は遮断されない」→「未契約組織は onboarding へ遮断される。無料枠は `free_plan_code='personal'`。billing / purchase-tickets / notifications / onboarding は gate group 外の構造的 allowlist」）。**route 定義の変更なし** | `/tmp/aigenba/routes/web.php` |
| `/workspace/docs/architecture.md:85` / `/workspace/docs/app-integration-guide.md:129-139,190` / `/workspace/docs/template-divergence.md §D9` | 課金ゲート方針を反転後へ。**D9（free tier は課金ゲートを通す）は「解消（本設計で反転。無料枠は `free_plan_code='personal'` の明示申告へ移行）」として記録更新**（削除しない） | — |

**P4 に含めない（フェーズ境界）**: `OnboardingReturnResolver` / `IntendedPlanResolver`（P7）/ **月次付与の廃止（D28 = P1 所管）** / **`deriveEntitlement` の意味論と、それに伴う `past_due`・`trial 終了 + PM 無` の結論変更（P2 所管）** / サブスク checkout の `subscriptionAttemptToken`・着地 feedback・請求先 PII（P9）/ `PlanCode` の case・`plans.is_active`・`state()` 本体（P1・P2 で確定済み）。

#### 波及変更

- **TypeScript 型定義**: なし（`OnboardingBillingState` は middleware / Service 内部の判定にのみ使い、Inertia props に載せない = aigenba と同じ）。
- **DTO / JsonResource**: なし（`state()` / `grantsAccess()` / `SubscriptionEntitlementDto` は P2 のまま。**値**が変わるのは `hasActiveAccess()` の移行 OR 経路だけ）。
- **Inertia props**: なし。遮断理由の提示は P3 の `Onboarding/Checkout` / `Onboarding/BillingRequired` の props に依存（middleware は `->with('error', ...)` を渡さない = aigenba 方式「理由は着地ページが持つ」）。
- **テストファイル（更新。削除しない）**: `RequireActiveSubscriptionMiddlewareTest`（**`plan_code IS NULL` 系のみ更新。`plan_code` 非 null 系は P2 で更新済みで P4 では触らない**）/ `SeededFreePlanBillingAccessTest`（**削除しない。期待更新**）/ `BillingAccessStateTest`（P2 成果物。`hasActiveAccess()` の期待のみ反転）/ `QuotaTest.php:15` / `BillingPageTest.php:26` / `PricingPageTest.php:35` / `PlanSeederPriceInvariantTest.php:23` / `BughuntBillingSeederTest.php:68` / `ManualTestSeederTest.php:30` / `tests/js/pages/Pricing.test.ts` / `tests/Pest.php`（詳細は「テスト計画」）。**`tests/Unit/Billing/OnboardingBillingStateTest.php`（enum マトリクス）は無改変**（`hasActiveAccess()` を持たないため）。
- `Organization::factory()` 直呼びで gate 対象 route を叩くファイルの棚卸し: `OrganizationSwitchTest` / `ApiKeyGuardTest` / `DefaultTeamInvariantTest` / `BillingNotificationDispatchTest` / `SendBillingRemindersTest` / `UserResourceTest`。業務 route を叩くものだけ `grandfatheredFree()` state を付与する。

#### 主要な契約

**判定（P2 成果物 = aigenba verbatim。P4 は移行 OR を外すだけ）**

```php
// App\Services\Billing\BillingAccess — P4 の判定変更はここだけ
public function hasActiveAccess(Organization $org): bool
{
    return $this->state($org)->grantsAccess();   // P4 = aigenba verbatim
    // P2 の移行 OR（`return $org->plan_code === null;`）を削除した。
    // OR は plan_code IS NULL の org にしか効かないため、本削除で結論が変わる集合は
    // { plan_code IS NULL ∧ ¬state()->grantsAccess() } に閉じる（= backfill が空にする集合）。
}

public function state(Organization $org): OnboardingBillingState;   // P2 で verbatim 移植済み・無改変
```

```text
state() の解決順（aigenba verbatim。plan_code を一切見ない）        grantsAccess
1. subscription('default') が deriveEntitlement()->entitled     Subscribed        true
2. free_plan_code === PersonalPlanService::FREE_PLAN_CODE       ActiveFreePlan    true
3. subscription('default') 行がある                              ExpiredCheckout   false
4. BillingCheckoutSession の live pending がある                  PendingCheckout   false
5. BillingCheckoutSession の stale pending / expired / failed     ExpiredCheckout   false
6. それ以外                                                       NoSubscription    false
```

**entitlement の定義（P2 の `SubscriptionService::deriveEntitlement()`。backfill はこの定義に厳密一致させる）**

```text
entitled(org) := ($sub = org->subscription('default')) instanceof Subscription
              && SubscriptionState::fromSubscription($sub)->grantsAccess()   // Active | UpgradeRecovery | PastDue
              && ! ($sub->trial_ends_at !== null && $sub->trial_ends_at <= now() && ! $sub->has_payment_method)
              && $sub->stripe_status !== 'paused'
```

| 入力 | `SubscriptionState` | `entitled` |
|---|---|---|
| `active` / `trialing`（trial 未終了 or PM 有） | `Active` / `UpgradeRecovery` | **true** |
| `active` / `trialing` + trial 終了 + **PM 無** | `Active` / `UpgradeRecovery` | **false**（`TrialEndedWithoutPaymentMethod`） |
| **`past_due` + PM 有**（or trial 未終了） | `PastDue`（`grantsAccess=true`） | **true**（dunning 中も利用継続） |
| `past_due` + trial 終了 + **PM 無** | `PastDue` | **false** |
| `paused` | `Paused` | **false** |
| `canceled` / `unpaid` / `incomplete` / `incomplete_expired` | `Inactive` | **false** |

**backfill の対象集合（PHP で entitlement を評価 → ID 集合で UPDATE）**

```text
grandfather := { org : org.plan_code IS NULL ∧ org.free_plan_code IS NULL ∧ ¬entitled(org) }
```

`free_plan_code IS NULL` のもとで `state(org)` が `Subscribed` を返す ⟺ `entitled(org)` なので、この集合は
**「P4 直前（P2 適用済み）に許可されていて、OR 削除後に `grantsAccess()=false` になる org」の全体と定義上一致**する。

**SQL 述語を書かない理由（2 点とも `deriveEntitlement` との集合不一致を生む）**

1. **`stripe_status IN ('active','trialing')` の除外は entitlement と双方向に不一致**。`past_due` + PM 有は `entitled=true`（= `Subscribed`）なのに除外されず **grandfather してしまう**（entitled subscription と free entitlement の併存 invariant 違反）。逆に `active` + trial 終了 + PM 無は `entitled=false`（= 遮断）なのに除外され **grandfather から漏れる**（P4 直前に使えていた org の締め出し）。
2. **`EXISTS(subscriptions ...)` は `subscription('default')` を再現できない**。Cashier の `subscriptions()` は `orderBy('created_at','desc')`、`subscription($type)` はその **先頭 1 行のみ**（`vendor/laravel/cashier/src/Concerns/ManagesSubscriptions.php:155,165`）。`type='default'` の行が複数ある org（paid→free→paid 経路）では「いずれかの行が entitled」（SQL の EXISTS）と「**最新行**が entitled」（`state()`）が食い違う。

→ よって backfill は **P2 の `deriveEntitlement()` と同一定義を唯一の判定経路として PHP で評価**し、確定した ID 集合で UPDATE する。述語の写し（= ドリフト源）を持たないため、**D22 の双方向 ID 集合一致が構成上（by construction）成立する**。

```php
public function up(): void
{
    $subscriptions = app(SubscriptionService::class);
    $now = CarbonImmutable::now();
    /** @var list<int> $targets */
    $targets = [];

    // 対象母集団は「plan_code IS NULL ∧ free_plan_code IS NULL」に閉じる（分類 1-6 / 12 を除外）。
    // subscriptions を eager load して subscription('default') の N+1 と order 差を避ける
    // （Cashier は $this->subscriptions コレクションの先頭を返す = created_at desc の最新行）。
    Organization::query()
        ->whereNull('plan_code')
        ->whereNull('free_plan_code')
        ->with('subscriptions')
        ->chunkById(500, function (Collection $orgs) use ($subscriptions, &$targets): void {
            foreach ($orgs as $org) {
                $sub = $org->subscription('default');
                $entitled = $sub instanceof Subscription
                    && $subscriptions->deriveEntitlement($sub)->entitled;   // ← state() の 1-2 行目と同一式
                if (! $entitled) {
                    $targets[] = $org->id;
                }
            }
        });

    foreach (array_chunk($targets, 500) as $ids) {
        DB::table('organizations')->whereIn('id', $ids)->update([
            'free_plan_code' => 'personal',        // migration はアプリ定数に依存しない（invariant テストで固定）
            'free_plan_activated_at' => $now,
            'updated_at' => $now,
        ]);
        // personal_declared_by_user_id / personal_declared_at は NULL のまま（partial unique index の対象外）
        // signup_tickets_granted_at には触れない（grant を発火しない）
    }

    // 残余 0 件検証: 反転後に遮断される未契約 org が 1 件も残っていないこと
    $remaining = /* 同じ母集団を同じ deriveEntitlement で再走査し ¬entitled を数える */;
    if ($remaining !== 0) {
        throw new RuntimeException("grandfather backfill incomplete: {$remaining} org(s) would lose access");
    }
}

public function down(): void {}   // 意図的 no-op（下記 rollback 手順）
```

**backfill 分類表（`deriveEntitlement` 準拠。Round 13 Critical #1 反映済み）**

## 実装差分 (git diff main...HEAD)
```diff
diff --git a/app/Http/Middleware/RequireActiveSubscription.php b/app/Http/Middleware/RequireActiveSubscription.php
index a897023..2c3c6d0 100644
--- a/app/Http/Middleware/RequireActiveSubscription.php
+++ b/app/Http/Middleware/RequireActiveSubscription.php
@@ -4,6 +4,7 @@
 
 namespace App\Http\Middleware;
 
+use App\Enums\Billing\OnboardingBillingState;
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\Billing\BillingAccess;
@@ -14,18 +15,20 @@
 use Symfony\Component\HttpFoundation\Response;
 
 /**
- * 課金ゲート: BillingAccess の entitlement 判定で不許可
- * (= 有償プラン契約中の支払い不健全) の組織の業務 route アクセスを遮断し、
- * 理由 flash とともに billing へ誘導する middleware。alias: `require-active-subscription`。
+ * 課金ゲート: BillingAccess の entitlement 判定で不許可 (= 未契約、または有償プラン契約中の
+ * 支払い不健全) の組織の業務 route アクセスを遮断し、onboarding へ誘導する middleware。
+ * alias: `require-active-subscription`。
  *
- * - 判定は BillingAccess::hasActiveAccess のみ (subscription 直参照禁止。
- *   アプリは BillingAccess の差し替えで gate 方針を変更する)。
- *   plan_code null (未契約 = free tier) は許可されるため本 middleware を素通りする
- * - 遮断時: ブラウザは billing へ redirect + 理由 flash (error)、
- *   JSON/XHR は 402 Payment Required (同一文言)
- * - allowlist: billing (index/checkout/portal)・Stripe webhook・組織管理系 route には
- *   本 middleware を適用しない (route 側で group に含めない構造的 allowlist。
- *   遮断中でも checkout / Customer Portal に到達できることを保証する)
+ * - 判定は BillingAccess::state()->grantsAccess() のみ (subscription 直参照禁止。
+ *   plan_code は見ない = 無料枠は free_plan_code='personal' の ActiveFreePlan で表現する)
+ * - 遮断時: ブラウザは onboarding へ redirect (manageBilling 保持者は自分で契約できるので
+ *   onboarding.checkout、非保持者は説明画面 onboarding.billing-required)。**遮断理由の flash は
+ *   積まない** — 理由は着地ページ (Onboarding/Checkout・Onboarding/BillingRequired) が持つ
+ * - JSON/XHR は 402 Payment Required (文言は state で 2 分岐)
+ * - allowlist: billing (index/checkout/portal)・onboarding (checkout/activate-personal/
+ *   billing-required)・Stripe webhook・組織管理系 route には本 middleware を適用しない
+ *   (route 側で group に含めない構造的 allowlist。遮断中でも契約導線 / Customer Portal に
+ *   到達できることを保証する = 「契約するための画面が契約してないと見られない」詰みの防止)
  *
  * 対象 organization の解決:
  *   1. route に `{organization}` binding があればそれを使う。その際、非メンバー /
@@ -39,12 +42,16 @@
 final class RequireActiveSubscription
 {
     /**
-     * 遮断理由 (ブラウザ flash / JSON 402 で同一文言。H1: 説明なしリダイレクト対策)。
-     * 判定変更後に遮断されるのは「有償プラン契約中の支払い不健全」のみのため、
-     * free 組織を誤解させる旧文言 (「有効なサブスクリプションがありません」) は廃止。
+     * JSON/XHR の 402 文言 (ブラウザ経路の理由提示は着地ページが担う)。
+     *
+     * BLOCKED_MESSAGE は「有償契約 + 支払い不健全」(= ExpiredCheckout) の既存契約文言で不変。
+     * NO_PLAN_MESSAGE はゲート反転で新たに生まれた遮断事由 (未契約 = NoSubscription /
+     * PendingCheckout) 専用。
      */
     private const string BLOCKED_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。';
 
+    private const string NO_PLAN_MESSAGE = 'ご利用にはプランの選択が必要です。';
+
     public function __construct(
         private readonly BillingAccess $access,
     ) {}
@@ -65,22 +72,29 @@ public function handle(Request $request, Closure $next): Response
             return $next($request);
         }
 
-        if ($this->access->hasActiveAccess($organization)) {
+        $state = $this->access->state($organization);
+        if ($state->grantsAccess()) {
             return $next($request);
         }
 
-        // JSON/XHR は 402、ブラウザは billing へ誘導 (理由 flash 付き。文言は両経路で統一)
+        // JSON/XHR は 402 (文言は遮断事由で 2 分岐)
         if ($request->expectsJson()) {
-            abort(Response::HTTP_PAYMENT_REQUIRED, self::BLOCKED_MESSAGE);
+            abort(
+                Response::HTTP_PAYMENT_REQUIRED,
+                $state === OnboardingBillingState::ExpiredCheckout ? self::BLOCKED_MESSAGE : self::NO_PLAN_MESSAGE,
+            );
         }
 
         // 直前 hop で積まれた flash (例: 招待受諾の success) が、この gate-redirect の
-        // 1 hop で消費され失われないよう延命する。with('error', ...) は新規 flash の
-        // 積み込みで両立する (key 衝突時は本 middleware の error が優先される —
-        // 遮断理由の提示が最優先の情報のため許容)
+        // 1 hop で消費され失われないよう延命する。
         $request->session()->reflash();
 
-        return redirect()->route('billing.index')->with('error', self::BLOCKED_MESSAGE);
+        // 遮断理由は着地ページが持つ (middleware は error flash を積まない)。
+        return redirect()->route(
+            Gate::forUser($user)->allows('manageBilling', $organization)
+                ? 'onboarding.checkout'          // 自分で契約できる = プラン選択へ
+                : 'onboarding.billing-required', // 契約権限なし = 説明画面へ
+        );
     }
 
     /**
diff --git a/app/Models/Organization.php b/app/Models/Organization.php
index b354f9d..e9f2b95 100644
--- a/app/Models/Organization.php
+++ b/app/Models/Organization.php
@@ -111,8 +111,11 @@ public function oauthSessions(): HasMany
     }
 
     /**
-     * 現在の契約プラン (plan_code → plans.code。null = 未契約 = 支払い不要の free tier。
-     * quota は config/quota.php の fallback_plan、業務 route は BillingAccess が許可する)。
+     * 現在の契約プラン (plan_code → plans.code)。
+     *
+     * plan_code は **quota 解決キー** であり利用可否 (entitlement) には使わない
+     * (null = config/quota.php の fallback_plan が効く、それだけの意味)。業務 route の
+     * 利用可否は BillingAccess::state() が決める (無料枠は free_plan_code='personal')。
      *
      * @return BelongsTo<Plan, $this>
      */
diff --git a/app/Services/Billing/BillingAccess.php b/app/Services/Billing/BillingAccess.php
index 9f1d69e..7896276 100644
--- a/app/Services/Billing/BillingAccess.php
+++ b/app/Services/Billing/BillingAccess.php
@@ -21,6 +21,12 @@
  *
  * 利用可否は `SubscriptionState` 単体ではなく `SubscriptionService::deriveEntitlement` で
  * 確定する (PM 有無 / trial 終了 / paused / past_due を合成)。
+ *
+ * **`plan_code` は entitlement 判定に一切使わない** (quota の解決キーでしかない)。かつては
+ * 「plan_code null = fallback free プラン = 支払い不要 tier として許可」していたが
+ * (devnotes/20260712-0927-bugfix-billing-free-access。歴史として保持する)、ゲート反転で
+ * 無料枠は `organizations.free_plan_code = 'personal'` の明示申告 (`ActiveFreePlan`) として
+ * 表現するようになった。plan_code が null であること自体は許可の理由にならない。
  */
 class BillingAccess
 {
@@ -31,21 +37,14 @@ public function __construct(
     /**
      * 組織が業務機能を利用してよいか (billing entitlement)。
      *
-     * `state()->grantsAccess()` が本来の判定。これに加えて **移行 OR を 1 行持つ**:
-     * 現行の意図的な free 許可 (= `plan_code === null` の未契約組織) をそのまま通す。
-     *
-     * **この移行 OR は P4 (ゲート反転) で削除する**。削除条件は grandfathering backfill
-     * (`organizations.free_plan_code = 'personal'`) の完了で、backfill が `ActiveFreePlan` を
-     * 成立させることで既存の free 組織が `state()` 側で許可される。**本行を消すことが
-     * ゲート反転そのもの**であり、P4 はこの 1 行削除 + 期待反転の diff だけで済む。
+     * 判定は `state()->grantsAccess()` の一本 (= 無料枠は `ActiveFreePlan`、有償は
+     * `Subscribed` でのみ許可)。移行 OR (`plan_code === null` を通す 1 行) はゲート反転で
+     * 削除済み — 既存の未契約組織は grandfathering backfill が `free_plan_code = 'personal'`
+     * を書いて `ActiveFreePlan` として許可されるため、締め出しは発生しない。
      */
     public function hasActiveAccess(Organization $organization): bool
     {
-        if ($this->state($organization)->grantsAccess()) {
-            return true;
-        }
-
-        return $organization->plan_code === null;
+        return $this->state($organization)->grantsAccess();
     }
 
     /**
diff --git a/app/Services/Billing/QuotaService.php b/app/Services/Billing/QuotaService.php
index bf90a72..711441e 100644
--- a/app/Services/Billing/QuotaService.php
+++ b/app/Services/Billing/QuotaService.php
@@ -12,7 +12,8 @@
 /**
  * 多次元 Quota の唯一の判定窓口 (docs 07 ガイド §4)。
  *
- * - 既定値: config/quota.php の plan_code → limits map (plan_code null は fallback_plan)
+ * - 既定値: config/quota.php の plan_code → limits map
+ *   (plan_code null は fallback_plan = personal)
  * - override: organization_quotas.limits が key 単位で既定値を上書きする
  * - チェックは本 Service 経由のみ (コントローラに直書きしない)。超過は
  *   QuotaExceededException (web では back + error flash に変換される)
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 10141fe..3da396b 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -48,10 +48,9 @@
  *
  * plan_code 不変条件: `organizations.plan_code` は Stripe Price を持つ有償プランの
  * 契約 (active/trialing) 時のみ SubscriptionService が set し、`customer.subscription.deleted` で
- * null に戻す状態キー。**null = 未契約 = 支払い不要の free tier**
- * (config/quota.php の fallback_plan が適用される)。BillingAccess はこの契約を
- * entitlement 判定の根拠にするため、支払い不要のプランを plan_code に載せる場合は
- * BillingAccess とセットで見直すこと (RequireActiveSubscriptionMiddlewareTest が固定)。
+ * null に戻す状態キー。**用途は quota の解決のみ** (null = config/quota.php の fallback_plan が
+ * 適用される、それだけの意味)。利用可否 (entitlement) は plan_code を一切見ず
+ * BillingAccess::state() が決める (無料枠は organizations.free_plan_code='personal')。
  */
 class StripeWebhookProcessor
 {
diff --git a/config/quota.php b/config/quota.php
index d98565a..80953b3 100644
--- a/config/quota.php
+++ b/config/quota.php
@@ -25,21 +25,16 @@
     /*
     | plan_code が未設定 (未契約) の組織に適用するプラン。
     */
-    'fallback_plan' => 'free',
+    'fallback_plan' => 'personal',
 
     /*
     | plan_code → limits。プラン追加時は PlanSeeder と合わせてここに limits を定義する。
     */
     'plans' => [
-        'free' => [
-            'max_projects' => 1,
-            'max_members' => 3,
-            'max_storage_bytes' => 1 * 1024 * 1024 * 1024,      // 1 GiB (初期値。プラン設計で調整可能)
-        ],
         'personal' => [
             'max_projects' => 1,
             'max_members' => 3,                                 // PersonalPlanService::MAX_MEMBERS と一致させる
-            'max_storage_bytes' => 1 * 1024 * 1024 * 1024,      // 1 GiB (free の後継 = 実効 limits 不変)
+            'max_storage_bytes' => 1 * 1024 * 1024 * 1024,      // 1 GiB (旧 free の後継 = 実効 limits 不変)
         ],
         'starter' => [
             'max_projects' => 1,
diff --git a/database/migrations/2026_07_17_000300_backfill_grandfathered_free_plan_code.php b/database/migrations/2026_07_17_000300_backfill_grandfathered_free_plan_code.php
new file mode 100644
index 0000000..9b80e40
--- /dev/null
+++ b/database/migrations/2026_07_17_000300_backfill_grandfathered_free_plan_code.php
@@ -0,0 +1,103 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Services\Billing\SubscriptionService;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Support\Facades\DB;
+
+/**
+ * ゲート反転 (P4) の declarer-less grandfathering backfill。
+ *
+ * 反転前は「plan_code IS NULL = 未契約 = 支払い不要の free tier」として素通ししていた。
+ * 反転後の判定 (`BillingAccess::state()`) は plan_code を一切見ないため、この経路で許可されて
+ * いた既存 org は free entitlement (`free_plan_code='personal'`) を明示的に持たない限り遮断される。
+ * 本 migration がゲートコードの活性化より前に当該集合を空にする (= 締め出しゼロ)。
+ *
+ *   grandfather := { org : plan_code IS NULL ∧ free_plan_code IS NULL ∧ ¬entitled(org) }
+ *
+ * **entitlement の判定は `SubscriptionService::deriveEntitlement()` に委譲する**
+ * (述語を SQL へ写すと `state()` との集合ドリフトが生じる: past_due + 支払い手段有りは
+ * entitled = 救ってはいけない / trial 終了 + 支払い手段無しは ¬entitled = 救わねばならない。
+ * さらに `subscription('default')` は type='default' の **最新 1 行** のみを見るため EXISTS では
+ * 再現できない)。よって対象 ID は PHP で確定し、その ID 集合で UPDATE する。
+ *
+ * declarer (`personal_declared_by_user_id` / `personal_declared_at`) は NULL のままにする
+ * (自己申告の記録が無い既存 org のため。partial unique index
+ * `organizations_personal_free_declarer_unique` の対象外 = 1 user 複数 org でも衝突しない)。
+ * 初回無償チケットは発火しない (`signup_tickets_granted_at` に触れない = 将来の activate /
+ * paid 成立時に 1 回だけ付与される)。
+ *
+ * 冪等 (`whereNull('free_plan_code')` ガード)。末尾の残余 0 件検証が違反すれば throw し、
+ * デプロイを中断してゲートを反転させない。down() は「どの org が migration 起因か」を
+ * 識別できないため意図的に no-op (旧コードは free_plan_code を見ないため無害に無視される)。
+ */
+return new class extends Migration
+{
+    /** 走査 / UPDATE の chunk サイズ (長時間ロックと N+1 を避ける) */
+    private const int CHUNK = 500;
+
+    public function up(): void
+    {
+        $now = CarbonImmutable::now();
+        $targets = $this->collectTargetIds();
+
+        foreach (array_chunk($targets, self::CHUNK) as $ids) {
+            DB::table('organizations')->whereIn('id', $ids)->update([
+                // migration はアプリ定数に依存させない (drift は invariant テストが固定する)
+                'free_plan_code' => 'personal',
+                'free_plan_activated_at' => $now,
+                'updated_at' => $now,
+            ]);
+        }
+
+        // 残余 0 件検証: 反転後に利用可否が変わる既存 org が 1 件も残っていないこと。
+        $remaining = count($this->collectTargetIds());
+        if ($remaining !== 0) {
+            throw new RuntimeException("grandfather backfill incomplete: {$remaining} org(s) would lose access");
+        }
+    }
+
+    public function down(): void
+    {
+        // backfill の巻き戻しは「どの org が migration 起因か」を識別できないため意図的に no-op。
+    }
+
+    /**
+     * grandfather 対象の org ID を確定する (母集団ガード + ¬entitled)。
+     *
+     * @return list<int>
+     */
+    private function collectTargetIds(): array
+    {
+        $subscriptions = app(SubscriptionService::class);
+
+        /** @var list<int> $targets */
+        $targets = [];
+
+        Organization::query()
+            ->whereNull('plan_code')        // plan_code 非 null の org は反転で結論が変わらない
+            ->whereNull('free_plan_code')   // 既に free entitlement を持つ org は対象外 (冪等)
+            ->with('subscriptions')
+            // Collection の型パラメータを明示して $organization を Organization に確定させる
+            // (無指定だと mixed に落ち、getKey() の戻り値も mixed になって cast.int で落ちる)。
+            ->chunkById(self::CHUNK, function (Collection $organizations) use ($subscriptions, &$targets): void {
+                /** @var Collection<int, Organization> $organizations */
+                foreach ($organizations as $organization) {
+                    $subscription = $organization->subscription('default');
+                    $entitled = $subscription instanceof Subscription
+                        && $subscriptions->deriveEntitlement($subscription)->entitled;
+
+                    if (! $entitled) {
+                        $targets[] = $organization->id;
+                    }
+                }
+            });
+
+        return $targets;
+    }
+};
diff --git a/database/migrations/2026_07_17_000400_remove_free_plan_row.php b/database/migrations/2026_07_17_000400_remove_free_plan_row.php
new file mode 100644
index 0000000..749b103
--- /dev/null
+++ b/database/migrations/2026_07_17_000400_remove_free_plan_row.php
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Support\Facades\DB;
+
+/**
+ * free プランの撤去 (D11)。後継は `personal` (P1 で seed 済み)。
+ *
+ * free entitlement は `organizations.free_plan_code='personal'` で表現するようになり、
+ * `plans` の free 行は行き場を失った。`PlanSeeder` は `updateOrCreate` のため seeder から
+ * 定義を消しても既存 DB の行は残る = 本 data migration で消す。
+ *
+ * 参照行 (`organizations.plan_code='free'` / free の `plan_prices`) が残存していたら
+ * **fail-closed で throw する** (黙って消して参照を壊さない)。free は Stripe Price を持たず
+ * plan_code に載る経路が構造的に無いため、残存 0 件が期待値。残存したらデプロイを止めて調査する。
+ *
+ * down() は `plans` の free 行のみを復元する (config/quota.php はリポジトリ内のため migration が
+ * 書き換えられない = rollback は運用手順で config を revert する)。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        $freePlanId = DB::table('plans')->where('code', 'free')->value('id');
+        if ($freePlanId === null) {
+            return; // 冪等: 既に撤去済み (未 seed の新規 DB を含む)
+        }
+
+        $referencingOrganizations = DB::table('organizations')->where('plan_code', 'free')->count();
+        if ($referencingOrganizations !== 0) {
+            throw new RuntimeException(
+                "cannot remove free plan: {$referencingOrganizations} organization(s) still reference plan_code='free'"
+            );
+        }
+
+        $prices = DB::table('plan_prices')->where('plan_id', $freePlanId)->count();
+        if ($prices !== 0) {
+            throw new RuntimeException("cannot remove free plan: {$prices} plan_price(s) still reference it");
+        }
+
+        DB::table('plans')->where('code', 'free')->delete();
+
+        $remaining = DB::table('plans')->where('code', 'free')->count();
+        if ($remaining !== 0) {
+            throw new RuntimeException("free plan removal incomplete: {$remaining} row(s) remain");
+        }
+    }
+
+    public function down(): void
+    {
+        if (DB::table('plans')->where('code', 'free')->exists()) {
+            return;
+        }
+
+        $now = CarbonImmutable::now();
+        DB::table('plans')->insert([
+            'code' => 'free',
+            'name' => 'Free',
+            'monthly_ticket_grant' => 0,   // D28: 月次付与は廃止
+            'sort_order' => 0,
+            'is_active' => true,
+            'created_at' => $now,
+            'updated_at' => $now,
+        ]);
+    }
+};
diff --git a/database/seeders/BughuntBillingSeeder.php b/database/seeders/BughuntBillingSeeder.php
index 66fbd9a..1f4c111 100644
--- a/database/seeders/BughuntBillingSeeder.php
+++ b/database/seeders/BughuntBillingSeeder.php
@@ -8,8 +8,11 @@
 use App\Models\Billing\Plan;
 use App\Models\Billing\Subscription;
 use App\Models\Organization;
+use App\Services\Billing\PersonalPlanService;
 use App\Services\Billing\TicketLedgerService;
+use Carbon\CarbonImmutable;
 use Illuminate\Database\Seeder;
+use Illuminate\Support\Facades\DB;
 
 /**
  * bug-hunt env 専用: 有料プラン組織に active subscription + 初期チケットを付与する。
@@ -18,8 +21,13 @@
  * 有料プラン組織で true にし、業務ルート (/projects, /app) を bug-hunt で走行可能にする。
  * チケット消費系ジャーニー (AI 解析 / レンダ) のため初期残高も付与する。
  *
- * ★ free 組織には何も付与しない: 「課金なし経路」(billing redirect / 残高ゼロ) を
+ * ★ 無料組織には subscription もチケットも付与しない: 「課金なし経路」(残高ゼロ) を
  *   bug-hunt 環境内に温存し、課金ゲート系バグの検出能力を落とさない (概念設計 施策 4)。
+ *   ただし課金ゲートは plan_code を見ず free entitlement を要求するため、未契約
+ *   (plan_code NULL) の組織には declarer-less な free entitlement
+ *   (free_plan_code='personal' / personal_declared_by_user_id NULL) を立てる
+ *   = grandfathering backfill 後の本番状態と同型の fixture にする。
+ *   初回無償チケットは発火させない (signup_tickets_granted_at に触れない = 残高ゼロを温存)。
  *
  * 三重 fail-secure (BughuntOAuthSeeder と同一): (1) config('testing.fake_externals') === true、
  * (2) app()->environment('bughunt.local')、(3) DB 名 ^bug_hunt(_[1-8])?$。
@@ -49,6 +57,8 @@ public function run(TicketLedgerService $tickets): void
             return;
         }
 
+        $this->grandfatherUncontractedOrganizations();
+
         $paidPlanCodes = $this->paidPlanCodes();
         if ($paidPlanCodes === []) {
             $this->command->warn('BughuntBillingSeeder: 有料プランが無いため skip。先に PlanSeeder を流すこと。');
@@ -72,6 +82,29 @@ public function run(TicketLedgerService $tickets): void
         $this->command->info("BughuntBillingSeeder: {$organizations->count()} 組織に active subscription + チケット".self::INITIAL_TICKET_GRANT.' 枚を付与。');
     }
 
+    /**
+     * 未契約 (plan_code NULL) かつ free entitlement を持たない組織に declarer-less な
+     * free entitlement を立てる (= grandfathering backfill 後の本番状態と同型)。
+     *
+     * declarer NULL のため partial unique index `organizations_personal_free_declarer_unique`
+     * の対象外 = 1 user が複数組織を持っていても衝突しない。冪等 (whereNull ガード)。
+     */
+    private function grandfatherUncontractedOrganizations(): void
+    {
+        $now = CarbonImmutable::now();
+
+        $count = DB::table('organizations')
+            ->whereNull('plan_code')
+            ->whereNull('free_plan_code')
+            ->update([
+                'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
+                'free_plan_activated_at' => $now,
+                'updated_at' => $now,
+            ]);
+
+        $this->command->info("BughuntBillingSeeder: 未契約 {$count} 組織に declarer-less な free entitlement を付与。");
+    }
+
     /**
      * base price を持つプラン (= 有料プラン) の code 一覧。
      *
diff --git a/database/seeders/ManualTestSeeder.php b/database/seeders/ManualTestSeeder.php
index 2a0a49d..1456a9a 100644
--- a/database/seeders/ManualTestSeeder.php
+++ b/database/seeders/ManualTestSeeder.php
@@ -9,6 +9,7 @@
 use App\Models\Billing\Plan;
 use App\Models\Organization;
 use App\Models\User;
+use App\Services\Billing\PersonalPlanService;
 use App\Services\Organization\OrganizationProvisioningService;
 use Illuminate\Database\Seeder;
 use Illuminate\Support\Str;
@@ -20,7 +21,7 @@
  *
  * 実行: php artisan db:seed --class=ManualTestSeeder
  * 全ユーザーのパスワード: password123
- * email 規則: {role}-{plan_code}@example.com (例: owner-free@example.com)
+ * email 規則: {role}-{plan_code}@example.com (例: owner-personal@example.com)
  */
 class ManualTestSeeder extends Seeder
 {
@@ -122,12 +123,14 @@ private function createUser(string $name, string $email, bool $verified = true):
      * 組織生成は provisioning 経由 (Default Team パターンの不変条件を担保する唯一の窓口)。
      *
      * plan_code の不変条件を尊重する: 「plan_code は Stripe Price を持つ有償プランの契約状態でのみ
-     * set される」(Model/StripeWebhookProcessor/BillingAccess の docblock が定める)。
+     * set される」(Model/StripeWebhookProcessor の docblock が定める)。
      * よって有償プラン (current base Price あり) のときのみ plan_code を forceFill し、あわせて
      * active な Cashier subscription 行を投入する (plan_code 非 null ⇔ 契約行あり を seed でも満たす)。
-     * Free (Price 無し) は plan_code を null のまま = 未契約 = 支払い不要 tier として BillingAccess が許可する。
+     * 無料プラン (Price 無し) は plan_code を null のまま PersonalPlanService::activate() で
+     * free entitlement (free_plan_code='personal' / declarer = owner) を立てる。課金ゲートは
+     * plan_code を見ないため、activate しないと手動テスト環境の無料組織が締め出される。
      *
-     * 有償/Free の判定は Plan の「値」(current base Price の有無) からのみ導出し、プラン名 (code) の
+     * 有償/無料の判定は Plan の「値」(current base Price の有無) からのみ導出し、プラン名 (code) の
      * 文字列比較はしない (AGENTS.md ドメイン規約)。
      */
     private function createOrganization(User $owner, Plan $plan): Organization
@@ -139,15 +142,20 @@ private function createOrganization(User $owner, Plan $plan): Organization
         if ($plan->currentPrice(PlanPriceKind::Base) !== null) {
             $organization->forceFill(['plan_code' => $plan->code])->save();
             $this->attachFakeActiveSubscription($organization);
+
+            return $organization;
         }
 
-        return $organization;
+        // 無料プラン: marker + 初回無償チケット付与も activate 内で org 生涯 1 回だけ走る
+        app(PersonalPlanService::class)->activate($organization, $owner);
+
+        return $organization->refresh();
     }
 
     /**
      * 手動テスト用に active な Cashier subscription 行を直接投入する (Stripe API 非到達)。
-     * BillingAccess は plan_code 非 null の組織に active/trialing subscription を要求するため、
-     * plan_code を載せた有償組織は本行が無いと課金ゲートで締め出される。
+     * 課金ゲート (BillingAccess::state()) は entitled な subscription か free entitlement を
+     * 要求するため、有償組織は本行が無いと締め出される。
      * subscription('default') が active を返すための最小カラムのみを設定する。
      *
      * メソッド単体で冪等: 既に default subscription があれば作らない (run() の冪等 guard に依存せず、
diff --git a/database/seeders/PlanSeeder.php b/database/seeders/PlanSeeder.php
index f4b736c..4997458 100644
--- a/database/seeders/PlanSeeder.php
+++ b/database/seeders/PlanSeeder.php
@@ -20,13 +20,10 @@
  * - 価格の真実源は plan_prices (DB snapshot)。ここでは bootstrap 行
  *   (stripe_price_id=price_test_* / livemode=false / synced_at=null) を投入し、
  *   実運用では `billing:sync-stripe-prices` が Stripe Catalog の実 Price ID へ上書きする
- * - free / personal プランは Stripe Price を持たない (Checkout 対象外。
- *   personal は activate 経由の無料プランで requiresStripeCheckout()=false)。
- *   これは BillingAccess の entitlement 判定の前提でもある: plan_code は Stripe Price →
- *   Plan 解決 (StripeWebhookProcessor) でのみ set されるため、Price を持たない free が
- *   plan_code に載る経路はない (null = 未契約 = 支払い不要の free tier)。free に Price を
- *   持たせる場合は BillingAccess とセットで見直すこと
- *   (RequireActiveSubscriptionMiddlewareTest が固定)
+ * - personal プランは Stripe Price を持たない (Checkout 対象外。activate 経由の無料プランで
+ *   requiresStripeCheckout()=false)。free entitlement は organizations.free_plan_code='personal'
+ *   で表現する。plan_code は entitlement 判定に使わない (quota 解決キーであり、利用可否は
+ *   BillingAccess::state() が決める)
  */
 class PlanSeeder extends Seeder
 {
@@ -44,9 +41,7 @@ class PlanSeeder extends Seeder
 
     public function run(): void
     {
-        // free / personal は Checkout を持たないため plan_prices は作らない
-        // (free は後継 personal への移行が完了するまでの残置)
-        $this->upsertPlan('free', 'Free', 0);
+        // personal は Checkout を持たないため plan_prices は作らない
         $this->upsertPlan('personal', 'Personal', 1);
         $this->upsertPlan('starter', 'Starter', 2);
         $this->upsertPlan('standard', 'Standard', 3);
diff --git a/routes/web.php b/routes/web.php
index 74347e8..4d255e8 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -347,7 +347,7 @@
     /*
     | チケットスポット購入 (current org スコープ)。billing.* と同じく課金ゲート
     | (require-active-subscription) の対象外 = 支払い不健全で遮断中の組織でも購入できる
-    | (free 組織はそもそも遮断されない = BillingAccess の entitlement 判定)。
+    | (無料枠は free_plan_code='personal' = ActiveFreePlan として BillingAccess が許可する)。
     | 閲覧は組織メンバー全員、Checkout 開始は manageBilling (owner / admin) のみ。
     */
     Route::get('/purchase-tickets', [TicketPurchaseController::class, 'show'])
@@ -376,7 +376,9 @@
     /*
     | 組織配下の業務 route (課金ゲート対象)。BillingAccess の entitlement 判定で
     | 不許可 = 有償プラン契約中の支払い不健全のみ billing へ redirect + 理由 flash
-    | (JSON は 402)。free (未契約 = plan_code null) 組織は遮断されない。
+    | (JSON は 402)。未契約組織は onboarding へ遮断される (P4 ゲート反転)。無料枠は
+    | free_plan_code='personal' の明示申告で表現し、plan_code は判定に使わない。
+    | billing / purchase-tickets / notifications / onboarding は gate group 外の構造的 allowlist。
     | 新しい業務ドメインの route はこの group 内に追加すること。
     */
     Route::middleware(['require-active-subscription', 'project.in-current-org'])->group(function (): void {
```

## テスト差分 (統計)
 tests/Feature/Billing/BillingAccessStateTest.php   |  14 +-
 tests/Feature/Billing/BillingPageTest.php          |  18 +-
 .../Billing/GateInversionF07RegressionTest.php     | 222 +++++++++++++++++++++
 tests/Feature/Billing/PersonalPlanServiceTest.php  |  22 +-
 tests/Feature/Billing/PlanActiveFilterTest.php     |  13 +-
 .../Billing/PlanSeederPriceInvariantTest.php       |   9 +-
 tests/Feature/Billing/QuotaCheckAdditionTest.php   |  12 +-
 tests/Feature/Billing/QuotaTest.php                |   2 +-
 .../RequireActiveSubscriptionMiddlewareTest.php    |  63 +++---
 tests/Feature/Capture/TakeRegistrationTest.php     |   2 +-
 tests/Feature/Capture/TakeUploadUrlTest.php        |   4 +-
 tests/Feature/DashboardTest.php                    |   5 +-
 tests/Feature/Marketing/PricingPageTest.php        |  29 ++-
 tests/Feature/Onboarding/ActivatePersonalTest.php  |  24 +--
 tests/Feature/Onboarding/BillingRequiredTest.php   |  10 +-
 .../Feature/Onboarding/OnboardingCheckoutTest.php  |  24 ++-
 tests/Pest.php                                     |  22 +-
 17 files changed, 373 insertions(+), 122 deletions(-)
