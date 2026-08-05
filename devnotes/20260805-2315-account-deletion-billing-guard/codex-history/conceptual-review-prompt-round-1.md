## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)
9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE は `Gate::authorize` を通すか、
   exemption inventory へ理由付きで登録する(deny-by-default)。
   **層 2(テナント境界 = 404)は層 3(認可 = 403)より前**(逆にすると存在が漏れる)
   (`ControllerAuthorizationGateTest`)
10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
> (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
> 相互参照するときは**番号ではなく項目名**で指すこと。既存の参照
> (`docs/app-integration-guide.md` の「§7 不変条件 8」/ stripe webhook migration の「不変条件 7」)
> を壊すため、どちらの側も renumber しない。

> **運用要件 (T108)**: production は `TRUSTED_PROXIES` の**明示宣言が必須**
> (未宣言 / `*` / `REMOTE_ADDR` / 書式不正は `ProductionEnvGuard` が起動時 fail-fast する
> = **初回デプロイ前に設定が要る破壊的変更**)。`trustProxies(at: '*')` はレート制限を
> 総当りに無効化するため復活させない。実 hop 一覧・CIDR の管理主体・変更手順は
> `docs/trusted-proxies-runbook.md` が正本。
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【前提】
- 本件は複数リポジトリ共有の機能台帳 c2c で **オーナー裁定済み** の機能。裁定内容 (何を作るか) は与件であり、蒸し返さない。レビュー対象は「aicue でどう実装するか」の概念設計のみ。
- 裁定 AG-033 (2026-08-05): 「有効なサブスクリプションがある、または自分がオーナーの課金中組織がある場合は退会をブロックし、何をすれば退会できるかを提示する」+ 標準形として 3 点 (決済事業者側データの扱いの明記 / 猶予期間つき削除 / 保持期間の実装)。
- aicue 側の一次目的は「今日開いている穴 (課金中組織のオーナーが退会すると宙に浮く) を塞ぐこと」。3 点については aicue の現状を実査したうえで **今必要な分だけ** を入れ、入れないものは理由を明記する方針。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項・セキュリティ不変条件に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Cashier + Svelte 5 + Inertia.js）
4. **述語の正しさ**: 「生きた課金責務」の定義 (grantsAccess かつ ends_at === null) に穴・過剰ブロック・取りこぼしはないか。特に Stripe の実挙動 (cancel_at_period_end / past_due / paused / trialing / incomplete) との対応
5. リスク: 重大な副作用・後退の可能性はないか (既存ユーザーが退会できなくなる詰み、競合状態、TOCTOU)
6. スコープの適切さ: 過大または過小になっていないか。「やらない」判断の根拠は妥当か
7. 型安全性: DTO パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: 退会時の課金ガード (account-deletion-billing-guard)

- c2c feature id: `account-deletion-billing-guard` / canonical v1 (origin: aigenba)
- オーナー裁定 AG-033 (2026-08-05) 済み。**裁定は与件**であり本設計では蒸し返さない。
  設計対象は「aicue でどう実装するか」だけ。
- ブリーフ: c2c `get_feature` 出力の要約 (feature_revision `5-1fec4bf47c79`)

---

## 1. 仮説

**仮説**: 現在の退会 (アカウント削除) ガードは「組織にメンバーが孤児化するか」だけを見ており、
「その組織に生きた課金責務 (Stripe subscription) が残るか」を見ていない。そのため
**課金中の個人組織 (自分だけがメンバー) のオーナーが退会すると、オーナー不在の課金中組織と、
請求先が消えた Stripe subscription が残り、誰も解約できない**。

**検証したいこと**: この経路が aicue に実在するか (コードとテストで裏を取る)。

**成功と判断する条件**:
1. 「生きた課金責務が残る組織の唯一オーナー」は退会がブロックされる。
2. ブロックされたユーザーが**何をすれば退会できるか**を画面とサーバ応答の両方で提示される
   (行き先のない詰みを作らない = AGENTS.md 課金ゲート規約と同じ思想)。
3. 上記が Feature テストで固定され、退会経路が **Stripe API を一切呼ばない**ことも
   テストで固定される。

---

## 2. 現状 (実査結果 / 2026-08-05)

ブリーフの「AccountController は 38 行、課金の言及ゼロ」は事実だが、
**ガード本体は Controller ではなく Service にある**。台帳の記述だけでは穴の形を誤認するため、
実査で確定した現状を以下に記す。

### 2.1 退会経路

| 層 | 実装 | 事実 |
|----|------|------|
| route | `routes/web.php` L199-202 | `DELETE /settings/account` + `recent-auth` (step-up) 必須 |
| Controller | `app/Http/Controllers/Settings/AccountController.php` (38 行) | 薄い。`OrganizationMembershipService::deleteAccount()` へ委譲。課金の言及ゼロ |
| Service | `app/Services/Organization/OrganizationMembershipService::deleteAccount()` (L607-651) | users→organizations の canonical 行ロック下で述語を再評価し、`ValidationException(['account' => ...])` で中断 |
| 述語 | 同 `organizationsBlockingDeletion()` (L532-547) | **唯一 Owner かつ 他メンバーが 1 人以上残る**組織のみを blocker とする |
| 表示 | `app/Http/Controllers/Settings/ProfileController.php` L30 → `resources/js/pages/Settings/Index.svelte` L315-329 | `soleOwnedOrganizations: {name, slug}[]` を warning Alert で表示。ボタンは disabled にしない (禁止事項 8 準拠) |

`organizationsBlockingDeletion()` の docblock が明示している:

> 個人組織のように $user が唯一メンバーの組織は「孤児化するメンバーが居ない」ため対象外。

そして `tests/Feature/Auth/AccountDeletionTest.php` に
**「唯一オーナーだが自分のみメンバー (個人組織) なら削除できる」というテストが存在する**
(= 現状の仕様として固定されている)。ここが穴の入口。

### 2.2 課金側の現状

- 課金主体 (billable) は **Organization** (`Cashier::useCustomerModel`)。
  `organizations.stripe_id` / `subscriptions.organization_id` (cascadeOnDelete)。
- **User を削除しても Organization 行は消えない**。`organization_user` の pivot だけが
  `cascadeOnDelete` で消える (`2026_06_11_074000_create_organizations_tables.php`)。
  → 退会後、組織はメンバー 0 人・Owner 不在で存続する。
- **組織削除の機能はアプリに存在しない** (`grep -rn "deleteOrganization\|DeleteOrganization" app tests` = 0 件)。
  つまりオーナー不在になった課金中組織は、アプリ操作では**永久に回収も解約もできない**。
- 課金状態の判定は `App\Services\Billing\BillingAccess` が唯一の窓口
  (「middleware / controller / service での subscription 直参照は禁止」と docblock が宣言)。
  利用可否の最終確定は `SubscriptionService::deriveEntitlement()`。
- 派生状態は `App\Enums\Billing\SubscriptionState`
  (`Active` / `UpgradeRecovery` / `PastDue` = 課金継続、`Paused` / `Inactive` = 非課金)。
- オートリチャージ (`TicketAutoRecharge`) は**消費起点**でしか課金しない
  (`TicketLedgerService` L442 → `AutoRechargeTriggerJob` → `maybeCreateAttempt`)。
  メンバー 0 人の組織では消費が発生しない = 自動課金は起きない。

### 2.3 その他の実査結果 (裁定の 3 点への影響)

- `users` テーブルに **softDeletes なし**。退会は物理削除 (関連は FK cascade / nullOnDelete)。
  猶予期間つき削除の土台は現時点で存在しない。
- `/terms` は `resources/views/legal/terms.blade.php` の**プレースホルダ**
  (「アプリ公開前に正式な文面へ差し替えてください」/ noindex)。
  **保持期間 (年数) を宣言する規約文言がまだ存在しない**。
- Stripe 呼び出しは `App\Services\Billing\Contracts\StripeGatewayInterface` に閉じており、
  テストでは `FakeStripeGateway` / mock に差し替えられる (= 「呼ばないこと」を検証できる)。

---

## 3. 課題 (穴の定式化)

現行述語:

```
blocked(user, org) := isOwner(user, org) ∧ ¬hasAnotherOwner(org) ∧ membersCount(org) > 1
```

守っている不変条件は「**Owner 不在の組織にメンバーを取り残さない**」だけ。
一方で守れていない不変条件が 2 つある:

- **(I1) Owner 不在の組織に生きた課金責務を残さない**
  課金中の個人組織 (membersCount = 1) は述語をすり抜ける。
  結果: Stripe が次期以降も請求を継続し、アプリ側には解約する主体が存在しない。
  ユーザー側の実害 = 退会したのに課金が続く。運営側の実害 = 返金・手動解約の個別対応。
- **(I2) ブロック時に「何をすれば退会できるか」を提示する**
  現行メッセージは「オーナーを移譲してください」の 1 種類のみ。課金起因でブロックした場合、
  個人組織には移譲先のメンバーが**存在しない**ため、この文言のままだと**行き先のない詰み**になる
  (AGENTS.md 課金ゲート規約「403 で突き放さず専用画面で受ける」と同じ失敗様式)。

---

## 4. 方針

### 4.1 述語の拡張 (I1)

blocker 判定を「唯一 Owner である組織」に広げ、その組織ごとに**理由の集合**を持たせる:

```
soleOwned(user, org) := isOwner(user, org) ∧ ¬hasAnotherOwner(org)

reasons(user, org) ⊆ {
  OwnerlessMembers : membersCount(org) > 1              // 既存
  ActiveBilling    : hasLiveBillingObligation(org)       // 新規
}
blocked(user, org) := soleOwned(user, org) ∧ reasons(user, org) ≠ ∅
```

**`hasLiveBillingObligation(org)` の定義 (本設計の核)**:

> 組織に、**将来の請求を発生させうる subscription 行が残っている**か。
> `SubscriptionState::fromSubscription($sub)->grantsAccess()`
> (= `Active` / `UpgradeRecovery` / `PastDue`) **かつ** `ends_at === null` を満たす行が
> 1 つでもあれば true。

この定義を採る理由:

- **`Paused` / `Inactive` を除く**: `Paused` は trial 終了後カード未登録の read-only
  (課金は発生しない)。`Inactive` は `canceled` / `unpaid` / `incomplete*` (終端)。
  どちらも「請求先が消えたまま課金が続く」害を生まない。
- **`ends_at !== null` を除く (重要)**: Customer Portal での解約は
  `cancel_at_period_end` = 期末解約予約であり、`stripe_status` は当面 `active` のまま
  `ends_at` が入る。ここを除外しないと「**解約したのに退会できない**」期間 (最長 1 課金周期) が生まれ、
  I2 の趣旨に真っ向から反する。解約予約済みなら Stripe が自動終了させるため追加請求は発生せず、
  ブロックする理由がない。
- **`deriveEntitlement()` を使わない**: あれは「利用可否 (entitlement)」の判定であり、
  本述語は「**Stripe 側に残る請求責務**」の判定で問いが違う。特に `PastDue` かつ PM 無しは
  entitlement 上 denied だが請求責務は残りうる。既存 docblock の禁止 (subscription 直参照禁止 /
  `grantsAccess` を可否判定に使うな) を守るため、**述語の実装は `App\Services\Billing` 配下に置き**、
  「これは entitlement 判定ではない」ことを docblock で明示する。組織側 service からは
  billing service を注入して呼ぶ (subscription 行を Organization 配下の service が直接読まない)。

### 4.2 出口の提示 (I2)

理由ごとに「次の一手」を対にする。**サーバ応答 (ValidationException) と画面 (Inertia props) の
両方**で提示する (画面は削除前の予告、応答はブロック後の確定説明)。

| 理由 | 次の一手 | 導線 |
|------|----------|------|
| `OwnerlessMembers` | 別のメンバーへオーナーを移譲する | `/organizations/{slug}/settings` (既存) |
| `ActiveBilling` | サブスクリプションを解約する (または移譲する) | `/billing` (既存。課金ゲート allowlist 内) |

- **ボタンは disabled にしない** (禁止事項 8)。押下 → サーバが再判定 → 理由付きエラー表示、という
  現行の作法を維持する。
- 課金起因ブロックのとき `/billing` へ到達できることは構造的に保証済み
  (`billing.*` は `require-active-subscription` group の外の allowlist)。
  唯一 Owner は `manageBilling` を必ず持つ (OrganizationPolicy の Owner/Admin 既定境界)。

### 4.3 「決済事業者 API を呼ばない」原則の明文化 (裁定 1 の一部)

裁定が標準形の原則として採った「**退会処理から決済事業者 API を呼ばない (ガードで止める)**」を
aicue でも採り、**Feature テストで固定**する (退会成功時に `StripeGatewayInterface` が
1 度も呼ばれないこと)。自 DB と外部サービスの二重書き込みを構造的に避けるため。

顧客データの事業者側の扱い (非表示化は作成から 90 日後のみ・処理に最大 30 日) は
**運用手順の話**であり、コードではなく `docs/architecture.md` に運用注記として残す。

---

## 5. 代替案と却下理由

| # | 代替案 | 却下理由 |
|---|--------|---------|
| A | 退会時にアプリから Stripe subscription を自動解約する | 裁定が採った原則に反する。自 DB 削除と外部 API の二重書き込みで、API 失敗時に「ユーザーは消えたが解約は失敗」という回復不能な不整合が残る。冪等性不変条件 (webhook 経由) の外で課金状態を書き換えることにもなる |
| B | 退会時に組織ごと削除する | boundary 外 (台帳が「組織削除そのもの (tenancy)」を含まないと定義)。かつ組織削除機能自体が未実装で、影響範囲 (プロジェクト・動画・チケット台帳・監査) が桁違いに広い。思考原則 2 に反する |
| C | `BillingAccess::hasActiveAccess()` を流用して判定する | 問いが違う (利用可否 vs 請求責務)。`Paused` を「課金中」と誤判定し、`cancel_at_period_end` を「解約済み」と扱えず**解約したのに退会できない**詰みを生む |
| D | 述語を `Organization` モデルや `OrganizationMembershipService` に直書きする | 「課金判定は Billing 配下を経由する」既存規約を崩す。subscription 行の解釈が 2 か所に散る |
| E | ブロックせず警告だけ出して退会させる | I1 が塞がらない。オーナー不在の課金中組織は**アプリ操作では回収不能** (組織削除機能が無い) ため、警告では実害を止められない |
| F | 猶予期間つき削除 (soft delete + 復元) を同時に入れる | §6 参照。今回の穴 (課金の宙づり) を塞ぐのに不要で、`users` の物理削除前提 (FK cascade / nullOnDelete / CipherSweet PII) を全面的に作り替える大工事になる |

---

## 6. スコープ境界

### 6.1 今回やる (必須)

1. 生きた課金責務の判定 (`App\Services\Billing` 配下の新 service)。
2. `organizationsBlockingDeletion()` の述語拡張と **理由付き DTO 化**。
3. ブロック時サーバ応答の理由別メッセージ (何をすれば退会できるか)。
4. `/settings` の削除前警告を理由別表示に (移譲導線 / 課金導線)。
5. テスト: Feature (ブロック / 通過 / 解約予約済みは通過 / Stripe 未呼び出し) と
   Architecture (ロック inventory 更新、理由 enum の PHP⇔TS 同期)。
6. `docs/architecture.md` に退会ガードの不変条件と事業者側データの運用注記を追記。

### 6.2 今回やらない (裁定の 3 点のうち 2 点を含む) — **やらない理由**

| 項目 | やらない理由 | 後続 TODO 候補 |
|------|--------------|----------------|
| **猶予期間つき削除 (soft delete + 復元導線 + 即時削除の併存)** | 今日開いている穴は「課金の宙づり」であって「誤操作の救済」ではない。`users` は物理削除前提で FK (cascade / nullOnDelete)・CipherSweet PII・監査イベントの `user_id` null 化まで設計が噛み合っており、soft delete 化は退会以外の全経路 (ログイン・招待・blind index の一意性・組織メンバー一覧) に波及する。思考原則 2「今必要なものだけ作る」に倒す | あり (独立 TODO) |
| **保持期間の実装 (規約が宣言する年数と匿名化処理の対応)** | **対応させるべき規約文言がまだ存在しない** (`/terms` はプレースホルダ・noindex・保持年数の記述ゼロ)。宣言のない年数を先にコードへ焼き込むと、正式文面確定時に必ず作り直しになる。実装の前提 (規約確定) が未成立 | あり (規約正式化に blocked) |
| **決済事業者側データの非表示化 (redaction) の自動化** | 90 日制約・最大 30 日処理という事業者側の制約は**運用手順**で受けるのが標準形の趣旨。退会処理から API を呼ばない原則とも整合する。今回は `docs/architecture.md` に運用注記として書き、自動化はしない | あり (低優先) |
| **オートリチャージ設定を blocker に含める** | 実査の結果、オートリチャージは**チケット消費起点**でしか課金しない (`TicketLedgerService` → `AutoRechargeTriggerJob`)。メンバー 0 人の組織では消費が起きないため自動課金は発生しない。blocker に足すと、害の無い状態で退会を止める過剰ガードになる | なし (不要と判断) |
| **チケット残高の失効警告** | 退会を止める理由にならない (課金は発生しない)。表示を足すと削除前 UI が肥大する | あり (UX 改善として低優先) |
| **組織削除 (tenancy) / サブスク解約 API** | 台帳 boundary の対象外と明記されている | 別 feature |
| **オーナー不在で既に残っている組織の回収 (バックフィル)** | 本改修は**新規発生を止める** deny-by-default。既存孤児組織があるかは運用調査 (本番データ) の話で、設計フェーズでは判断材料がない | あり (運用調査として) |

---

## 7. 検証方法

| # | 検証 | 期待結果 |
|---|------|----------|
| V1 | 課金中 (`active`, `ends_at=null`) の個人組織の唯一 Owner が退会 | ブロック。`errors.account` に「サブスクリプションを解約してください」と `/billing` の案内。user 行は残る |
| V2 | 解約予約済み (`ends_at` セット) の個人組織の唯一 Owner が退会 | **成功** (追加請求が発生しないため止めない) |
| V3 | `canceled` / `paused` の個人組織の唯一 Owner が退会 | 成功 |
| V4 | 課金中組織で 2 人目 Owner がいる場合 | 成功 (課金責務の引受先が残るため) |
| V5 | 課金中組織 + 他メンバー有りの唯一 Owner | ブロック。理由 2 つ (移譲 + 解約) が両方提示される |
| V6 | 無料枠 (`free_plan_code='personal'`, subscription 行なし) の個人組織 | 成功 (現行挙動の維持 = 退行なし) |
| V7 | 退会成功経路で `StripeGatewayInterface` を mock | 1 度も呼ばれない |
| V8 | `/settings` の props | 理由付き blocker が返り、理由別の導線リンクが描画される |
| V9 | 既存テスト `tests/Feature/Auth/AccountDeletionTest.php` | 「個人組織なら削除できる」は**課金なし前提**として残す (削除しない。禁止事項 3) |

検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test`。

---

## 8. 使命との関係

AI-CUE の使命は「現場作業者が標準化されたマニュアル動画を作れること」であり、退会は周辺機能である。
ただし**課金の宙づりは信頼を直接毀損する**タイプの欠陥で、しかもアプリ操作では回復できない
(組織削除機能が無い) ため、放置コストが構造的に高い。最小限のガードで塞ぐのが妥当。
