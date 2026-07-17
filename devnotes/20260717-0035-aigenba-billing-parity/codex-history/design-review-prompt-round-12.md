Round 11 で APPROVED をもらったが、**その後ユーザー指摘により設計方針そのものを差し戻した**。再レビューを依頼したい。

## 何が起きたか

ユーザー指摘: 「基本的に全部揃える方向。揃えてから問題を調整」「値段を憶測でいじるよりロジックを合わせて欲しい」
「**指摘されたバグが aigenba に存在しているバグなのかどうかをちゃんと検証してください**」

検証した結果、**あなたが指摘した 7 バグのうち 5 件は「私が aigenba から逸脱して発明した独自実装」が原因**だった
（実コードで確定）。**aigenba 通りに移植していれば発生しなかった**:

| 指摘されたバグ | aigenba に存在するか | 真因 |
|---|---|---|
| NoPlan variant 欠落 | **無い**。`OnboardingBillingState` が `ActiveFreePlan` / `NoSubscription` を最初から分離 | 私の D18（`OnboardingBillingState` を移植せず `EffectivePlan` を発明した） |
| paid 判定の同期ラグ / 不健全素通し | **無い**。`state()` は `plan_code` を一切見ず `subscription('default')` + `deriveEntitlement()` のみ | 私の D26（`plan_code` 依存の解決順を発明した） |
| debt の二重回収 | **無い**。aigenba に debt 概念が無い（per-source clamp `max(…,0)`） | 私が debt を発明した（D19/D24/D27） |
| reserve が debt 無視 | **無い**（同上の派生） | 同上 |
| Personal 非公開のまま反転 | **無い**。`PlanSeeder` に「Personal は `is_active=true` で公開する」と明記 | 私の D10 |

つまり合議の大半は**私が作り込んだ問題を私が塞ぐ作業**だった。D18 の根拠（「checkout session テーブルが無いから
Pending/Expired を表現できない」）も、**P9 でそのテーブルを追加する自分の設計と矛盾**していた。

## v2 でやったこと

- **原則を「aigenba verbatim」に固定**。逸脱は **AGENTS.md の禁止事項・不変条件に抵触する場合のみ**許す
  （私の設計判断を根拠にしない）。値は aigenba の既定値をそのまま。
- **撤回**: D18/D23 → `OnboardingBillingState`(5 状態) + `BillingAccess::state()` verbatim /
  D26 → `subscription` + `deriveEntitlement` /  D19/D24/D27 → per-source clamp verbatim（debt なし）/
  D10 → `is_active=true` seed（再公開フェーズごと削除）/ D1・D2 → **PlanCode verbatim 5 case** /
  U1・U2・U3 → aigenba 既定値。
- **D25 変更**: `BillingCheckoutSession` を **P2 へ前倒し**（`state()` が読むので状態モデルの一部）。
- **D28 新規（要注視）**: **月次チケット付与を廃止**（全 tier `monthly_ticket_grant=0`）。aigenba は施策8/v3 で廃止済み
  （都度購入 / オートリチャージのみ）で、**AI-CUE だけ月次が生きていると per-source clamp の移植で
  「aigenba では死んでいる債務の逃げ道」が生きる**ため **clamp 移植と不可分**と判断した。コード経路は不変
  （既存 guard `monthly_ticket_grant <= 0` が aigenba の `if ($count < 1) return;` と同形になる）。
  プロダクト影響（standard の月 100 枚が無くなる）はユーザーへ明示済み。
- **P1/P2/P3/P4/P5/P8b/P9 を verbatim 方針で再生成**（P6/P7/P8a は v1 のまま = verbatim 逸脱を含まないため）。

## 維持する逸脱（AGENTS.md 由来のみ）
amount ベース reserve（可変コスト = ドメイン要件）/ 2 フェーズ（#7。aigenba も同じ）/ disabled 禁止（#8）/
請求先 PII は email+name とも CipherSweet（#6。aigenba は平文 string）。

## 見てほしい点
1. **v1 の発明が本文に残っていないか**（`EffectivePlan` / `NoPlan` / `debt` / `is_active=false` / `isDeclared()` 等）。
2. **verbatim 移植の前提で、フェーズ間の契約が整合しているか**（特に P2 の移行 OR で「P2 は挙動不変」が本当に成立するか、
   P3→P4 の窓の扱い、`BillingCheckoutSession` の P2 前倒しに伴う齟齬）。
3. **D28（月次付与廃止）の波及が漏れていないか**（既存テスト / seeder / 料金表文言）。
4. 維持した 4 つの逸脱が妥当か（AGENTS.md 由来として正当か、過剰でないか）。

改訂後の詳細設計書を全文添付する。残る穴があれば指摘し、無ければ APPROVED を出してほしい。

---

## 改訂後の詳細設計書（v2 全文）

# 詳細設計: aigenba-billing-parity（決済ドメインを aigenba に全面一致させる）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を
作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **決済は North Star 本体ではなく支持基盤**。本設計の価値は「現場作業者が残高・契約で作業を止められない」ことと
> 「保守コストを下げる」ことに限定される（効果を誇張しない）。

### 禁止事項（AGENTS.md 正本）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` の factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml` に置く）
7. 操作系 POST の応答での `redirect()->intended()`（ログイン直後フロー専用）
8. **必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）**

### セキュリティ不変条件（アプリ都合で緩めない。AGENTS.md）

本設計に直結するもの:

- **#7 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ** ← **維持する**
- #1 tenant キー不信 / #2 子は親に属する（不整合は認可より前に 404）/ #3 cross-org 不可
- #5 権限判定は常に `laratrust_team_id` を明示（strict_check=true）/ #6 PII は CipherSweet

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）/ **Pest**（`composer test`）
- **RefreshDatabase** + `--parallel`（`tests/Pest.php` でグローバル適用。個別 `DatabaseTransactions` 禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）。新モデルには Factory も作る
- **DTO + JsonResource** パターン / アーリーリターン推奨
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Cashier(Stripe) + Svelte 5 runes + Inertia + TypeScript
- UI: **DS token のみ**（hex 直書き禁止。DESIGN.md が canonical）/ アイコンは `@lucide/svelte` /
  **T071 の primitive**（`templates/PageContainer`・`molecules/PageHeader(Section)`・`templates/PageContent`）準拠
  （arch: page-shell-structure / ds-purity / atomic-import-graph / lucide-scoped-import / svg-inline-allowlist）

## 概念設計リファレンス

- [conceptual-design.md](conceptual-design.md)（Codex gpt-5.4 合議 **APPROVED** / Round 3）
- 接地監査: [../20260716-2339-aigenba-billing-audit/audit-report.md](../20260716-2339-aigenba-billing-audit/audit-report.md)（49 findings）

### ユーザー決定（前提。再検討しない）

| # | 分岐 | 決定 |
|---|------|------|
| F1 | 課金ゲート | **aigenba 方式へ反転**（未契約は遮断 → checkout / activate-personal） |
| F2 | signup grant 付与契機 | **aigenba 方式へ**（登録時 → プラン有効化時。marker が真実源） |
| F3 | チケット会計 | **aigenba 方式へ**（= 残高会計の**精緻化**。台帳の置換ではない） |
| スコープ | — | **4 つ全部**（裏チャージ / 判断不要 15 件 / プランモデル / チケット会計） |

## 横断決定（v2: aigenba verbatim へ全面差し戻し）

> **2026-07-17 の方針転換（ユーザー指摘 +  aigenba 側の検証結果を受けた全面改訂）**
>
> ユーザー指摘: 「**基本的に全部揃える方向だよ。揃えてから問題を調整してもらいたい**」
> 「**値段を憶測に基づいていじるよりもロジックを合わせて欲しい**」
> 「指摘されたバグが aigenba に存在しているバグなのかどうかをちゃんと検証してください」
>
> 検証（`bug-origin-verification.md`）の結果、**Codex が指摘した 7 件のうち 5 件は「私が aigenba から逸脱して
> 発明した独自実装」が原因**であり、**aigenba 通りに移植していれば発生しなかった**ことが確定した。
> よって **v1 の横断決定のうち、私の設計判断に由来するものを全て撤回し、aigenba verbatim へ戻す**。

### 原則（v2）

1. **aigenba verbatim で移植する**。「parity より良い設計」を持ち込まない（それがバグの発生源だった）。
2. 逸脱してよいのは **AGENTS.md の禁止事項・セキュリティ不変条件に抵触する場合のみ**（私の設計判断は根拠にしない）。
3. **値は aigenba の既定値をそのまま使う**（憶測で調整しない）。
4. AI-CUE に対象が存在しない aigenba 機能は移植しない（席課金 / encounter 等）。
5. **aigenba にある問題は AI-CUE 側で先回り修正しない**。aigenba 側で修正されたらそれを取り込む。

### 撤回する v1 の決定（私の発明。aigenba verbatim へ戻す）

| 撤回 | 戻す先（aigenba verbatim） | 撤回理由 |
|---|---|---|
| ~~D18 / D23~~（`EffectivePlan` 4 variant の新設） | **`App\Enums\Billing\OnboardingBillingState`（5 状態: NoSubscription / PendingCheckout / ExpiredCheckout / Subscribed / **ActiveFreePlan**）+ `BillingAccess::state()` をそのまま移植**。`grantsAccess() = Subscribed \|\| ActiveFreePlan` | 私の 4 variant は **aigenba の 5 状態の劣化コピー**だった（`ActiveFreePlan` ≡ 私の Grandfathered、`NoSubscription` ≡ 私の NoPlan）。**畳んだせいで「NoPlan 欠落」バグを自作**した。D18 の根拠「checkout session テーブルが無いので Pending/Expired を表現できない」も、**P9 でそのテーブルを追加する自分の設計と矛盾**していた |
| ~~D26~~（`plan_code` 依存の解決順） | **`$org->subscription('default')` + `deriveEntitlement($sub)->entitled` で判定**（**`plan_code` を一切見ない**） | aigenba は元から plan_code を見ない。**私が plan_code 依存にしたから**「同期ラグ組織の締め出し」「支払い不健全の素通し」を自作した |
| ~~D19 / D24 / D27~~（debt 保全・数式・reserve への反映） | **`max($monthly, 0)` / `max($purchased, 0)` の per-source clamp をそのまま移植**（**debt 概念を持たない**） | aigenba に debt は存在しない。**私が発明したから**「二重回収」「reserve が debt 無視」を自作した |
| ~~D10~~（personal/starter を `is_active=false` で seed） | **`is_active=true` で公開**（`PlanSeeder` verbatim。「Personal は…`is_active=true` で公開する」と明記されている） | **私の発明**。これが「P4 反転時に無料導線が非公開」バグを生んだ |
| ~~D1 / D2~~（`PlanCode` を 3 case に縮小・Enterprise 特判の削除） | **`PlanCode` を verbatim 移植**（Personal / Starter / Standard / Business / Enterprise の 5 case）。`normalizeRaw` の Enterprise 除外も verbatim | 縮小は私の判断。case を verbatim にすれば `normalizeRaw` も verbatim で通り、PHPStan の `alwaysFalse` も起きない |
| ~~U1 / U2 / U3~~（値の再検討） | **aigenba の既定値をそのまま**（`default_threshold=5` / `default_max=50` / `max_count=1000` / `max_failures=3`）。低残高通知との併存・reserve TTL も aigenba のまま | 「可変コストだから合わないかも」は**実測せずに言った憶測**だった（実測では AI-CUE の既存 `ticket_low_balance_threshold=5` と完全一致）。**値を憶測でいじらない** |

### 維持する決定

| ID | 論点 | 決定 | 根拠 |
|---|---|---|---|
| **D5** | reserve プリミティブ | **amount ベースを維持**（aigenba の 1 encounter=1 枚は移植しない） | AI-CUE の消費は解析 1 枚 / レンダ 3 枚の**可変コスト**（`manual.analysis_ticket_cost` / `render_ticket_cost`）。機械移植すると単価差の前提が壊れて課金が壊れる。**ドメイン要件であり私の設計判断ではない**（Codex も妥当と判定） |
| **D4** | aigenba の disabled ボタン | **移植しない**。押下時にエラー表示 | **AGENTS.md 禁止事項 #8**（原則 2） |
| **#7** | reserve→commit/release の 2 フェーズ | **維持** | AGENTS.md 不変条件 #7。**aigenba も同じ**なので実は逸脱ですらない |
| **#6** | 請求先 PII | **email / name の両方を CipherSweet 化**（aigenba は平文 string） | **AGENTS.md 不変条件 #6**（原則 2） |
| **D6 / D21** | route スコープ | **route parameter を持たない current-org スコープ**（`onboarding.{checkout,activate-personal,billing-required}`） | AI-CUE の業務 route が current-org スコープ（`routes/web.php:349`）。org-slug 化は AI-CUE 全体の route 規約変更でスコープ外 |
| **D12** | `config/quota.php` の plan キー | **P1 で `personal` / `starter` の limits を必ず追加** | `QuotaService.php:33` が未知キーを `?? []` = **無制限に silent 退行**させる |
| **D13** | 移行期の `claimSignupGrantMarker()` public 化 | 許容（P6 で private へ戻す） | 移行期規約（付与と marker を同一 tx）の成立に必要 |
| **D14** | `PlanPriceService` への `?string $lookupKey` 追加 | 許容 | AI-CUE の `SyncStripePrices.php:78-87` が current 行の `lookup_key` 一致を要求。verbatim だと既存 sync 契約が壊れる |
| **D11** | 既存 `free` Plan 行と `fallback_plan='free'` | **P4 で撤去**（`personal` が後継。data migration + 残余 0 件検証。rollback は「コード/config revert → migration down」の運用手順） | free fallback の消滅とゲート反転は同一の意味変更 |
| **D22** | P4 backfill の集合同値検証 | migration テストで **SQL 更新 ID 集合 == 分類表 grandfather 対象 ID 集合**の双方向完全一致 | 分類表を文書で終わらせず機械検証に落とす |
| **D25** | subscription checkout の冪等 / 着地 feedback / 請求先 | **P9 へ切り出す**。ただし **`BillingCheckoutSession` テーブルは `state()` の Pending/ExpiredCheckout が読むため P2 に前倒す**（v2 で変更） | `OnboardingBillingState` を verbatim 移植する以上、当該テーブルは状態モデルの一部 |
| **D15** | JSON/XHR への 402 | 維持 | 既存 API/XHR クライアントの後退を避ける |
| **D16** | `Welcome.svelte` の `/register` 直リンク | **P7 で `/pricing` 誘導へ**（aigenba の Landing は直リンクを持たない） | F1 でプラン選択が必須関門になる以上、直リンクは矛盾 |
| **D17** | チケット単位 | 「枚」を維持 | AI-CUE 全体の既存語彙 |

### D28（新規・重要）: 月次チケット付与を廃止する — clamp 移植とセットでしか成立しない

**aigenba は月次付与を廃止済み**（`PlanSeeder`: 全 tier `included_monthly_tickets = 0`。施策8/v3
「**月次付与は廃止。チケットは都度購入 / オートリチャージ**」）。`CreditSource::PlanMonthly` で行が入るのは
**signup grant の 10 枚のみ**（30 日期限・org 生涯 1 回）。

**AI-CUE は月次付与が生きている**（`PlanSeeder.monthly_ticket_grant`: free 10 / standard 100。
`StripeWebhookProcessor::grantMonthlyTickets()` が `invoice.paid` で発火）。**課金モデルの根本が違う**。

**これは per-source clamp の移植と不可分**: aigenba 側の「clamp は現行モデルでは実質 no-op（債務の逃げ道になる
生きた source が無い）」という判断は、**「月次付与が廃止済み」という前提に立つ**。**月次付与を残したまま clamp だけ
移植すると、aigenba では死んでいる経路が AI-CUE では生きる**（月次が債務の逃げ道になる）。

**決定: parity 方針に従い AI-CUE も月次付与を廃止する。**
- `database/seeders/PlanSeeder.php`: **全 tier の `monthly_ticket_grant` を 0** にする。
- コード経路は**変更しない**。`grantMonthlyTickets()` は既存 guard `$plan->monthly_ticket_grant <= 0` で抜けるため、
  aigenba の `if ($count < 1) return;` と**同形**になる（`StripeWebhookProcessor.php:274`）。
- signup grant（10 枚・`TicketSource::Monthly`・30 日期限）は**据え置き**（aigenba と同一）。
- **プロダクト影響（明示）**: standard の「月 100 枚」・free/personal の「月 10 枚」が**無くなる**。
  チケットは**都度購入 + オートリチャージのみ**になる。これは値の調整ではなく**モデルそのもの**であり、
  「全部揃える」= こうなる、という意味。
- 波及: `tests/Feature/Billing/TicketGrantTest.php`（月次付与の期待）/ `MonthlyGrantTest` 系 /
  `BughuntBillingSeeder` / `ManualTestSeeder`（dev シードの最低保証）/ 料金表の文言（「月 N 枚」表記があれば）。
  **既存テストは削除せず期待を更新**する。

### aigenba 側で CLOSED になった件（AI-CUE は verbatim 移植する）

私が aigenba へ報告した「返金債務が回収されない経路」は、**aigenba 側の実行検証で不成立と判定**された
（私の再現手順が消費優先 monthly→purchased と矛盾していた。詳細は `aigenba-handoff.md` の CLOSED 注記）。
先方は **当面この挙動を変更せず**、**verbatim 移植で問題なし・往復は発生しない**と回答。
`TicketRefundClawbackTest:147` の期待 **`-2` → `0`** への更新も先方確認済み。


## 施策一覧

| # | 施策名 | 主な変更ファイル | 優先度 | 単独マージ時の安全性 |
|---|--------|------------|--------|---|
| **P1** | プラン基盤（PlanCode / free plan・marker 列 / PersonalPlanService / seeder / backfill） | `app/Enums/PlanCode.php`, `app/Services/Billing/{PersonalPlanService,PlanPriceService}.php`, migrations ×2, `database/seeders/PlanSeeder.php`, `config/quota.php` | Critical | 挙動不変（ゲート未反転・列は additive） |
| **P2** | サブスク層 + **判定モデル**（SubscriptionService / SubscriptionSnapshot / **`OnboardingBillingState`(5 状態) + `BillingAccess::state()` を verbatim 移植** / **`BillingCheckoutSession` テーブル**（state() が読むため P9 から前倒し）） | `app/Services/Billing/{SubscriptionService,SubscriptionSnapshot,BillingAccess,BillingCustomerSynchronizer,BillingPermissionService}.php`, `app/Enums/Billing/OnboardingBillingState.php`, `app/Models/Billing/BillingCheckoutSession.php` + migration | Critical | 挙動不変（移行 OR で現行同値を維持） |
| **P3** | Onboarding 最小導線（**ゲート反転より前**に導線を実在させる = F-07 条件 A） | `app/Http/Controllers/Onboarding/*`, `resources/js/pages/Onboarding/{Checkout,BillingRequired}.svelte`, `routes/web.php` | Critical | 安全（導線が増えるだけ） |
| **P4** | **ゲート反転 + grandfathering 移行**（山場） | `app/Services/Billing/BillingAccess.php`, `app/Http/Middleware/RequireActiveSubscription.php`, backfill migration | Critical | 条件 A（P3）+ 条件 B（backfill）を満たして初めて安全 |
| **P5** | チケット残高会計の精緻化（per-bucket / per-source 失効 / 消費優先 / commit-wins） | `app/Services/Billing/TicketLedgerService.php`, `app/DataTransferObjects/Billing/TicketBalanceDto.php`, additive 列 | High | 安全（additive 列 + 読み取り計算） |
| **P6** | signup grant 契機変更（F2）+ **LP 文言** | `app/Actions/Fortify/CreateNewUser.php`, `app/Services/Billing/{PersonalPlanService,StripeWebhookProcessor}.php`, `resources/js/pages/Welcome.svelte` | High | 安全（marker は P1 で導入済み） |
| **P7** | 新規登録経路（IntendedPlanResolver / continuation / `?plan=` handoff） | `app/Services/Onboarding/*`, `app/Support/Auth/EmailVerificationContinuation.php`, `app/Providers/FortifyServiceProvider.php` | Medium | 安全（導線の質向上） |
| **P8a** | 裏チャージ（オートリチャージ + リコンサイル） | `app/DataTransferObjects/Billing/AutoRecharge*`, `app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php`, migration | Medium | 安全（opt-in・既定 off） |
| **P8b** | 課金 UI parity + 監査の判断不要 15 件（**D25: checkout feedback / billingContact は除く**） | `resources/js/pages/{Guest/Pricing,Billing/Plans,Billing/Index,Billing/PurchaseTickets}.svelte`, `_helpers/PlanCard.svelte` | Medium | 安全（UI のみ） |
| **P9** | サブスク checkout の冪等配線・着地 feedback + 請求先情報（**D25**。**`BillingCheckoutSession` テーブル自体は P2 へ前倒し済み**） | `subscriptionAttemptToken` の冪等状態機械, `resolveBillingFeedback`, billing contact 列（**email/name とも CipherSweet**）+ 更新 Action, `Billing/Index` の feedback バナー | Low | 安全（追加機能） |

**依存順（交渉不可）**: `P1 → P2 → P3 → P4`（導線 → ゲート反転）。以降 `P5 → P6`（marker 前提）、`P7`、`P8a → P8b`、`P9`（P8b の後）。
**実装 TODO は 10 本**（P1/P2/P3/P4/P5/P6/P7/P8a/P8b/P9）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone**（全フェーズ） |
| 判断根拠 | 金銭ドメイン・課金ゲート・約60 テストファイルへの波及を含み、各フェーズが複数コンポーネントの協調変更になる。並行実装は移行順序（導線 → ゲート反転）の不変条件を壊す危険がある |
| 競合リスク | フェーズ間は**直列前提**。P1 の marker/列を P4・P5・P6 が参照するため、順序を崩すと F-07 再発（P3→P4 逆転）または二重付与（P1 未了で P6）が起きる |

---

# 各施策の詳細

### P1 プラン基盤（PlanCode 5 case / plans.is_active / D28 月次付与廃止 / free plan・signup marker 列 / PersonalPlanService / marker backfill）

**DoD**: **ゲートは反転しない**。`BillingAccess::hasActiveAccess()` は無改変で、既存の業務ルート到達性・`RequireActiveSubscriptionMiddlewareTest` / `SeededFreePlanBillingAccessTest` の期待は変わらない。`organizations` への列追加はすべて additive（既存行を書き換えない）。`PersonalPlanService::activate()` は **P1 で完成**させる（marker claim + grant を含む）が、まだどの route からも呼ばれない（配線は P3）。signup grant の付与契機は移行期規約に従い **P6 まで登録時を維持**する。
**P1 で意図的に変わる挙動（v2 で明示）**: (a) `PlanSeeder` に personal / starter が `is_active=true` で加わり `/pricing`・`/billing` のプラン件数が 2 → 4 になる、(b) **D28** により全 tier の `monthly_ticket_grant` が 0 になり `invoice.paid` の月次付与行が seed 既定では生成されなくなる（コード経路は不変）、(c) 料金表・課金ページの「月 N 枚のチケット付与」表示が廃止される。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| AI-CUE | 内容 | 移植元 |
|---|---|---|
| `app/Enums/PlanCode.php`（新規） | **verbatim 5 case**: `Personal='personal' / Starter='starter' / Standard='standard' / Business='business' / Enterprise='enterprise'`。`requiresStripeCheckout()` も verbatim（Starter/Standard/Business=true、Personal/Enterprise=false）。**`isSeatFixed()` は移植しない**（AI-CUE に席概念・`plans.included_seats` が無い = 原則 4）。Business / Enterprise は Plan 行を持たないが enum の case は縮小しない | `/tmp/aigenba/app/Enums/PlanCode.php` |
| `database/migrations/2026_07_17_000100_add_free_plan_and_signup_grant_marker_to_organizations.php`（新規） | **verbatim**: `free_plan_code`(string 32 nullable) / `free_plan_activated_at` / `personal_declared_at` / `personal_declared_by_user_id`(FK users nullOnDelete) / `signup_tickets_granted_at` + `index('free_plan_code')` + raw partial unique index。`down()` も verbatim | `/tmp/aigenba/database/migrations/2026_07_08_113500_add_free_plan_and_signup_grant_marker_to_organizations.php` |
| `database/migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php`（新規） | **verbatim の data migration（列追加とは別 migration に分離）**。`ticket_ledger_entries.idempotency_key LIKE 'signup_grant:%'` を持つ org に `min(granted_at)` で marker を立てる。`whereNull` ガードで冪等、`down()` は意図的 no-op。AI-CUE の実スキーマ（`ticket_ledger_entries` / `organization_id` / `idempotency_key` / `granted_at`。`2026_06_11_091400_create_ticket_tables.php:37-61`）と完全一致 | `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php` |
| `database/migrations/2026_07_17_000120_add_is_active_to_plans.php`（新規） | `plans.is_active`(boolean NOT NULL default **true**) を追加。既存 `free` / `standard` 行は default で true になり公開状態は変わらない | aigenba `create_plans_table.php` の `is_active` |
| `app/Models/Billing/Plan.php` | `is_active` を `$fillable` と `casts()`（`boolean`）へ追加、`@property bool $is_active` を docblock へ | `/tmp/aigenba/app/Models/Billing/Plan.php` |
| `app/Services/Marketing/PricingService.php` | `listPublicPlans()` に `->where('is_active', true)` を追加（公開制御の唯一の場所）。`PricingPlanDto` から `monthlyTicketGrant` を落とす（D28。L51） | aigenba の `plans.is_active` 露出制御 |
| `database/seeders/PlanSeeder.php` | **personal**（Price 無し・`sort_order=1`）と **starter**（base ¥980・`sort_order=2`）を `updateOrCreate` で追加し、`standard` の `sort_order` を 3 へ（aigenba SPECS の tier 順 personal → starter → standard に一致させる。`free` は `sort_order=0` のまま P4 まで残置）。**D28: 全 tier の `monthly_ticket_grant` を 0**（free 10→0 / standard 100→0 / personal 0 / starter 0）。`is_active` は属性配列に入れず **`wasRecentlyCreated` のときのみ `true` を確定**（aigenba verbatim。運用者の手動変更を seed 再実行で踏み潰さない。既存 free / standard 行は migration default の true が残る）。`PlanCode::from($code)` の membership assert は **P1 では入れない**（`free` 行が P4 まで残り ValueError になるため。P4 の free 撤去と同時に導入する） | `/tmp/aigenba/database/seeders/PlanSeeder.php`（SPECS の personal / starter・`included_monthly_tickets=0`・`wasRecentlyCreated` の is_active 確定・Personal の Price skip） |
| `app/Support/Billing/StripePriceLookupKeys.php` + `stripe/fixtures/plan_starter.json`（新規） | `CATALOG` に `'starter' => [PlanPriceKind::Base]` を追加し、fixture（product + `unit_amount=980` / `lookup_key='app_starter_base'` / `recurring.interval=month`）を**同一 PR で**追加（`StripePriceCatalogFixtureInvariantTest` が lookup_key の集合一致を強制）。**personal は Price を投入しない**（`requiresStripeCheckout()=false` = aigenba の Personal skip 規約と同値） | `/tmp/aigenba/database/seeders/PlanSeeder.php` の `in_array($code, ['enterprise','personal'], true)` skip |
| `config/quota.php` | `plans` に **`personal`**（`max_projects=1` / `max_members=3` / `max_storage_bytes=1GiB`）と **`starter`**（personal と同値）を追加。値の根拠: aigenba では personal と starter の能力値が同一（`included_seats=3` / `scenario_limit=10` / `course_limit=5`）で、AI-CUE の該当 tier は現行 `free`（1 / 3 / 1GiB）。`max_members=3` は aigenba `included_seats=3` と一致。`fallback_plan` は **`'free'` のまま**（撤去は P4）。**未追加だと `QuotaService.php:33` の `?? []` で無制限へ silent 退行する** | 原則 3（値を憶測でいじらない） |
| `app/Services/Billing/PersonalPlanService.php`（新規） | `eligibility()` / `activate()` / `retireForPaidSubscription()` / `hasOtherActiveFreePersonalOrg()` / `isDeclarerUniqueViolation()` を verbatim 移植。**`activate()` は P1 で完成**（org 行 `lockForUpdate()` → eligibility 再検証 → `forceFill` → marker の条件付き先取 → 先取できた場合のみ `grantSignupGrant` を同一 transaction で）。`QueryException` の declarer unique 違反は `PersonalPlanNotEligibleException(AlreadyHasFreePersonalOrg)` へ変換し **並行 activate の後着を 500 にしない** | `/tmp/aigenba/app/Services/Billing/PersonalPlanService.php` |
| `app/DataTransferObjects/Billing/PersonalPlanActivationResultDto.php` / `PersonalPlanEligibilityDto.php`（新規） | verbatim（`@phpstan-type PersonalPlanEligibilityShape` 込み） | 同名 aigenba ファイル |
| `app/Enums/Billing/PersonalPlanIneligibleReason.php`（新規） | verbatim（`HasEntitledSubscription` / `TooManyMembers` / `AlreadyHasFreePersonalOrg` + `label()` の日本語文言） | `/tmp/aigenba/app/Enums/Billing/PersonalPlanIneligibleReason.php` |
| `app/Exceptions/Billing/PersonalPlanNotEligibleException.php`（新規） | verbatim（`userMessage()`）。422 への変換は P3（Controller 層） | 同名 aigenba ファイル |
| `app/Services/Billing/PlanPriceService.php`（新規） | `replaceCurrent()` を移植。AI-CUE の `plan_prices` は `lookup_key` を持ち `SyncStripePrices.php:78-87` が「kind + is_current + lookup_key 一致」の current 行を要求するため **`?string $lookupKey = null` を追加**（D14。それ以外は verbatim。`is_current ⇔ active_to IS NULL` の CHECK は「旧 current 無効化 → 新規作成」の順序で満たされる） | `/tmp/aigenba/app/Services/Billing/PlanPriceService.php` |
| `app/Models/Organization.php` | 新 5 列を `casts()` に追加（timestamp 4 本は `immutable_datetime` / `personal_declared_by_user_id` は `integer`）。`$fillable` は不変（書き込みは `PersonalPlanService` の `forceFill` 経由のみ）。docblock に「free entitlement は `free_plan_code` 側で表現する」を追記。**`plan_code=null=free tier` の既存記述は P4 まで有効なので消さない** | aigenba `Organization` |
| `app/Support/Security/MassAssignmentProtectedKeys.php` | actor キーとして `'personal_declared_by_user_id'` を追加（`MassAssignmentSafetyTest` が `$fillable` 不含を検証する） | 不変条件 #1 |
| `app/Services/Billing/TicketLedgerService.php` | `grantSignupGrant(Organization $organization, string $idempotencyKey): void` へ**シグネチャ変更**（内部生成キーをやめ、呼び出し側が `signup_grant:org:{orgId}` / `signup_grant:personal:{orgId}` を渡す）。枚数・期限・`insertOrIgnore` の冪等は不変。`grantMonthly()` は無改変 | `/tmp/aigenba/app/Services/Billing/TicketService.php:261` |
| `app/Actions/Fortify/CreateNewUser.php`（L106） | **移行期規約**: 既存の登録 tx 内で `PersonalPlanService::claimSignupGrantMarker($org)` を呼び、**先取できたときのみ** `grantSignupGrant($org, "signup_grant:org:{$id}")`。org 行 `lockForUpdate()` 下・同一 tx。**付与契機・枚数・「招待経由は非付与」の現行挙動は不変**（marker を同時に立てるだけ） | 概念設計 §signup grant の冪等移行 規約 |
| `app/Services/Billing/StripeWebhookProcessor.php`（L270） | `grantSignupGrant($organization)` → `grantSignupGrant($organization, "signup_grant:org:{$organizationId}")` の**引数適合のみ**。`grantMonthlyTickets()` の既存 guard `$plan->monthly_ticket_grant <= 0`（L274）が **D28 で常に成立** = aigenba の `if ($count < 1) return;` と同形になる。**コードは変更しない**。paid 経路の marker claim ブロック追加は P6 | D28 |
| `resources/js/pages/Pricing.svelte`（L29） / `resources/js/pages/Billing/Index.svelte`（L154） | **D28 波及**: 「月 {N} 枚のチケット付与」の feature 行 / 表示を削除（`monthly_ticket_grant=0` で「月 0 枚」と表示されるのは虚偽。後方互換の並走を残さない = 思考原則 3）。signup grant 10 枚・チケット都度購入の説明は既存 FAQ / `signupGrantTickets` props がそのまま担う | D28 |

**移植時の adaptation（名前解決と既存契約への適合のみ。意味論は不変）**

- `TicketService` → `TicketLedgerService`。
- `Role::OrganizationOwner` → `App\Enums\OrganizationRole::Owner`（値 `organization_owner`）。laratrust pivot は AI-CUE も `role_user` + `role_user.team_id`（`config/laratrust.php:151`）のため `hasOtherActiveFreePersonalOrg()` の `whereColumn('role_user.team_id', 'organizations.laratrust_team_id')` はそのまま成立する。
- `hasEntitledSubscription()`: P2 の `SubscriptionService::deriveEntitlement()` が未着のため、P1 は `subscription('default')?->stripe_status ∈ {active, trialing}`（= 現行 `BillingAccess::GRANTING_STATUSES` と同値）で実装し、docblock に **P2 で `deriveEntitlement()` へ差し替える seam** と明記する。
- `MAX_MEMBERS = 3`（aigenba verbatim。`config/quota.php` の `personal.max_members=3` と一致。invariant テストで固定）。
- `claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool` を **移行期は public**（aigenba では `activate()` 内 private）。移行専用 API である旨を docblock に明記し、**P6 で private へ戻す**（D13）。

#### 波及変更

- **TypeScript 型定義**:
  - `resources/js/types/marketing.ts`（L26） — `PricingPlanShape.monthlyTicketGrant` を削除（D28）。
  - `resources/js/pages/Billing/Index.svelte`（L28） — ローカル props 型から `monthlyTicketGrant: number` を削除。
- **DTO / JsonResource**:
  - `app/DataTransferObjects/Marketing/PricingPlanDto.php`（L22 の `@phpstan-type` / L34 の promoted property / L49 の `toArray()`）— `monthlyTicketGrant` を削除。
  - 新規 `PersonalPlanActivationResultDto` / `PersonalPlanEligibilityDto`（P1 時点では Service 戻り値のみ。Controller からは返さない）。
- **Inertia props**:
  - `/pricing`（`page.plans`）— 件数 2 → **4**（free / personal / starter / standard の sort_order 昇順）、各要素から `monthlyTicketGrant` が消える。
  - `/billing`（`plans`）— 件数 2 → **4**、`monthlyTicketGrant` が消える（`BillingController.php:50`）。`currentPlanCode` / `ticketBalance` / `canManageBilling` は不変（`plan_code` の raw 読みの解消は P2）。
- **Factory**: `database/factories/OrganizationFactory.php` に `freePersonal(User $declarer)`（`free_plan_code='personal'` + declared_*）/ `grandfathered()`（declarer NULL）/ `signupGranted()` state を追加（テストデータ手組み禁止のため）。
- **Filament**: `app/Filament/Resources/PlanResource.php` / `Plans/Pages/EditPlan.php` — **変更なし**（`monthly_ticket_grant` 列とコード経路は D28 でも残すため、管理画面の編集口もそのまま）。
- **Seeder**:
  - `database/seeders/BughuntBillingSeeder.php` — **変更不要**（`plan.monthly_ticket_grant` を読まず `grantMonthly($org, 100, null, "bughunt:initial-grant:{id}", …)` で直接付与しているため、D28 でも探索用残高は維持される）。ただし `paidPlanCodes()`（base Price を持つプラン）が starter を含むようになり、対象組織が増える（意図どおり）。
  - `database/seeders/ManualTestSeeder.php` — **変更不要**（`TEST_TICKETS` を `TicketLedgerService::grant()` で直接付与するため dev シードの最低保証は D28 の影響を受けない）。プラン行増加により「パーソナルプラン組織」「スタータープラン組織」が生成される（starter は base Price を持つため plan_code + fake active subscription が付く = 既存不変条件どおり）。
- **テストファイル（更新。削除しない）**:
  - `tests/Feature/Billing/TicketGrantTest.php`（L82-137） — `grantSignupGrant` 呼び出しに `$idempotencyKey` 引数を追加（枚数・期限・冪等の期待は不変）。
  - `tests/Feature/Billing/WebhookIdempotencyTest.php`（L93-100） — **D28**: seed 既定では月次付与が 0 になるため、arrange で `standard` の `monthly_ticket_grant` を 100 に設定してから「monthly:{invoiceId} の冪等」を検証する形へ更新（コード経路の検証を維持）。付与 1 回の期待値・entries 数を更新。
  - `tests/Feature/Billing/InvoiceLinePricingShapeTest.php`（L79-95） — **D28**: 同様に arrange で `monthly_ticket_grant=100` を設定。`pricing.price_details.price` / 旧 `price.id` の形状解決という関心は不変。
  - `tests/Feature/Marketing/PricingPageTest.php`（L29-47） — plans 件数 2 → 4、index を sort_order（free / personal / starter / standard）へ、`monthlyTicketGrant` の期待（10 / 100）を削除、personal（`baseAmountJpy=null`・1/3/1）と starter（`baseAmountJpy=980`・1/3/1）の期待を追加。
  - `tests/Feature/Billing/BillingPageTest.php`（L26-33） — `has('plans', 2)` → 4、index 更新、`plans.*.monthlyTicketGrant` の期待を削除。
  - `tests/js/pages/Pricing.test.ts`（L17-28） — fixture から `monthlyTicketGrant` を削除、「月 N 枚」行の期待を削除。
  - `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`（L21-26 の `seededFreePlan()`） — 「current base Price を持たない最初の Plan」を拾う実装が personal 追加で非決定になるため `code='free'` 固定へ。**ゲート期待値は変えない**（未反転の証明）。
  - `tests/Feature/Billing/PlanSeederPriceInvariantTest.php` — 「有償プラン starter は current base Price を持つ」「personal は Price を持たない（`prices()->count()===0`）」を追加。free / standard の既存期待は維持。
  - `tests/Feature/Billing/SyncStripePricesCommandTest.php` / `VerifyStripePricesCommandTest.php` — starter の fixture + lookup_key 追加を反映。
  - `tests/Feature/Auth/RegistrationTest.php`（L30） — 付与枚数の期待は不変。`signup_tickets_granted_at` が**同一 tx で立つ**期待を追加。
  - `tests/Architecture/MassAssignmentSafetyTest.php` / `StripePriceCatalogFixtureInvariantTest.php` / `QuotaKeyConfigInvariantTest.php` — コード変更不要（キー・fixture・config 追加で自動的に集合一致を検証）。
- **テストファイル（新規）**: `tests/Feature/Billing/PersonalPlanServiceTest.php` / `tests/Feature/Billing/SignupGrantOncePerOrgTest.php` / `tests/Feature/Billing/PlanActiveFilterTest.php` / `tests/Feature/Billing/PlanPriceServiceTest.php` / `tests/Feature/Billing/PlanQuotaCoverageInvariantTest.php` / `tests/Architecture/FreePlanCodeWriteInvariantTest.php`。

#### 主要な契約

```php
enum PlanCode: string {                                  // aigenba verbatim (5 case)
    case Personal = 'personal'; case Starter = 'starter'; case Standard = 'standard';
    case Business = 'business'; case Enterprise = 'enterprise';
    /** Personal は free (activate 経由)、Enterprise は営業導線のため checkout を通らない */
    public function requiresStripeCheckout(): bool;      // Starter|Standard|Business => true
}

final class PersonalPlanService {
    public const FREE_PLAN_CODE = 'personal';
    public const MAX_MEMBERS = 3;
    public function __construct(private readonly TicketLedgerService $tickets) {}
    public function eligibility(Organization $org, User $user): PersonalPlanEligibilityDto;
    /** org 行 lockForUpdate → eligibility 再検証 → forceFill → marker 先取 → 先取時のみ grant。全て同一 tx
     *  @throws PersonalPlanNotEligibleException 並行 activate の後着 (declarer unique 違反) を含む */
    public function activate(Organization $org, User $declarer): PersonalPlanActivationResultDto;
    public function retireForPaidSubscription(Organization $org): void;
    /** 移行期専用 public API (P6 で private 化)。org 行 lockForUpdate 下で marker を先取できたら true */
    public function claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool;
}

final readonly class PersonalPlanActivationResultDto { public function __construct(public bool $granted) {} }
final readonly class PersonalPlanEligibilityDto {  // eligible() / ineligible(reason) / toArray()
    public bool $eligible; public ?PersonalPlanIneligibleReason $reason; }
enum PersonalPlanIneligibleReason: string { HasEntitledSubscription | TooManyMembers | AlreadyHasFreePersonalOrg; }

class TicketLedgerService { public function grantSignupGrant(Organization $o, string $idempotencyKey): void; }

class PlanPriceService {   // D14: ?string $lookupKey のみ adaptation
    public function replaceCurrent(Plan $plan, PlanPriceKind $kind, string $stripePriceId, int $amount,
        ?string $lookupKey = null, string $currency = 'jpy', ?CarbonImmutable $activeFrom = null): PlanPrice;
}
```

`activate()` の中核（marker claim + grant を含む完成形。aigenba verbatim）:

```php
$fresh = Organization::query()->lockForUpdate()->findOrFail($org->id);
// … eligibility 再検証 → forceFill(free_plan_code / free_plan_activated_at / personal_declared_at / personal_declared_by_user_id)
$claimed = DB::table('organizations')->where('id', $fresh->id)
    ->whereNull('signup_tickets_granted_at')->update(['signup_tickets_granted_at' => $now]);
if ($claimed === 1) { $this->tickets->grantSignupGrant($fresh, 'signup_grant:personal:'.$fresh->id); }
return new PersonalPlanActivationResultDto(granted: $claimed === 1);
```

**DB（`organizations`。全て nullable / additive）**: `free_plan_code varchar(32)` + `index`、`free_plan_activated_at ts`、`personal_declared_at ts`、`personal_declared_by_user_id → users.id (nullOnDelete)`、`signup_tickets_granted_at ts`。
**partial unique index（aigenba verbatim・改変禁止）**:
```sql
CREATE UNIQUE INDEX organizations_personal_free_declarer_unique
ON organizations (personal_declared_by_user_id)
WHERE free_plan_code = 'personal' AND personal_declared_by_user_id IS NOT NULL
```
→ declarer NULL 行（P4 の grandfathered backfill）は **index 対象外**。
**DB（`plans`）**: `is_active boolean NOT NULL DEFAULT true`。
**seed 後の plans（P1 時点）**: `free`(0, grant 0, Price 無, is_active=true) / `personal`(1, grant 0, Price 無, true) / `starter`(2, grant 0, base ¥980 `app_starter_base`, true) / `standard`(3, grant 0, base ¥4,980, true)。
**冪等キー**: `signup_grant:org:{orgId}`（登録経路 = 移行期）/ `signup_grant:personal:{orgId}`（activate）。既存の partial unique `ticket_ledger_entries_signup_grant_unique ON (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'`（`2026_07_13_180622`）が**キー種別を跨いで org 生涯 1 回を DB 強制済み** → marker と 1:1 の二重防御。
**ルート**: 追加・変更なし（P3）。

#### PHPStan 適合チェック（level 10）

- `PlanCode` は 5 case で閉じ、`requiresStripeCheckout()` の `match` は網羅（default 不要）。Plan 行が無い Business / Enterprise も enum としては静的に閉じるため `alwaysFalse` は生じない（v1 の 3 case 縮小で発生した `identical` 常偽比較の懸念はここでは起きない）。
- `Organization::query()->lockForUpdate()->findOrFail($org->id)` は `Builder<Organization>` generics から `Organization` を返す（`@var` 不要）。`$org->id` は `Assert::integer()` で絞る（現行 `TicketLedgerService::grantSignupGrant` と同じ作法）。
- `DB::table('organizations')->…->update([...])` は `int` 戻り → `$claimed === 1` は型安全。
- `QueryException::getCode()` は `Throwable::getCode()` の宣言上 `int` だが PDO 由来は string を返すため、`in_array((string) $e->getCode(), ['23000','23505'], true)` とする（キャストによる正規化。禁止事項 2 の widen ではない）。
- `PersonalPlanEligibilityDto::toArray()` は `@phpstan-type PersonalPlanEligibilityShape array{eligible: bool, reason: string|null, reasonLabel: string|null}` で形状固定（aigenba verbatim）。
- `hasOtherActiveFreePersonalOrg()` のクロージャ引数は `Illuminate\Database\Eloquent\Builder $q` を型注釈。戻りは `exists()` の `bool`。
- `Plan::updateOrCreate()` は `Plan` を返し `$plan->wasRecentlyCreated` は `bool`、`$plan->is_active = true` は `@property bool $is_active` で型解決される。
- `PricingPlanDto` の `@phpstan-type PricingPlanShape` から `monthlyTicketGrant: int` を削除し、`PricingPageDto` 側の配列形状と一致させる（片方だけ消すと `array{...}` 不一致で level 10 が落ちる）。
- 新 casts は `protected function casts(): array` の `array<string, string>` 契約内（`immutable_datetime` / `integer` / `boolean`）。
- `config()` 参照は `config()->string()` / `config()->array()` か `Assert::integer()` 経由（既存 `grantSignupGrant` / `PricingService` の作法を踏襲）。
- `response()->json()` の直書きは無し（P1 は Controller のロジックを増やさない）。

#### テスト計画（テストファースト）

**先に red を作る（新規）**

1. `tests/Feature/Billing/PersonalPlanServiceTest.php`
   - `activate()` が `free_plan_code='personal'` / `free_plan_activated_at` / `personal_declared_at` / `personal_declared_by_user_id` を埋め、`signup_tickets_granted_at` を立て、`config('billing.signup_grant_tickets')` 枚を **1 回だけ**付与し `granted=true` を返す（**activate が P1 で完成している**ことの固定）。
   - 同一 org の再 `activate()` は `granted=false` かつ**残高不変**（marker 先取が 0 件）。
   - `eligibility()` の 3 理由: 有効 subscription あり（active/trialing）/ メンバー 4 名 / 同一 declarer の別 free personal org。
   - **並行 activate の後着**: 同一 declarer で別 org を `activate()` → partial unique 違反が `PersonalPlanNotEligibleException(AlreadyHasFreePersonalOrg)` に変換され **`QueryException` が漏れない（= 500 にしない）**。
   - `retireForPaidSubscription()` の冪等（2 回目 no-op。`personal_declared_*` は監査証跡として残る）。
2. `tests/Feature/Billing/SignupGrantOncePerOrgTest.php`（**P1 の要**）
   - **free activate ↔ paid webhook の競合で二重付与しない**: activate 済み org に `invoice.paid (billing_reason=subscription_create)` → 付与 0（signup ledger 行は 1 のまま）。逆順（paid 成立済み org を activate）は `eligibility()` の `HasEntitledSubscription` で弾かれ付与 0。
   - **移行期回帰（必須）**: 移行期に `CreateNewUser` 経由で登録された org（marker 済み）を `activate()` に掛けても再付与されない（`granted=false`・残高不変）。
   - **backfill migration**: 既存 `signup_grant:org:{id}` 履歴のある org は marker が `min(granted_at)` で立ち、履歴の無い org は null のまま。再実行しても値が動かない（冪等）。
3. `tests/Feature/Billing/PlanActiveFilterTest.php` — `is_active=false` の Plan は `/pricing` の props に出ない / `is_active=true` の Plan は出る。あわせて **seed 直後は 4 プランすべて `is_active=true`**（aigenba verbatim の公開方針）を固定。
4. `tests/Feature/Billing/PlanQuotaCoverageInvariantTest.php` — **全 `Plan.code` が `config('quota.plans')` に存在する**（`QuotaService.php:33` の `?? []` による無制限 silent 退行の機械検知）+ `PersonalPlanService::MAX_MEMBERS === config('quota.plans.personal.max_members')`。
5. `tests/Feature/Billing/PlanPriceServiceTest.php` — `replaceCurrent()` が旧 current を `is_current=false` + `active_to` 設定で閉じ、新 current を `lookup_key` 付きで作る（CHECK 制約と `SyncStripePrices` の「kind + is_current + lookup_key 一致」検索が成立することを固定）。
6. `tests/Architecture/FreePlanCodeWriteInvariantTest.php` — `app/` 内の `free_plan_code` 書き込みは `PersonalPlanService` に限定（aigenba 同名を移植）。
7. **D28 の固定（新規テスト）**: `WebhookIdempotencyTest` に「seed 既定（`monthly_ticket_grant=0`）では `invoice.paid` で `monthly:%` 行が 1 件も作られない（signup grant のみ）」を追加。`PlanSeeder` の全 tier `monthly_ticket_grant === 0` を `PlanSeederPriceInvariantTest` に追加。

**既存テストの更新（削除しない）**: `tests/Feature/Billing/TicketGrantTest.php` / `WebhookIdempotencyTest.php` / `InvoiceLinePricingShapeTest.php` / `SeededFreePlanBillingAccessTest.php` / `PlanSeederPriceInvariantTest.php` / `BillingPageTest.php` / `SyncStripePricesCommandTest.php` / `VerifyStripePricesCommandTest.php` / `tests/Feature/Marketing/PricingPageTest.php` / `tests/Feature/Auth/RegistrationTest.php` / `tests/js/pages/Pricing.test.ts`。

**挙動不変の固定（回帰。期待値を変えない）**: `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php` / `SeededFreePlanBillingAccessTest.php`（ゲート未反転の証明）/ `tests/Feature/Billing/QuotaTest.php` / `QuotaCheckAdditionTest.php`（既存 free / standard の limits 不変）/ `tests/Feature/Auth/RegistrationInvitationPrefillTest.php`（招待経由は非付与）/ `tests/js/pages/Welcome.test.ts`（`Welcome.svelte` の文言は P1 では触らない。付与契機は登録時のままで文言は依然事実。修正は P6）。

#### リスク

| リスク | 緩和 |
|---|---|
| **D28 で月次付与が消え、既存 standard 契約組織の毎月 100 枚が無くなる**（プロダクト影響そのもの） | 「値の調整」ではなく **aigenba の課金モデルへの一致**（都度購入 + オートリチャージ）という決定であることを D28 に明記済み。チケット都度購入（`/billing/tickets`）と signup grant 10 枚は据置で、残高ゼロ詰みは P8a のオートリチャージが引き取る。料金表・課金ページの「月 N 枚」表示を同一 PR で撤去し、虚偽表示の期間を作らない |
| `config/quota.php` に personal / starter を入れ忘れると `QuotaService.php:33` の `?? []` で**無制限に silent 退行**する | 同 PR で limits を追加し、`PlanQuotaCoverageInvariantTest`（全 `Plan.code` ⊆ `quota.plans`）で機械検証（`QuotaKeyConfigInvariantTest` と同格） |
| **personal が `is_active=true` で `/pricing` に出るのに activate 導線は P3 まで無い** | `/pricing` の CTA は現行どおり `/register`（P1 では変更しない）で、personal カードも同じ導線に着地する = 詰みは発生しない。activate-personal への誘導は P3、`?plan=` handoff は P7。P1 → P3 は直列前提（依存順は交渉不可） |
| `starter` を公開しても Stripe 実 Price が未 sync だと checkout が失敗する | fixture（`plan_starter.json`）+ `StripePriceLookupKeys` を同一 PR で追加し `StripePriceCatalogFixtureInvariantTest` が集合一致を強制。実 Price 反映は既存運用の `billing:sync-stripe-prices`（bootstrap 行 `price_test_app_starter_base` は `livemode=false` / `synced_at=null` のまま）。checkout 時の未 sync は既存の `back()->with('error', …)` 経路で 500 にならない |
| `grantSignupGrant` のシグネチャ変更で呼び出し漏れ | 呼び出し元は 2 箇所のみ（`CreateNewUser.php:106` / `StripeWebhookProcessor.php:270`）。引数を必須にすることで PHPStan level 10 が漏れを静的検出する |
| 移行期の marker claim を入れ忘れた org が P6 後に再付与される | `SignupGrantOncePerOrgTest` の移行期回帰 + `ticket_ledger_entries_signup_grant_unique` の DB 二重防御（キー種別を跨いで org 生涯 1 回） |
| P1〜P5 の paid webhook 経路は marker を立てないため、当該経路のみで付与された org が marker 無しで残る | 二重付与は `ticket_ledger_entries_signup_grant_unique` が DB レベルで阻止するため**金銭的影響は無い**（残高不変）。当該経路は **P6 (b) の claim+grant ブロック追加**で閉じる |
| backfill 対象 org に `signup_grant:%` が複数行あると `min(granted_at)` が曖昧 | `2026_07_13_180622` が「重複あれば fail-closed」で既に導入済みのため、本番に重複行は存在し得ない |
| `PlanPriceService` が P1 時点で呼び出し元なし | 移植方針上は許容（P2 / 価格改定運用で使用）。`PlanPriceServiceTest` で生存を確保。`?string $lookupKey` を落とすと `SyncStripePrices.php:78-87` が current 行を見失う |
| `free` と `personal` が並存し、`ManualTestSeeder` 由来の組織が 2 つ増える（`seededFreePlan()` の対象が非決定になる） | 手動テスト用途のみで本番影響なし。`SeededFreePlanBillingAccessTest` は `code='free'` 固定で解消。`free` 行と `fallback_plan='free'` の撤去は P4 |
| `PlanCode` に Plan 行を持たない Business / Enterprise が残る | verbatim 移植の帰結（原則 1）。`PlanCode::from()` の membership assert は free 撤去後（P4）に導入するため、P1 で ValueError にはならない。逆向き（全 `Plan.code` ⊆ `PlanCode`）の invariant も P4 で入れる |
| partial unique index は pgsql / sqlite 前提（MySQL 非対応） | 既存 `2026_07_13_180622` が同じ前提を driver チェック + fail-closed で明示済み。本番 / CI とも該当ドライバのみ |

---

### P2 サブスク層: `OnboardingBillingState` / `BillingAccess::state()` の verbatim 移植と SubscriptionService / Gateway 置換

前提: P1 で `App\Enums\PlanCode`（Personal / Starter / Standard / Business / Enterprise の 5 case・`requiresStripeCheckout()`）/ `PersonalPlanService`（`FREE_PLAN_CODE='personal'`）/ `organizations.{free_plan_code, free_plan_activated_at, personal_declared_at, personal_declared_by_user_id, signup_tickets_granted_at}` + partial unique index / `PlanPriceService` / `plans.is_active` が入っている。

**DoD**: `hasActiveAccess()` の結論は **past_due 以外の全ケースで現行と同値**。migration は additive のみ（`billing_checkout_sessions` 新規 + `subscriptions.has_payment_method` 追加 + その backfill）。route 変更ゼロ・Inertia props 変更ゼロ。

**同値性の担保（3 点。ここが本フェーズの核心）**

1. **`BillingAccess::state()` は aigenba verbatim**（`plan_code` を一切見ない）。一方 AI-CUE の現行 `hasActiveAccess()` は「`plan_code === null` = 支払い不要 free tier として許可」という**意図的な逸脱**を持つ。両立は **`hasActiveAccess()` 側に移行期の OR を 1 行だけ置く**ことで行う（`state()` は汚さない）:

   ```php
   public function hasActiveAccess(Organization $org): bool
   {
       if ($this->state($org)->grantsAccess()) {
           return true;
       }
       // 移行期 (P4 で削除する 1 行): 現行の意図的な free 許可 = plan_code null を通す。
       // P4 で grandfathering backfill (free_plan_code='personal') が ActiveFreePlan を成立させ、
       // 本行を消すことがゲート反転そのものになる。
       return $org->plan_code === null;
   }
   ```
   `free_plan_code` を立てる writer は P3（activate-personal 配線）/ P4（backfill）まで存在しないため、P2 時点で `ActiveFreePlan` へ落ちる org は 0 件。`BillingCheckoutSession` の writer も P2 では存在しない（行 0 件）ため `PendingCheckout` も発生せず、`state()` の実効レンジは `Subscribed` / `ExpiredCheckout` / `NoSubscription` に限られる。
2. **`plan_code` 非 null × 全 status の結論一致**: canceled / unpaid / incomplete / incomplete_expired → `Inactive`（`grantsAccess=false`）→ `ExpiredCheckout` → 移行 OR も非適用 → **遮断（現行同値）**。paused → `denied(Paused)` → `ExpiredCheckout` → **遮断（現行同値）**。行不在 → `NoSubscription` → **遮断（現行同値）**。active / trialing → `Subscribed` → **許可（現行同値）**。
3. **唯一の意図的な結論変更 = `past_due`**（現行: 遮断 / P2 以降: 許可）。これは指示どおり **aigenba の `deriveEntitlement` に従う**結果であり（`SubscriptionState::PastDue::grantsAccess()=true` = dunning 中も利用継続、PM 無し past_due のみ `trial_ends_at <= now && !has_payment_method` で遮断）、原則 1・原則 3 により AI-CUE 側で先回り修正しない。**既存テストは削除せず期待を更新**する（下記テスト計画）。trial 節は AI-CUE に trial 発行経路が存在しない（`trial_ends_at` を set するコードが `app/` に無い）ため実質不活性で、`has_payment_method` の backfill と webhook writer により既存有償 org が締め出されないことを保証する。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| ファイル (AI-CUE) | 何をするか | 移植元 (aigenba) |
|---|---|---|
| `app/Enums/Billing/OnboardingBillingState.php`（新規） | **verbatim**。`NoSubscription` / `PendingCheckout` / `ExpiredCheckout` / `Subscribed` / `ActiveFreePlan` の 5 case + `grantsAccess() = Subscribed \|\| ActiveFreePlan`。docblock も移植 | `/tmp/aigenba/app/Enums/Billing/OnboardingBillingState.php` |
| `app/Enums/CheckoutSessionStatus.php`（新規） | **verbatim**（`Pending` / `Completed` / `Failed` / `Expired`）。名前空間も verbatim（P1 の `app/Enums/PlanCode.php` と同じ配置規約） | `/tmp/aigenba/app/Enums/CheckoutSessionStatus.php` |
| `app/Enums/CheckoutIntent.php`（新規） | `SubscriptionStart='subscription_start'` / `SetupPaymentMethod='setup_payment_method'` の 2 case。`CreditPurchase` は AI-CUE では既存 `TicketCheckoutSession`（`app/Models/Billing/TicketCheckoutSession.php`）が担う別テーブル、`SignupFunding` は campaign / trial 機構（`signup_campaigns`）が AI-CUE に無いため移植しない（原則 4） | `/tmp/aigenba/app/Enums/CheckoutIntent.php` |
| `database/migrations/2026_07_17_000200_create_billing_checkout_sessions_table.php`（新規） | aigenba の 6 本（create + unit_amount + attempt_token + signup_funding + initiated_by + pm_reuse）を**新規 create 1 本に畳んで移植**。列: `id` / `organization_id`(FK cascade) / `initiated_by_user_id`(FK users nullOnDelete) / `intent`(32) / `plan_code`(32 nullable) / `stripe_session_id`(unique) / `idempotency_key`(128 unique) / `attempt_token`(nullable) / `checkout_url`(2048 nullable) / `status`(16 default 'pending') / `completed_at` / `timestamps`。index: `['organization_id','intent','status']` + unique `['organization_id','intent','attempt_token']`（名 `billing_checkout_sessions_org_intent_attempt_unique`）。**`seats`（席課金）/ `credit_count`・`unit_amount`（AI-CUE は ticket 側テーブル）/ `funding_choice`・`topup_count`・`applied_campaign_id`・`applied_trial_days`（campaign・trial 機構が無い）/ `pm_reuse_dispatched_at`（PM 再利用 job が無い）は移植しない**（原則 4。必要になった P8a/P9 で additive 追加） | `/tmp/aigenba/database/migrations/2026_04_14_011321_create_billing_checkout_sessions_table.php` ほか 5 本 |
| `app/Models/Billing/BillingCheckoutSession.php`（新規） | **verbatim**（移植列に限定）。`$fillable` / `casts` / `intentEnum()` / `statusEnum()` / `isReplayablePending()` / `organization()`。`@property` docblock も移植 | `/tmp/aigenba/app/Models/Billing/BillingCheckoutSession.php` |
| `database/factories/Billing/BillingCheckoutSessionFactory.php`（新規） | `pending()` / `expired()` / `failed()` / `completed()` / `forOrganization()` state。**新モデルには Factory を作る**規約（テストデータ手組み禁止） | `/tmp/aigenba/database/factories/Billing/BillingCheckoutSessionFactory.php` |
| `app/Enums/Billing/SubscriptionState.php`（新規） | `Active` / `UpgradeRecovery` / `PastDue` / `Paused` / `Inactive` の 5 case + `fromSubscription()` + `grantsAccess()`（`Active`・`UpgradeRecovery`・`PastDue` = true / `Paused`・`Inactive` = false）を移植。**`ScheduledForUpgrade` は入力列 `subscriptions.pending_plan_code` が AI-CUE に無いため移植しない**（原則 4。`upgrade_recovery_required` も同様に無いため当該分岐は落とし、`stripe_schedule_id !== null && schedule_setup_status === ScheduleSetupStatus::Created` の UpgradeRecovery 分岐のみ移植 = 両列は AI-CUE に実在）。`isTerminated` / `isTerminalStatus` / `TERMINATED_STRIPE_STATUSES` は P2 に呼び出し元が無い（AI-CUE の終了契機は `customer.subscription.deleted` のみ）ため移植しない | `/tmp/aigenba/app/Enums/Billing/SubscriptionState.php` |
| `app/Enums/Billing/EntitlementDeniedReason.php`（新規） | **verbatim**（`NoActiveSubscription` / `TrialEndedWithoutPaymentMethod` / `Paused`） | `/tmp/aigenba/app/Enums/Billing/EntitlementDeniedReason.php` |
| `app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php`（新規） | **verbatim**（`entitled` / `state` / `reason` + `granted()` / `denied()` / `toArray()` + `@phpstan-type EntitlementShape`） | `/tmp/aigenba/app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php` |
| `database/migrations/2026_07_17_000210_add_has_payment_method_to_subscriptions.php`（新規） | `subscriptions.has_payment_method`(boolean, default false, after `trial_ends_at`) を追加。**`deriveEntitlement` verbatim の入力**。同 migration の他 4 列（`trial_redeemed_at` / `applied_campaign_id` / `applied_trial_days` / `signup_initial_tickets_granted_at`）は campaign / trial 機構が無いため移植しない（原則 4） | `/tmp/aigenba/database/migrations/2026_06_25_090100_add_signup_trial_columns_to_subscriptions.php` |
| `database/migrations/2026_07_17_000220_backfill_has_payment_method_on_subscriptions.php`（新規） | data migration（列追加と分離）: 既存全 `subscriptions` 行を `has_payment_method = true` にする。**根拠**: AI-CUE の subscription 生成経路は `CashierSubscriptionCheckoutGateway::createSubscriptionCheckout`（`newSubscription()->checkout()` = mode=subscription）のみで PM 収集が必須のため、既存行の事実値は true。default false のまま放置すると trial 終了済み行が `deriveEntitlement` で締め出される（aigenba の default false は「trial 中カード無し signup」が存在する前提の値であり、その経路が無い AI-CUE では既存行に当てはまらない）。`down()` は意図的 no-op | — |
| `app/Models/Billing/Subscription.php` | `@property bool $has_payment_method` を docblock へ、`casts()` に `'has_payment_method' => 'boolean'` を追加。`$guarded = ['id','organization_id']` は不変（書込は `SubscriptionService` の `forceFill` / webhook 同期のみ） | `/tmp/aigenba/app/Models/Billing/Subscription.php:38,81` |
| `app/Services/Billing/SubscriptionSnapshot.php`（新規） | Stripe subscription の値オブジェクト。`stripeId` / `status` / `basePriceId` / `baseQuantity` / `currentPeriodEnd` / `trialEndsAt` / `endsAt`。**`currentPeriodStart` は `subscriptions.current_period_start` 列が AI-CUE に無い**ため持たず、period 巻き戻し guard も移植しない（列が無い = 移植対象が存在しない。原則 4）。`seatItemQuantity` も席概念が無いため持たない。schedule 状態を含めない契約（T666 C2）の docblock は移植 | `/tmp/aigenba/app/Services/Billing/SubscriptionSnapshot.php` |
| `app/Services/Billing/SubscriptionService.php`（新規） | サブスク層の中枢。`deriveEntitlement()`（**verbatim**）/ `applySubscriptionSnapshot()`（下記 adaptation）/ `recordPaymentMethodSnapshot()`（`recordFundingSnapshot` の PM 単独 subset。monotonic・`lockForUpdate` は verbatim）/ `assertStripeBillablePlan()`（**verbatim**）/ `assertPriceSynced()`（**verbatim**）/ `startCheckout()` / `createPortalSession()` / `resolvePlanCodeFromPriceId()`（**verbatim**）。Stripe I/O は Gateway 経由のみ。**`getStatus()` / `BillingStatusDto` は呼び出し側 UI が P8b 所管のため P2 では作らない**（dead code を作らない）。schedule lifecycle / seat / signup funding / changePlan / upgradeNow は非スコープ | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:56-155,195-420,457` |
| `app/Services/Billing/BillingAccess.php`（改修） | `state()` を **verbatim 移植**（`subscription('default')` → `deriveEntitlement($sub)->entitled` なら `Subscribed` / `free_plan_code === PersonalPlanService::FREE_PLAN_CODE` なら `ActiveFreePlan` / `$sub instanceof Subscription` なら `ExpiredCheckout` / `BillingCheckoutSession` の live pending なら `PendingCheckout` / stale pending・expired・failed があれば `ExpiredCheckout` / それ以外 `NoSubscription`。**read 経路で DB 書込をしない**契約・`CarbonImmutable::now()->subDay()` の閾値・in-memory stale 判定も verbatim）。`hasActiveAccess()` は `state()->grantsAccess()` + **P4 で削除する移行 OR 1 行**（`$org->plan_code === null`）。`GRANTING_STATUSES` 定数を撤去。ctor は `SubscriptionService` 注入（verbatim）。docblock は「課金判定の単一窓口」契約を維持しつつ移行 OR の削除期限（P4）を明記 | `/tmp/aigenba/app/Services/Billing/BillingAccess.php` |
| `app/Services/Billing/Contracts/StripeGatewayInterface.php`（新規。`app/Services/Billing/SubscriptionCheckoutGateway.php` を置換・削除） | 命名と名前空間のみ aigenba 形へ。**メソッドは 3 本に限定**（`createSubscriptionCheckout` / `createPortalSession` / `syncCustomerDetails`）。戻り値は AI-CUE の `ExternalBillingRedirect` を維持。**aigenba の 30+ メソッド単一 interface へは寄せず、AI-CUE の狭い gateway + チケット系 Gateway 分割の境界を維持**（AI-CUE の Gateway 規約） | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php`（命名のみ） |
| `app/Services/Billing/CashierStripeGateway.php`（`CashierSubscriptionCheckoutGateway.php` を rename） | 実装本体は現行のまま（`newSubscription('default',…)->checkout()` / `billingPortalUrl(…, PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')))`）。`portalRedirect` → `createPortalSession` へ改名し、`syncCustomerDetails()`（`$org->syncStripeCustomerDetails()`）を追加 | `/tmp/aigenba/app/Services/Billing/CashierStripeGateway.php` |
| `app/Services/Billing/Fakes/FakeStripeGateway.php`（`Fakes/FakeSubscriptionCheckoutGateway.php` を rename） | interface 変更へ追随。`FakeExternalUrl::neutralReturn` の中立帰還 URL 契約は不変。`syncCustomerDetails()` は **no-op**（fake 環境が実 Stripe を叩かない規約の維持） | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php:204,211` |
| `app/Services/Billing/BillingCustomerSynchronizer.php`（新規） | **verbatim**（`stripe_id === null` は no-op / `SyncBillingCustomerDetails::dispatch($org)->afterCommit()` / 「必ず `DB::transaction` の内側から呼ぶ」契約 docblock 込み） | `/tmp/aigenba/app/Services/Billing/BillingCustomerSynchronizer.php` |
| `app/Jobs/Billing/SyncBillingCustomerDetails.php`（新規） | `handle(StripeGatewayInterface $gateway)` → `$gateway->syncCustomerDetails($org)`。Cashier 標準 job を使わない理由（billable を trait 型で受けるため PHPStan level 10 で不一致）を移植元コメントごと持ち込む | `/tmp/aigenba/app/Jobs/Billing/SyncBillingCustomerDetails.php` |
| `app/Actions/Organizations/RenameOrganizationAction.php`（新規）+ `app/Http/Controllers/Organizations/OrganizationController.php:98-108`（改修） | Controller の update 内部を Action に抽出し、`DB::transaction` 内で `isDirty('name')` のときだけ `BillingCustomerSynchronizer::dispatchFor()`。**配線は rename 経路のみ**（aigenba の `UpdateBillingContactAction` は請求先列・更新 UI が AI-CUE に無いため P9 / laratrust team rename も AI-CUE に無い） | `/tmp/aigenba/app/Actions/Organizations/RenameOrganizationAction.php` |
| `app/Services/Billing/BillingPermissionService.php`（新規） | `grant` / `revoke` / `hasDirectPermission` / `getDirectManageBillingMap` + `ensureTeamId`（`Assert::integer($org->laratrust_team_id)`）/ `ensureMembership`（`DomainException`）を移植。permission 名は AI-CUE 規約（kebab）で `public const PERMISSION_MANAGE_BILLING = 'manage-billing'`（AI-CUE に `BillingPermission` enum は無く、同型先例 `app/Services/ApiKey/ApiKeyPermissionService.php` と同じ const 方式）。**`canEdit` / `canEditWithKnownRoles` は移植しない**（`App\Enums\OrganizationRole` に `level()` が無く、階層マトリクスは付与 UI 専用 = 本フェーズは **service + Policy の OR 参照のみ**） | `/tmp/aigenba/app/Services/Billing/BillingPermissionService.php` |
| `database/seeders/PermissionSeeder.php`（改修） | `permissions()` に `['name' => BillingPermissionService::PERMISSION_MANAGE_BILLING, 'display_name' => '請求・プラン管理']` を追加（`manage-api-keys` の隣。flat 付与モデルのため `RolePermissionSeeder` には登録しない） | — |
| `app/Policies/OrganizationPolicy.php:37 manageBilling`（改修） | `manageApiKeys`（同ファイル L48-60）と同型に: role null → false / `canManage()` → true / それ以外は `BillingPermissionService::hasDirectPermission()` を **OR 参照**。付与 route / UI は P2 に含めない = 直接付与行 0 件 = 認可の結論は現行と同一 | `/tmp/aigenba/app/Services/Billing/BillingPermissionService.php` の Policy 参照形 |
| `app/Http/Controllers/Billing/BillingController.php`（改修） | `SubscriptionCheckoutGateway` 直注入をやめ `SubscriptionService` へ委譲（`checkout` → `startCheckout()` / `portal` → `createPortalSession()`）。**`index` の props は一切変えない**（`currentPlanCode` を維持 = `getStatus()`/`BillingStatusDto` は P8b）。`startCheckout()` が投げる `StripePriceNotSyncedException` を catch し **現行と同一文言**の `back()->with('error', '選択したプランは現在お申し込みいただけません。')` を返す | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php`（Service 委譲の層構成） |
| `app/Exceptions/Billing/StripePriceNotSyncedException.php`（新規） | **verbatim**（`userMessage()`）。Controller が flash に使う（500 にしない） | `/tmp/aigenba/app/Exceptions/Billing/StripePriceNotSyncedException.php` |
| `app/Services/Billing/StripeWebhookProcessor.php`（改修。L180-329） | `syncPlanCode` / `clearPlanCode` / `syncSubscriptionPeriod` の**書込ロジックを `SubscriptionService::applySubscriptionSnapshot()` へ移設**。Processor の責務は payload → `SubscriptionSnapshot` の写像 + 組織解決（`resolveOrganization`）+ `subscriptionHasPaymentMethod($object)`（`default_payment_method` / `default_source` の有無。verbatim）→ `recordPaymentMethodSnapshot()` 呼び出しに縮む。**終了契機は現行どおり `customer.subscription.deleted` のみ**（`$terminated=true`）。反映条件（active/trialing のみ plan_code 同期・未知 Price は受理のみ・invoice / ticket 系分岐）は不変 | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:195-420`, `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:246-300` |
| `app/Providers/AppServiceProvider.php:22-26,110` / `app/Providers/FakeExternalsServiceProvider.php:10-13,80`（改修） | bind を `Contracts\StripeGatewayInterface → CashierStripeGateway` / fake は `FakeStripeGateway` へ更新 | `/tmp/aigenba/app/Providers/AppServiceProvider.php:103` |

**`applySubscriptionSnapshot()` の adaptation（意味論不変。列の所在差の吸収）**: aigenba は `subscriptions.plan_code` に書くが AI-CUE の権威は `organizations.plan_code`。よって単一 transaction 内で (a) `resolvePlanCodeFromPriceId($snap->basePriceId)` が解決でき **かつ** `status ∈ {active,trialing}` のときのみ `organizations.plan_code` を同期（未知 Price は受理のみ = 現行 `syncPlanCode` と同値）、(b) `subscriptions` 行が存在すれば `stripe_status` / `stripe_price` / `quantity` / `trial_ends_at` / `ends_at` / `current_period_end` を更新（**行の作成は Cashier `WebhookController` の責務** = 現行と同値。行不在なら period 更新のみ skip）、(c) `$terminated === true` のとき `organizations.plan_code = null`（現行 `clearPlanCode` と同値）+ `stripe_schedule_id = null` / `schedule_setup_status = ScheduleSetupStatus::None`（aigenba の終了時 schedule クリアのうち AI-CUE に実在する 2 列のみ）。seat drift / schedule drift / period 巻き戻し guard は対象列が無いため移植しない。

#### 波及変更

- **TypeScript 型定義**: **なし**。`resources/js/types/billing.ts` / `resources/js/types/dashboard.ts`（`BillingSummary.has_billing_access`）ともに形状不変。`resources/js/pages/Billing/Index.svelte` の `currentPlanCode` props も**変更しない**（P2 は判定層の入替のみ。表示 DTO 化は P8b）。
- **DTO / JsonResource**: 新規 = `SubscriptionEntitlementDto`（`@phpstan-type EntitlementShape`）/ `SubscriptionSnapshot`（値オブジェクト）。既存 `ExternalBillingRedirect` は Gateway 戻り値契約として据置。`BillingSummaryData` / `PurchaseTicketsPageDto`（`ticketAttemptToken` を含むチケット決済の冪等性契約）は**一切触らない**。JsonResource の新設なし。
- **Inertia props**: **なし**（`Billing/Index` / `Dashboard` とも不変）。
- **Factory**: `database/factories/Billing/BillingCheckoutSessionFactory.php`（新規）。`database/factories/OrganizationFactory.php` に `activatedPersonal(User $declarer)` / `grandfatheredFree()`（declarer-less）state を追加（P1 で未追加なら P2 で追加）。`tests/Pest.php:167 createFakeSubscription()` に `bool $hasPaymentMethod = true` 引数を追加（既存呼び出しは既定値で挙動不変）。
- **テストファイル（更新。削除しない）**: `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`（past_due の期待更新 ×3 + BillingAccess マトリクス行）/ `tests/Feature/DashboardTest.php:423-437`（past_due の `has_billing_access` 期待更新 + 遮断シナリオを `canceled` で追加）/ `tests/Feature/Billing/BillingPageTest.php`（fake bind の型名 rename）/ `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（bind 名 rename）/ `tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php`・`tests/Feature/Billing/WebhookIdempotencyTest.php`（期待不変）/ `tests/Feature/Billing/PortalConfigurationTest.php`（期待不変 + 1 ケース追加）/ `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`（**無改変で green**）/ `tests/Feature/Billing/SendBillingRemindersTest.php`・`tests/Feature/Database/BughuntBillingSeederTest.php`（期待不変）/ `tests/Architecture/MassAssignmentSafetyTest.php`（新モデル `BillingCheckoutSession` の `$fillable` に保護キーが無いことの検査対象追加）。

#### 主要な契約

```php
// App\Enums\Billing
enum OnboardingBillingState: string {          // verbatim
    case NoSubscription = 'no_subscription';
    case PendingCheckout = 'pending_checkout';
    case ExpiredCheckout = 'expired_checkout';
    case Subscribed = 'subscribed';
    case ActiveFreePlan = 'active_free_plan';
    public function grantsAccess(): bool { return $this === self::Subscribed || $this === self::ActiveFreePlan; }
}
enum SubscriptionState: string {               // ScheduledForUpgrade は入力列不在のため非移植
    case Active = 'active'; case UpgradeRecovery = 'upgrade_recovery';
    case PastDue = 'past_due'; case Paused = 'paused'; case Inactive = 'inactive';
    public static function fromSubscription(Subscription $sub): self;
    public function grantsAccess(): bool;      // Active|UpgradeRecovery|PastDue => true
}

final class BillingAccess {
    public function __construct(private readonly SubscriptionService $subscriptions) {}
    public function hasActiveAccess(Organization $org): bool; // state()->grantsAccess() || $org->plan_code === null (P4 で後半を削除)
    public function state(Organization $org): OnboardingBillingState;  // verbatim。plan_code を見ない
}

final class SubscriptionService {
    public function __construct(private readonly StripeGatewayInterface $gateway) {}
    public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto;   // verbatim（唯一の判定経路）
    public function applySubscriptionSnapshot(Organization $org, SubscriptionSnapshot $snap, bool $terminated = false): void;
    public function recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentMethod): void; // monotonic
    public function startCheckout(Organization $org, Plan $plan, string $successUrl, string $cancelUrl): ExternalBillingRedirect;
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect;
}

// App\Services\Billing\Contracts
interface StripeGatewayInterface {
    public function createSubscriptionCheckout(Organization $org, string $stripePriceId, string $successUrl, string $cancelUrl): ExternalBillingRedirect;
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect;
    public function syncCustomerDetails(Organization $org): void;
}

final class BillingPermissionService {
    public const PERMISSION_MANAGE_BILLING = 'manage-billing';
    public function grant(User $target, Organization $org): void;      // 非メンバーは DomainException
    public function revoke(User $target, Organization $org): void;
    public function hasDirectPermission(User $user, Organization $org): bool;   // 非メンバーは false
    /** @param list<int> $userIds @return array<int, bool> */
    public function getDirectManageBillingMap(Organization $org, array $userIds): array;
}
```

**`state()` の分岐順（verbatim。上から最初に一致したものを返す）**

| # | 条件 | 戻り | P2 実効 |
|---|---|---|---|
| 1 | `$sub instanceof Subscription && deriveEntitlement($sub)->entitled` | `Subscribed` | 到達（active / trialing / **past_due**） |
| 2 | `$org->free_plan_code === PersonalPlanService::FREE_PLAN_CODE` | `ActiveFreePlan` | **不到達**（writer は P3/P4） |
| 3 | `$sub instanceof Subscription` | `ExpiredCheckout` | 到達（canceled / unpaid / incomplete / incomplete_expired / paused） |
| 4 | live pending な `BillingCheckoutSession`（`created_at >= now()-1day`） | `PendingCheckout` | **不到達**（writer は P9） |
| 5 | stale pending（in-memory 判定）または expired / failed 行あり | `ExpiredCheckout` | **不到達**（同上） |
| 6 | それ以外 | `NoSubscription` | 到達（sub 行なし） |

**DB 列 / index**: `billing_checkout_sessions`（上記 create）/ `subscriptions.has_payment_method`(bool default false) + 既存行 true の backfill。**ルート変更なし**（`/billing`・`/billing/checkout`・`/billing/portal`）。`permissions` に `manage-billing` 行を seed。

#### PHPStan 適合チェック

- `Organization::subscription('default')` は Cashier 由来で `Subscription|null`（`AppServiceProvider.php:185` の `Cashier::useSubscriptionModel(App\Models\Billing\Subscription::class)` で差替済）。`state()` / webhook 経路とも **`$sub instanceof Subscription` で narrow**してから `deriveEntitlement()` に渡す（aigenba `BillingAccess.php:31` と同型）。`?->` で握り潰さない。
- `SubscriptionState::fromSubscription()` は `$sub->schedule_setup_status` が `ScheduleSetupStatus` へ enum cast 済みのため **instance 比較**（`=== ScheduleSetupStatus::Created`）。文字列比較にしない（cast 経由で `alwaysFalse` になる）。
- `has_payment_method` は `casts()` の `'boolean'` により `bool` 型が保証される。`@property bool $has_payment_method` を model docblock に置き、`! $sub->has_payment_method` が `mixed` にならないようにする（型 widen での回避・baseline 化はしない = 禁止事項 2）。
- `BillingCheckoutSession::$created_at` は `Carbon|null`。`state()` の stale 判定は `$row->created_at !== null && $row->created_at->lessThan($threshold)` で null を明示分岐（verbatim）。`get(['id','created_at'])` の戻りは `Collection<int, BillingCheckoutSession>` として generics を docblock で明示。
- `SubscriptionEntitlementDto::toArray()` は `@phpstan-type EntitlementShape` + `@return EntitlementShape` で固定。`SubscriptionSnapshot` の日時は webhook payload の `data_get`（`mixed`）を既存 `stringAt()` + 新設 `epochAt(): ?CarbonImmutable` helper で `?CarbonImmutable` に narrow してから ctor へ渡す。
- `getDirectManageBillingMap()` は `@param list<int>` / `@return array<int, bool>`。`DB::table('permission_user')->pluck('user_id')` の `mixed` は `Assert::integerish()` 後に cast（`ApiKeyPermissionService::getDirectMap` と同一実装）。`ensureTeamId()` は `Assert::integer($org->laratrust_team_id)`（不変条件 #5: `laratrust_team_id` を常に明示）。
- config 読みは `config('cashier.portal_configuration_id')` / `config()->string('quota.fallback_plan')` の既存 typed accessor 経由を維持。`assertPriceSynced()` の `app()->environment('production')` 分岐も verbatim。

#### テスト計画

**先に red を作るテスト**

1. `tests/Unit/Billing/OnboardingBillingStateTest.php` — 5 case の `value` と `grantsAccess()` マトリクス（`Subscribed` / `ActiveFreePlan` のみ true）。enum 不在で red。
2. `tests/Feature/Billing/BillingAccessStateTest.php` — **分岐順 6 段を Factory から固定**:
   - active / trialing sub → `Subscribed` + `hasActiveAccess()=true`
   - **past_due sub（PM 有）→ `Subscribed` + true**（aigenba semantics の明示固定）
   - **past_due sub + `has_payment_method=false` + `trial_ends_at` 過去 → `ExpiredCheckout` + false**（`TrialEndedWithoutPaymentMethod`）
   - paused / canceled / unpaid / incomplete / incomplete_expired → `ExpiredCheckout` + false（**`plan_code` 非 null / null の両方で同じ state** = state が plan_code を見ないことの証明）
   - sub 行なし + checkout session なし → `NoSubscription`（`plan_code=null` は移行 OR で `hasActiveAccess()=true` / `plan_code='standard'` は false）
   - `free_plan_code='personal'`（declarer 有無の両方）→ `ActiveFreePlan` + true
   - `BillingCheckoutSession` pending（`created_at=now`）→ `PendingCheckout` / pending（`created_at=now()-2day`）→ `ExpiredCheckout` / expired・failed → `ExpiredCheckout`、かつ **`state()` 実行で DB 行が書き換わらない**（`updated_at` / `status` 不変を assert = read 経路 no-write 契約）
3. `tests/Feature/Billing/SubscriptionEntitlementTest.php` — `deriveEntitlement()` の `entitled` / `state` / `reason` マトリクス（status × `has_payment_method` × `trial_ends_at`）。`UpgradeRecovery`（`stripe_schedule_id` + `schedule_setup_status=Created`）が `entitled=true` になること。
4. `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` — webhook payload → `SubscriptionSnapshot` → `applySubscriptionSnapshot()` で `organizations.plan_code` / `subscriptions.current_period_end` が現行と同一に落ちる。`deleted`（`terminated=true`）で `plan_code=null` + schedule 2 列クリア。未知 Price は無変更。非 active/trialing status は plan_code 無変更。`recordPaymentMethodSnapshot()` の monotonic（true → false に戻らない）。
5. `tests/Feature/Billing/HasPaymentMethodBackfillMigrationTest.php` — 列追加前に作った subscription 行が backfill 後に `has_payment_method=true` になり、既存 active org の `hasActiveAccess()` が **true のまま**であること（migration 単体の後退防止）。
6. `tests/Architecture/BillingEntitlementSingleSourceTest.php` — (a) `app/` 配下で `SubscriptionState::grantsAccess()` を直接参照するのは `SubscriptionService::deriveEntitlement()` のみ（aigenba の architecture test 相当）、(b) `subscription('default')` の直参照は `BillingAccess` / `SubscriptionService` / `StripeWebhookProcessor` のみ、(c) `organizations.plan_code` / `free_plan_code` を読むのは allowlist（`BillingAccess` の移行 OR / `StripeWebhookProcessor` / `QuotaService` / `Organization` model / `PersonalPlanService` / Filament 表示）のみ。
7. `tests/Architecture/BillingSyncDispatchInvariantTest.php` — `SyncBillingCustomerDetails::dispatch` の呼び出し元は `BillingCustomerSynchronizer` のみ（aigenba IV-2）。

**既存テストの更新（削除しない）**

- `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`:
  - 「有償契約 + 支払い不健全は billing へ redirect + 理由 flash」の dataset から `past_due` を外し `['canceled','incomplete','unpaid','paused']` へ更新。
  - 「有償契約 + 支払い不健全の JSON は 402」の status を `past_due` → `canceled` へ更新（402 文言の固定は不変）。
  - 「billing ページは遮断対象の組織でも到達できる」の status を `past_due` → `canceled` へ更新。
  - 「BillingAccess: plan_code null は常に許可、非 null は active/trialing のみ許可」を **`'past_due' => true`** に更新し、テスト名を「plan_code null は許可（移行 OR。P4 で削除）/ 非 null は entitlement 判定」へ。
  - **追加ケース**: `past_due` + `has_payment_method=false` + `trial_ends_at` 過去 → 遮断（PM 無し dunning が通らないこと）。
- `tests/Feature/DashboardTest.php:423`: past_due の `has_billing_access` 期待を **false → true** に更新し、CTA 遷移先 200（redirect loop なし）の不変条件は `canceled` シナリオを追加して保持。
- `tests/Feature/Billing/BillingPageTest.php`: `SubscriptionCheckoutGateway` / `FakeSubscriptionCheckoutGateway` の参照を `Contracts\StripeGatewayInterface` / `FakeStripeGateway` へ。**props の期待（`currentPlanCode`）と中立帰還 URL の期待は不変**。
- `tests/Feature/Providers/FakeExternalsServiceProviderTest.php:35`: bind の型名 rename に追随。
- `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` / `tests/Feature/Billing/WebhookIdempotencyTest.php` / `tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php`: **無改変で green**（同値性の証明）。
- `tests/Feature/Billing/PortalConfigurationTest.php`: 期待不変 + 「Service 委譲後も `PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id'))` が Gateway に渡る」を 1 ケース追加。

**新規（機能追加分）**

- `tests/Feature/Billing/BillingPermissionServiceTest.php`: grant/revoke → `hasDirectPermission` の反映 / 非メンバーは `DomainException`（grant）・false（has）/ `getDirectManageBillingMap` が 1 クエリ（N+1 なし）。**Policy 回帰**: 直接付与ゼロなら `manageBilling` の結論は現行（owner/admin のみ）と同一 / 直接付与された member は `/billing/checkout` が 403 にならない / 非メンバーは付与行が残存しても false。
- `tests/Feature/Organizations/OrganizationRenameStripeSyncTest.php`: `Queue::fake()` で (a) name 変更時のみ `SyncBillingCustomerDetails` が dispatch、(b) 同名 save では dispatch なし、(c) `stripe_id === null` は no-op、(d) transaction rollback 時に発火しない（`afterCommit`）。
- `tests/Unit/Billing/FakeStripeGatewayTest.php`: `syncCustomerDetails()` が no-op（実 Stripe を叩かない）+ checkout / portal の中立帰還 URL 契約（既存 `FakeTicketCheckoutGatewayTest` と同型）。
- `tests/Feature/Billing/BillingCheckoutSessionModelTest.php`: `statusEnum()` / `intentEnum()` / `isReplayablePending()` と unique 制約（`stripe_session_id` / `idempotency_key` / `(organization_id,intent,attempt_token)`。NULL token は重複許容）。

#### リスク

| リスク | 緩和 |
|---|---|
| **past_due の結論変更（遮断 → 許可）で未収金 org が利用継続する** | 原則 1・3 による意図的 parity（aigenba の dunning 継続方針）。**PM 無し past_due は `deriveEntitlement` が遮断**し、`invoice.payment_failed` 通知（既存 `BillingNotificationDispatcher`）は不変。`BillingAccessStateTest` / `RequireActiveSubscriptionMiddlewareTest` に past_due の両ケース（PM 有=許可 / PM 無 & trial 終了=遮断）を明示固定する。aigenba 側で方針が変われば取り込む（先回り修正しない） |
| **`has_payment_method` の default false により既存有償 org が締め出される**（trial 終了済み行が `TrialEndedWithoutPaymentMethod` で denied） | 列追加と分離した data migration で既存全行を true に backfill（AI-CUE の subscription 生成経路は PM 収集必須の Checkout のみ = 事実値）。`HasPaymentMethodBackfillMigrationTest` で「backfill 後に既存 active org の `hasActiveAccess()` が true のまま」を固定。以後は webhook の `recordPaymentMethodSnapshot()`（monotonic）が真実源 |
| **移行 OR（`plan_code === null`）の消し忘れで P4 のゲート反転が効かない** | `BillingAccess::hasActiveAccess()` の docblock に「P4 で削除」と削除条件（grandfathering backfill 完了）を明記し、`BillingAccessStateTest` に「`NoSubscription` + `plan_code=null` → **P2 は true**」を明示ケースとして置く。P4 はこの 1 行削除 + 期待反転の diff だけで済むことをテスト差分で確認する |
| **`state()` が `plan_code` を見ないことの回帰**（将来の再発明） | `BillingEntitlementSingleSourceTest` で `plan_code` の読み出し allowlist を構造的に固定（`BillingAccess` は移行 OR の 1 箇所のみ許可し、P4 で allowlist からも外す） |
| **`state()` の checkout session クエリが gate 経路（多数の GET）で毎回走る** | verbatim どおり **sub がある / free_plan_code がある org は分岐 1・2 で早期 return** し、クエリに到達するのは sub 行なしの org のみ。P2 では行 0 件（writer 不在）。read 経路で **DB 書込をしない**契約もテストで固定（stale expire は日次 scheduler の責務 = P9 以降） |
| `StripeWebhookProcessor` からの書込移設で webhook の順序逆転耐性・冪等が退行 | 既存 `WebhookEventSubscriptionInvariantTest` / `WebhookIdempotencyTest` を**無改変で維持**。反映条件（active/trialing のみ・未知 Price は受理のみ・行不在時は period 更新 skip・終了契機は deleted のみ）をそのまま持ち込み、`SubscriptionSnapshotSyncTest` で列単位に固定 |
| **rename 時に Stripe API 呼び出しが増える**（現行は customer 同期なし）= 外部副作用の新規発生 | job 化 + `stripe_id === null` no-op + `isDirty('name')` 限定 + fake 環境は `FakeStripeGateway::syncCustomerDetails()` で no-op。`OrganizationRenameStripeSyncTest` + `BillingSyncDispatchInvariantTest` で固定 |
| `manageBilling` への直接付与 OR 追加で認可が緩む | 付与経路（route / UI / Action）を P2 に含めない = 直接付与行は生成されない。Policy 回帰テストで「付与ゼロなら結論は現行と同一」を固定。非メンバーは role null で早期 false（`manageApiKeys` と同型） |
| Gateway rename で fake bind 漏れ（bughunt 環境が実 Stripe を叩く） | `AppServiceProvider` / `FakeExternalsServiceProvider` の bind を同一 PR で更新し、`FakeExternalsServiceProviderTest` + `BillingPageTest` の fake 経由 happy path 2 本（checkout / portal）が中立帰還 URL を返すことで検出 |
| `billing_checkout_sessions` が writer なしで先行導入される（dead table） | `state()` が読む = 状態モデルの一部（D25 の v2 変更）。model / factory / 制約テストを同時に入れ、P9 の writer 追加時に migration を触らずに済ませる。列は AI-CUE に対象がある分だけに絞り、不要列の負債化を防ぐ |

---

### P3 Onboarding 最小導線（ゲート反転より前に導線を実在させる = F-07 再発防止の条件 A）

前提: P1（`App\Enums\PlanCode` **5 case** / `plans.is_active`（**全プラン `true` seed 済み**）/ `organizations.{free_plan_code, free_plan_activated_at, personal_declared_at, personal_declared_by_user_id, signup_tickets_granted_at}` + partial unique index / `PersonalPlanService::activate()` **完成済み** / `PersonalPlanEligibilityDto` / `PersonalPlanIneligibleReason` / `PersonalPlanNotEligibleException` / **D28: 全 tier `monthly_ticket_grant=0`**）と P2（`App\Enums\Billing\OnboardingBillingState` **5 状態** + `BillingAccess::state()` **verbatim** / `hasActiveAccess() = state()->grantsAccess() || $org->plan_code === null`（**移行 OR。P4 で削除**））がマージ済み。

**DoD**: **導線を足すだけ**。`BillingAccess` / `RequireActiveSubscription` は一切触らない（ゲート反転は P4）。Personal 有効化は P1 の `PersonalPlanService::activate()` を**呼ぶだけ**（付与ロジックを再実装しない = 二重付与源を作らない）。**migration は無い**（`plans.is_active` は P1 で `true` seed 済み = Personal / Starter / Standard は本フェーズ開始時点で既に公開済み）。**入口ガードは aigenba verbatim**（`OnboardingController` = `hasActiveAccess()` / `BillingRequiredController` = `state()->grantsAccess()`）。

**P3〜P4 の窓で生じる既知の非対称（新しい述語を作らずに受け入れる）**: aigenba では `hasActiveAccess() ≡ state()->grantsAccess()` だが、AI-CUE は P4 まで `hasActiveAccess()` にのみ移行 OR（`plan_code === null` を通す現行の意図的実装）が乗る。帰結は 2 つ。

1. `onboarding.checkout` は **`plan_code IS NULL` の org（= P3 時点の未契約 org の大半）では `billing.index` へ redirect され、画面としては到達しない**。到達するのは `plan_code` 非 null かつ entitlement 不成立（canceled / unpaid / incomplete / paused = `ExpiredCheckout`）の org のみ。
2. `onboarding.billing-required` は `state()` を直に読むため移行 OR の影響を受けず、`plan_code IS NULL` の非 manage-billing member が**直 URL で 200 render できる**（まだ遮断されていないのに説明画面が見える）。**P3 では billing-required への UI リンクを一切張らない**ため、通常導線からは到達しない。

**P4 で移行 OR の 1 行を消すと両者は自動的に aigenba と同値へ収束する**（`plan_code IS NULL` の org は grandfathering backfill で `ActiveFreePlan`、それ以外は `NoSubscription` → checkout が到達可能・billing-required が正しい対象にだけ出る）。**したがって条件 A は「P3 で route / Controller / 画面 / テストが実在すること」で満たし、到達可能性の反転は P4 の OR 削除と同一コミットで起きる**（P3 に述語を発明して先取りしない）。

#### 変更箇所

| AI-CUE（新規/変更） | 移植元 aigenba | 何をするか |
|---|---|---|
| `routes/web.php`（`auth` + `verified` group 内（L153）、`/billing` 群（L306-311）の直後 = **`require-active-subscription` group（L349）の外**） | `/tmp/aigenba/routes/web.php:441-450` | **D6/D21（route parameter なしの current-org スコープ）**: `GET /onboarding/checkout` → `onboarding.checkout` / `POST /onboarding/activate-personal`（**`->middleware('throttle:10,1')` verbatim**）→ `onboarding.activate-personal` / `GET /billing-required` → `onboarding.billing-required`。aigenba の `prefix('organizations/{organization:slug}')` は移植せず、既存 `billing.index` / `billing.tickets.show` と同一の組織解決にする。route name は既存 `organizations.onboarding.{mcp,cli}`（L293-296。MCP/CLI 導入ガイド = 別責務）と非衝突 |
| `app/Http/Concerns/ResolvesCurrentOrganization.php`（**additive**。既存メソッド無改変） | — | `resolveMemberCurrentOrganization(Request): Organization` を追加。`resolveCurrentOrganization()`（current org 不在 → 404）に続けて **非所属 → 404**（`current_organization_id` が退会後も残存する不整合を**認可より前に 404**。不変条件 #2 = 403 で存在を漏らさない）。aigenba は route binding（`MembershipScopedOrganizationBinder` 相当）が担う層を、current-org スコープに写す際の受け皿 |
| `app/Http/Controllers/Onboarding/OnboardingController.php`（新規） | `app/Http/Controllers/Onboarding/OnboardingController.php` | プラン選択 + Personal 自己申告画面。`show(Request): Response\|RedirectResponse`。**ガード式は verbatim**（`hasActiveAccess()` → `Gate::allows('manage-billing')` の順）。`?plan=` / `IntendedPlanResolver` / `preselectFunding` は移植しない（**P7**） |
| `app/Http/Controllers/Onboarding/ActivatePersonalController.php`（新規） | 同名 | `__invoke(ActivatePersonalRequest): RedirectResponse`。`Gate::authorize('manageBilling')` → `activate()` → `PersonalPlanNotEligibleException` を `ValidationException::withMessages(['plan_code' => $e->userMessage()])` = **422** へ（verbatim）。着地は **`dashboard` 固定**（= aigenba の `$continue === null` 経路と同一。`OnboardingReturnResolver` は **P7**）。`funding_choice` / `consent_version` / `startSetupCheckout` / `setupAttemptToken` は移植しない（**P8a**） |
| `app/Http/Controllers/Onboarding/BillingRequiredController.php`（新規） | 同名 | 未契約 + manage-billing なし member 向け説明画面。`show(Request): Response\|RedirectResponse`。**離脱ガードは verbatim**（`state()->grantsAccess()` → 組織ダッシュボード / `Gate::allows('manage-billing')` → checkout） |
| `app/Http/Requests/Onboarding/ActivatePersonalRequest.php`（新規） | 同名 | `declaration` = `['required', 'accepted']` + `messages()` の 2 文言を verbatim。`funding_choice` / `consent_version` は P8a の additive 追加。`ProhibitsProtectedKeys`（`app/Http/Requests/Concerns/`）を配線し `protectedKeyMissingRules()` を `array_replace` で merge（verbatim。`FormRequestProhibitedKeyTest` 対応）。`authorize(): true`（認可は Controller の `Gate::authorize`） |
| `app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php`（新規） | 同名 | 下記 shape。**フィールド名は aigenba と同一**にし、P7/P8a/P9 は additive に足すだけにする |
| `app/DataTransferObjects/Onboarding/BillingRequiredDto.php`（新規） | 同名 | `ownerName` / `ownerEmail` / `contactUrl` + `@phpstan-type BillingRequiredShape`（**verbatim**） |
| `app/DataTransferObjects/Billing/PlanDto.php`（新規。AI-CUE に PlanDto は不在） | 同名 | `fromModel(Plan)`。**AI-CUE の実列にのみマップ**（下記） |
| `app/Enums/Inquiry/InquirySource.php`（**additive**） | `InquirySource::Onboarding` | `case Onboarding = 'onboarding';` + `label()` に `'オンボーディング'` を追加（`match ($this)` は case 追加で網羅維持。`normalize()` は `tryFrom` のため allowlist に自動追随） |
| `lang/ja/validation.php` | — | `attributes` に `'declaration' => '個人利用の確認'` を追加（ja 文言規約。`plan_code` は既存） |
| `resources/js/pages/Onboarding/Checkout.svelte`（新規） | 同名（643 行） | **P3 部分のみ移植** = plan grid（`PricingPlanCard` 再利用）+ Personal 自己申告 step。funding 2 択 / 同意 UI（P8a）、intended バッジ / `?choose`（P7）、`attemptToken` 同梱（P9）は移植しない |
| `resources/js/pages/Onboarding/BillingRequired.svelte`（新規） | 同名（53 行） | Owner 連絡先 + 問い合わせ導線。403 ではなく専用ページで「行き先のない詰み」を回避する（本文・文言は verbatim） |
| `resources/js/types/onboarding.ts`（新規） | — | PHP の `@phpstan-type` と exact 対（`types/billing.ts` の既存規約） |

**名前空間の分離（aigenba と同一理由）**: 既存 `App\Http\Controllers\Organizations\OrganizationOnboardingController`（MCP/CLI 手順）と `resources/js/pages/Organizations/Onboarding/*` は**触らない**。課金オンボーディングは `App\Http\Controllers\Onboarding\*` / `resources/js/pages/Onboarding/*` に分離する。

**移植時の adaptation（意味論不変。列・API の所在差の吸収のみ）**

- `ContactUrl::forSource($s)->url` → **`ContactUrl::resolveForSource(InquirySource $s): string`**（AI-CUE の既存 API。`app/Services/Marketing/ContactUrl.php:52`）。
- `TicketService::signupGrantTicketCount()` → **`TicketPricingService::signupGrantTickets(): int`**（`app/Services/Billing/TicketPricingService.php:61`。`config()` 直読みを増やさない）。
- `Role::OrganizationOwner` → **`App\Enums\OrganizationRole::Owner`**、`$u->getOrganizationRole($org)` → **`$u->organizationRole($org)`**。Owner 解決は `Organization::routeNotificationForMail()`（`app/Models/Organization.php:164-172`）と**同一パターン**。
- Gate ability 名: aigenba `'manage-billing'` → **AI-CUE `'manageBilling'`**（`OrganizationPolicy::manageBilling`。既存 `BillingController.php:75,101` と同一）。permission 文字列（`BillingPermissionService::PERMISSION_MANAGE_BILLING = 'manage-billing'`）は P2 の成果物でありここでは触らない。
- redirect 先: `organizations.billing.index` → **`billing.index`** / `organizations.show`（組織ダッシュボード）→ **`dashboard`**（AI-CUE に `organizations.show` は存在せず、組織ダッシュボードは current-org スコープの `/dashboard`）/ `organizations.onboarding.{checkout,billing-required}` → **`onboarding.{checkout,billing-required}`**。
- `Inertia::location(route('organizations.billing.index'))` → **素の `RedirectResponse`**（AI-CUE では `Inertia::location()` は Stripe への外部 full page redirect 専用。内部遷移の意味論は同一）。
- `orderBy('id')` → **`orderBy('sort_order')`**（AI-CUE の表示順の権威列。既存 `BillingController::index`(L43) / `PricingService::listPublicPlans()`(L41) と同一。集合は不変）。
- `->with('currentPrices')` は移植しない（AI-CUE の `Plan::currentPrice(PlanPriceKind)` は relation query を都度発行する実装で eager load が効かない。対象は 3 行のため N+1 の実害なし）。
- `OrganizationDto` は AI-CUE に不在 → **新設しない**。organization props は既存 `OrganizationOnboardingController::organizationProps()` と同形（`{id, name, slug}`）。
- `GuestLayout` → **`AppLayout` + T071 primitive**（`PageContainer` / `PageHeader(Section)` / `PageContent`）。両ページとも `auth` group 内のログイン後ページであり、AI-CUE の外枠規約（arch: page-shell-structure）が parity に優先する（原則 2）。

#### 波及変更

- **TypeScript 型定義**: `resources/js/types/onboarding.ts` 新規（`OnboardingCheckoutShape` / `BillingRequiredShape` / `PlanShape` / `PersonalPlanEligibilityShape`）。`types/billing.ts` / `types/marketing.ts` は**変更なし**（`PurchaseTicketsPageDto` の `ticketAttemptToken` = チケット決済の冪等性契約には**一切触らない**。subscription checkout 用の `subscriptionAttemptToken` は P9 の別型）。
- **DTO**: 新規 `OnboardingCheckoutDto` / `BillingRequiredDto` / `PlanDto`。P1 産出の `PersonalPlanEligibilityDto` を**再利用**（新規作成しない）。**JsonResource は使わない**（Inertia ページ = DTO→`toArray()`。`response()->json()` 直書きなし）。
- **Inertia props**: `Onboarding/Checkout` = `{ organization: {id,name,slug}, pageData: OnboardingCheckoutShape }` / `Onboarding/BillingRequired` = `{ organization, pageData: BillingRequiredShape }`。**既存ページの props 変更なし**。
- **Enum / lang**: `InquirySource::Onboarding` 追加（公開フォームの `source` allowlist が 1 件増える）/ `lang/ja/validation.php` の `attributes.declaration` 追加。
- **Factory / seeder**: `database/factories/OrganizationFactory.php` の `activatedPersonal(User)` / `grandfatheredFree()` state（P1/P2 産出）を再利用。Plan は `PlanSeeder` が真実源（P1 で 4 行すべて `is_active=true`）。`database/factories/Billing/PlanFactory.php` が未作成なら P3 で新設（テストデータ手組み禁止）。
- **DB / migration**: **なし**（P1 の列・index を読むだけ）。
- **テストファイル（新規）**: `tests/Feature/Onboarding/{OnboardingCheckoutTest,ActivatePersonalTest,BillingRequiredTest}.php` / `tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php` / `tests/js/pages/{OnboardingCheckout,OnboardingBillingRequired}.test.ts`。
- **テストファイル（更新）**: **なし**（`RequireActiveSubscriptionMiddlewareTest` / `SeededFreePlanBillingAccessTest` は P4 の更新対象。P3 は `BillingAccess` を読むだけで書き換えないため期待不変）。arch テストは allowlist 追加なしで green: `NestedRouteIdorDefenseTest`（route param 2 個以上が対象 / 本 route は **0 個**）/ `OrganizationRouteParamWebOnlyInvariantTest`（`{organization}` param を持たない）/ `ManageRouteAuthGuardTest`（`/manage/` 配下ではない）/ `FormRequestProhibitedKeyTest`（`ProhibitsProtectedKeys` 配線）/ `MassAssignmentSafetyTest`（新 model なし）/ page-shell-structure・ds-purity・atomic-import-graph・lucide-scoped-import。

#### 主要な契約

```php
// App\Http\Concerns\ResolvesCurrentOrganization （additive。既存メソッドは無改変）
private function resolveMemberCurrentOrganization(Request $request): Organization;
//  current org 不在 → 404 / current org にユーザーが非所属 → 404（いずれも認可より前 = 不変条件 #2）

// App\Http\Controllers\Onboarding\OnboardingController      （Request のみ。Organization 引数なし）
public function show(Request $request): Response|RedirectResponse
{
    $organization = $this->resolveMemberCurrentOrganization($request);   // 404 / 404
    Gate::authorize('view', $organization);                              // verbatim（IDOR 二重防御）

    // verbatim: 判定順序は hasActiveAccess → manageBilling
    // （契約済み non-manager が誤って billing-required に飛ばないよう、先に契約状態を判定する）
    if ($this->access->hasActiveAccess($organization)) {
        return new RedirectResponse(route('billing.index'));
    }
    if (! Gate::allows('manageBilling', $organization)) {
        return new RedirectResponse(route('onboarding.billing-required'));
    }

    /** @var list<PlanDto> $plans */
    $plans = Plan::query()
        ->where('is_active', true)                                       // verbatim（P1 で全 true seed）
        ->whereIn('code', [PlanCode::Personal->value, PlanCode::Starter->value,
                           PlanCode::Standard->value, PlanCode::Business->value])   // verbatim（Enterprise 除外）
        ->orderBy('sort_order')                                          // AI-CUE の表示順列（aigenba: id）
        ->get()->map(static fn (Plan $p): PlanDto => PlanDto::fromModel($p))->values()->all();

    $dto = new OnboardingCheckoutDto(
        plans: $plans,
        recommendedPlanCode: PlanCode::Standard->value,                  // verbatim
        defaultPlanCode: PlanCode::Starter->value,                       // verbatim
        contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
        personalEligibility: $this->personalPlan->eligibility($organization, $user),
        signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
    );

    return Inertia::render('Onboarding/Checkout', [
        'organization' => $this->organizationProps($organization),
        'pageData' => $dto->toArray(),
    ]);
}

// App\Http\Controllers\Onboarding\ActivatePersonalController （Request のみ。Organization 引数なし）
public function __invoke(ActivatePersonalRequest $request): RedirectResponse
{
    $organization = $this->resolveMemberCurrentOrganization($request);   // 404 / 404
    Gate::authorize('manageBilling', $organization);                     // 403
    $user = $request->user(); Assert::isInstanceOf($user, User::class);

    try {
        $result = $this->personalPlan->activate($organization, $user);   // P1 完成済み。呼ぶだけ
    } catch (PersonalPlanNotEligibleException $e) {
        throw ValidationException::withMessages(['plan_code' => $e->userMessage()]);   // 422（500 にしない）
    }

    $message = $result->granted
        ? sprintf('パーソナルプラン（無料）を開始しました。無料チケット %d 枚をお付けしました。',
            $this->ticketPricing->signupGrantTickets())
        : 'パーソナルプラン（無料）を開始しました。';

    return redirect()->route('dashboard')->with('success', $message);    // P7 まで dashboard 固定
}

// App\Http\Controllers\Onboarding\BillingRequiredController  （Request のみ。Organization 引数なし）
public function show(Request $request): Response|RedirectResponse
{
    $organization = $this->resolveMemberCurrentOrganization($request);   // 404 / 404
    Gate::authorize('view', $organization);

    // verbatim: 離脱ガード（行き先のない詰みの回避）
    if ($this->access->state($organization)->grantsAccess()) {
        return redirect()->route('dashboard');                           // aigenba: organizations.show
    }
    if (Gate::allows('manageBilling', $organization)) {
        return redirect()->route('onboarding.checkout');
    }

    $owner = $organization->users()->get()
        ->first(static fn (User $u): bool => $u->organizationRole($organization) === OrganizationRole::Owner);

    $dto = new BillingRequiredDto(
        ownerName: $owner instanceof User ? $owner->name : null,
        ownerEmail: $owner instanceof User ? $owner->email : null,
        contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
    );

    return Inertia::render('Onboarding/BillingRequired', [...]);
}
```

**入口ガードの判定源（発明しない）**: P3 は `BillingAccess`（`hasActiveAccess()` / `state()`）**のみ**を読む。`isDeclared()` 等の述語は作らない。`OnboardingBillingState` の各状態に対する挙動は下表（`grantsAccess() = Subscribed || ActiveFreePlan`。`plan_code` は判定に使わない）。

| org の状態 | `state()` | `hasActiveAccess()`（P3 = state + 移行 OR） | `onboarding.checkout` | `onboarding.billing-required` |
|---|---|---|---|---|
| active / trialing / past_due の sub | `Subscribed` | true | → `billing.index` | → `dashboard` |
| `free_plan_code='personal'`（P3 の activate 成功後） | `ActiveFreePlan` | true | → `billing.index` | → `dashboard` |
| canceled / unpaid / incomplete / paused の sub | `ExpiredCheckout` | **false** | **200 render**（manage-billing 保持者）/ → `billing-required`（member） | **200 render**（member）/ → `checkout`（manage-billing 保持者） |
| sub 行なし・`plan_code IS NULL`（P3 時点の未契約 org） | `NoSubscription` | **true**（移行 OR） | → `billing.index`（**P4 の OR 削除で 200 へ**） | **200 render**（member。P3 では UI リンクを張らない） |

**DTO 形状（P3 スコープ。フィールド名は aigenba と同一）**

```
OnboardingCheckoutShape = {
  plans: PlanShape[],                   // is_active=true ∧ code ∈ {personal,starter,standard,business}。sort_order 昇順
  recommendedPlanCode: string,          // 'standard' （verbatim）
  defaultPlanCode: string,              // 'starter'  （verbatim）
  contactUrl: string,                   // ContactUrl::resolveForSource(InquirySource::Onboarding)
  personalEligibility: { eligible: boolean; reason: string | null; reasonLabel: string | null } | null,
  signupGrantTickets: number,           // TicketPricingService::signupGrantTickets()
}
PlanShape = { code: string; name: string; currentBaseAmount: number | null; isActive: boolean }
BillingRequiredShape = { ownerName: string | null; ownerEmail: string | null; contactUrl: string }
```

- **`PlanDto` は AI-CUE の実列にのみマップする**: `code` / `name` / `currentBaseAmount`（`Plan::currentPrice(PlanPriceKind::Base)?->amount`）/ `isActive`。aigenba の `includedSeats` / `currentSeatAmount`（席課金）・`scenarioLimit` / `courseLimit`（能力は `config/quota.php` の「値」で表現するのが AI-CUE 規約）は**移植しない**（原則 4）。`includedMonthlyTickets` も**持たない**（**D28 で月次付与は廃止 = 全 tier 0**。P1 で `PricingPlanShape` からも削除済みで整合）。**通貨フィールドは持たない**（AI-CUE の金額契約は `PricingPlanDto::baseAmountJpy` と同じく JPY 固定）。`currentBaseAmount === null` = base price 不在 = **無料表示契約**（`PricingPlanDto` の docblock と同一意味論）。
- **`currentSeatCount` / `starterAutoMigrationDays` は持たない**（席概念なし / Starter 自動移行機構が AI-CUE に無い）。
- **`attemptToken` は持たない**（subscription checkout 用 = **P9**。`ticketAttemptToken` は既存機構で無関係）。`intendedPlanCode` / `preselectFunding` は **P7**、`autoRechargeTerms` は **P8a** の additive 追加。
- **Plan 集合の露出規則は `is_active=true` の単一規則のみ**（P1 で 4 行すべて true = personal / starter / standard が P3 完了時点で並ぶ。business は Plan 行が無いため結果に出ない）。legacy `free` 行は `whereIn` の code 集合外のため出ない（撤去は P4）。`personalEligibility` は常に非 null。
- `defaultPlanCode` / `recommendedPlanCode` は**コード値**であり `plans` への包含を保証しない。フロントは `plans` に該当 code があるときのみ preselect し、無ければ先頭 plan を選択する（`computeInitialPlan` と同型の決定的挙動）。

**route 構造上の帰結**: onboarding route は **route parameter を持たない current-org スコープ**（D6/D21）で、既存 `billing.*` と**同一の組織解決**。「URL の org ≠ current org」が構造的に発生せず cross-org 課金の余地がない。`isCurrentOrganization` prop・組織切替 CTA・org-slug 非対称は存在しない。

**UI 契約**: 両ページとも `AppLayout` + `PageContainer` + `PageHeader(Section)` + `PageContent`（T071 primitive / arch: page-shell-structure）。plan grid は既存 `resources/js/components/molecules/PricingPlanCard.svelte`（+ `PricingPlanCard.types.ts` の `PricingFeature`）を再利用し**新規 molecule を作らない**。アイコンは `@lucide/svelte` の named import のみ、色は DS token のみ（hex 直書き禁止）、import 方向は pages → templates/molecules/atoms。
**D4（AGENTS.md 禁止事項 #8）**: Personal 有効化 CTA は `personalEligibility.eligible=false` でも `declaration` 未チェックでも **disabled にしない**（aigenba の `if (submitting || !declarationChecked) return;` は移植しない）。押下で submit し、サーバ由来の `errors.plan_code` / `errors.declaration` を表示する。`reasonLabel` は理由 caption として常時可視。**文言はすべてサーバ確定**（`PersonalPlanIneligibleReason::label()` / `ActivatePersonalRequest::messages()`）でフロントは組み立てない。eligibility は render 後に変化しうるため**サーバ判定が唯一の権威**。

#### PHPStan 適合チェック

- Controller 戻り値は `show(): Response|RedirectResponse` / `__invoke(): RedirectResponse`。内部遷移に `Inertia::location()` を使わないため `SymfonyResponse` は union に不要。**`response()->json()` 不使用**。
- `$request->user()` は `Assert::isInstanceOf($user, User::class)` で narrowing（aigenba `requestUser()` と同型 / 既存 `ResolvesCurrentOrganization` と同作法）。`abort_if` に型絞りを依存させない。
- `resolveMemberCurrentOrganization()` は `Organization` を返す（`$user->currentOrganization` の `Organization|null` は `abort_if(... === null, 404)` で解決済み）。
- `list<PlanDto>` を保つため `->map(...)->values()->all()`（`values()` を省くと `array<int, PlanDto>` に落ちて level 10 が落ちる）。`whereIn` に渡す `PlanCode::…->value` の配列は `list<string>`。
- `Plan::query()->where('is_active', true)` は **P1 で `is_active` を列 + `casts()` + `@property bool $is_active` に追加済み**のため larastan の property 解決が通る。
- DTO はすべて `final readonly` + `@phpstan-type ...Shape` + `toArray(): ...Shape`。`OnboardingCheckoutDto` は `@phpstan-import-type PlanDtoShape from PlanDto` / `PersonalPlanEligibilityShape from PersonalPlanEligibilityDto` を import（aigenba verbatim の作法）。
- `Plan::currentPrice(PlanPriceKind::Base)` は `?PlanPrice` → `$plan->currentPrice(PlanPriceKind::Base)?->amount` で `int|null` に落とす（DTO 側が nullable を型で表明）。
- Owner 解決 `->first(static fn (User $u): bool => $u->organizationRole($organization) === OrganizationRole::Owner)` は `?User` → `$owner instanceof User ? $owner->name : null`（`routeNotificationForMail()` と同形。`?->` で握り潰さない）。
- `TicketPricingService::signupGrantTickets(): int` / `PersonalPlanNotEligibleException::userMessage(): string`（P1 産出）を使い、`config()` の `mixed` 直読みを増やさない。
- `InquirySource::label()` の `match ($this)` は case 追加で網羅維持（`identical.alwaysFalse` は発生しない）。`PlanCode` は 5 case で静的に閉じるため `whereIn` の列挙も定数解決される。
- `BillingAccess::state()` は `OnboardingBillingState` を返し `grantsAccess()` は `bool`（P2 契約）。P3 は enum を分岐に使わず `bool` としてのみ消費するため `match` 網羅義務は生じない。
- **baseline 化 / 型 widen は行わない**（禁止事項 #2）。

#### テスト計画

**先に red を作る（新規 Feature）**

1. `tests/Feature/Onboarding/OnboardingCheckoutTest.php`
   - **current org 不在 → 404** / **current org 非所属（`current_organization_id` 残存の退会ユーザー）→ 404**（403 にしない = 存在秘匿）。**同一の 404 テストを 3 route すべてに置く**（不変条件 #2 の網羅）。
   - **`ExpiredCheckout` の org（`plan_code` 非 null + canceled sub）+ manageBilling → 200 render**: `pageData.plans` は `is_active=true` ∧ code ∈ {personal,starter,standard,business} のみで **legacy `free` 行を含まない** / `sort_order` 昇順 / `recommendedPlanCode === 'standard'` / `defaultPlanCode === 'starter'` / `signupGrantTickets === TicketPricingService::signupGrantTickets()` / `contactUrl` が `source=onboarding` 付き / `personalEligibility` が**非 null**（P1 で personal は `is_active=true`）。
   - 同条件 + 非 manageBilling member → `onboarding.billing-required` へ redirect（**判定順序 verbatim の固定**）。
   - `Subscribed`（active sub）→ `billing.index` / `ActiveFreePlan`（`free_plan_code='personal'`）→ `billing.index`。
   - **移行 OR の窓の固定（P4 で期待が反転する箇所を明示）**: `plan_code IS NULL` ∧ `free_plan_code IS NULL` ∧ sub なしの org → **`billing.index` へ redirect**（= P3 の事実）。テスト名に「**P4 の移行 OR 削除で 200 render へ変わる**」と明記し、P4 のテスト計画で期待を更新する（削除しない）。
   - `is_active=false` に落とした Plan は `pageData.plans` に出ない（露出規則の固定）。
2. `tests/Feature/Onboarding/ActivatePersonalTest.php`
   - current org 不在 → 404 / 非所属 → 404 / manageBilling なし member → **403**。
   - `declaration` 未チェック → redirect-back + `errors.declaration`（XHR は 422）。
   - 成功 → `free_plan_code='personal'` / `personal_declared_by_user_id` = declarer / `signup_tickets_granted_at` 設定 / signup grant 付与（P1 の marker 経路）/ **`dashboard` へ redirect** + success flash（枚数入り文言）/ 直後の `state()` が `ActiveFreePlan`。
   - **二重 POST 冪等**: 2 回目は `granted=false` 側の文言で `ticket_ledger_entries` の `signup_grant:%` は **1 行のまま**（P1 marker の回帰）。
   - eligibility 不成立（別 free personal org 保有 / メンバー 4 名 / 有効 subscription あり）→ redirect-back + `errors.plan_code` に**サーバ確定文言**（`PersonalPlanNotEligibleException` が **500 にならない** = 422 相当）。
   - `throttle:10,1` が効く（11 回目 429）。
3. `tests/Feature/Onboarding/BillingRequiredTest.php`
   - current org 不在 → 404 / 非所属 → 404。
   - `grantsAccess()`（active sub / `free_plan_code='personal'`）→ `dashboard` / manageBilling 保持者 → `onboarding.checkout`（**離脱ガード = 行き先のない詰みの回避**）。
   - `ExpiredCheckout` の一般 member → 200 render + `ownerName` / `ownerEmail` / `contactUrl`。
   - **非対称の窓の固定**: `plan_code IS NULL` の一般 member も **200 render**（`state()` は移行 OR を持たない）。テスト名に「P4 で grandfathering backfill 後は `ActiveFreePlan` → `dashboard` へ変わる」と明記（P4 で期待更新）。
   - Owner 不在 org でも 200 で `ownerName` / `ownerEmail` が null（`routeNotificationForMail` と同じ null 許容）。
4. `tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php`
   - `fromModel()` が `code` / `name` / `is_active` と現行 base price（`PlanPriceKind::Base` ∧ `is_current`）をマップする / base price 不在（personal）→ `currentBaseAmount === null` = 無料表示契約。
5. `tests/js/pages/OnboardingCheckout.test.ts`（**D4 適用**）
   - `personalEligibility.eligible=false` でも Personal CTA は**押せる状態を維持**し `reasonLabel` を caption として表示する / 押下で submit され、返ったサーバ文言（`errors.plan_code`）を表示する。
   - `declaration` 未チェックでも CTA は**押せ**、押下後に `errors.declaration` を表示する。
   - `defaultPlanCode` が `plans` に含まれるときは preselect、含まれないときは先頭 plan を選択する。
   - **「月 N 枚」表記が存在しない**（D28 の回帰。plan card に月次付与の文言を出さない）。
6. `tests/js/pages/OnboardingBillingRequired.test.ts`
   - `ownerName` / `ownerEmail` が null でも描画が壊れず、問い合わせ導線が出る。

**既存テストの更新対象**: **なし**（削除も一切なし）。arch テスト（page-shell-structure / ds-purity / atomic-import-graph / lucide-scoped-import / `FormRequestProhibitedKeyTest` / `NestedRouteIdorDefenseTest` / `OrganizationRouteParamWebOnlyInvariantTest` / `ManageRouteAuthGuardTest`）は **allowlist 追加なしで green のまま**を DoD にする（allowlist 追加が必要になった時点で設計を疑う）。テストデータは Factory + `PlanSeeder` 経由で生成する。

#### リスク

| リスク | 緩和 |
|---|---|
| **移行 OR（`plan_code === null`）により P3 の checkout が未契約 org から到達しない**まま P4 に進み、条件 A が実質未達 = F-07 再発 | P3 の DoD を「route / Controller / 画面 / テストの実在」に置き、**到達可能性の反転は P4 の OR 削除と同一コミット**であることを P4 の受入条件に明記（P4 テスト計画に「`plan_code IS NULL` の org で checkout が 200」を移す）。P3 側は当該テストを**現状の期待（redirect）で固定**し、P4 の変更が必ずこのテストに当たるようにする（沈黙して壊れない） |
| **入口ガードに述語を発明する誘惑**（`isDeclared()` 等）。v1 ではこれが `NoPlan` 欠落バグの発生源だった | P3 は `hasActiveAccess()` / `state()->grantsAccess()` の **2 式のみ**を読む（aigenba の Controller と同一）。判定モデルの追加・拡張は P3 のスコープ外（原則 1） |
| `billing-required` が P3〜P4 の窓で「まだ遮断されていない member」に直 URL で見える | **UI から billing-required へのリンクを張らない**（唯一の入口は checkout の redirect で、それは同窓では発生しない）。P4 の backfill で `ActiveFreePlan` になり離脱ガードが `dashboard` へ逃がす = 自然解消。Feature テストで窓の挙動を明示的に固定する |
| current org スコープゆえ `current_organization_id` の不整合（退会後の残存）が cross-org 課金に化ける | `resolveMemberCurrentOrganization()` が**認可より前に 404**（不変条件 #2）。**3 route すべてに同一の 404 テスト**。route parameter が無いため「URL の org ≠ current org」自体が構造的に発生しない |
| P1 未マージ（`PersonalPlanService::activate()` / `PlanCode` / `free_plan_code` / `plans.is_active` / personal seed）だと P3 が実装できない | 依存順（`P1 → P2 → P3`）は交渉不可。`activate-personal` は `activate()` を**呼ぶだけ**で付与ロジックを再実装しない（二重付与源を作らない） |
| `Onboarding/Checkout.svelte` を 643 行 verbatim 移植すると P7/P8a/P9 の未実装機能（funding 2 択・同意・intended バッジ・attempt token）を先取りして壊れる | P3 は plan grid + Personal 自己申告 step のみ。**フィールド名を aigenba と同一**にし、P7/P8a/P9 は DTO/TS に additive に足すだけにする（再設計コストゼロ） |
| Personal 有効化後の着地が `dashboard` 固定（aigenba は `OnboardingReturnResolver` で復帰） | aigenba の `$continue ?? route('dashboard')` の **`$continue === null` 経路と同一挙動**。P3 時点はゲート未反転 = 「gate に奪われた destination」が存在しないため機能欠落にならない。P7 で `OnboardingReturnResolver` へ差し替える |
| `InquirySource` に case を足すと公開フォームの `source` allowlist が広がる | `normalize()` の allowlist 検証（自由入力を正本に残さない）は不変。追加は 1 case（`onboarding`）で流入元分析の粒度が増えるだけ |
| `GuestLayout` を捨てたことで見た目が aigenba と差分になる | 外枠規約（T071 / page-shell-structure）は AGENTS.md 側の不変条件で parity に優先する（原則 2）。本文（plan grid / 自己申告 step / 文言）は aigenba の構成を維持する |

---

### P4 ゲート反転 + grandfathering 移行（free 撤去を含む。山場）

前提: P1（`organizations` の `free_plan_code` / `free_plan_activated_at` / `personal_declared_at` / `personal_declared_by_user_id` / `signup_tickets_granted_at` + partial unique index、`PersonalPlanService::activate()` 完成、`PlanCode` 5 case、`plans.is_active`（**personal / starter とも `is_active=true` で seed**）、`config/quota.php` の `personal` / `starter` limits）/ P2（**`App\Enums\Billing\OnboardingBillingState`（5 状態）と `BillingAccess::state()` の aigenba verbatim 移植** + `BillingCheckoutSession` テーブル + `SubscriptionService::deriveEntitlement()`）/ P3（`onboarding.{checkout,activate-personal,billing-required}` 導線）がマージ済み。

P4 の内容は 4 点に閉じる（新機能を足さない）:

1. **`BillingAccess::hasActiveAccess()` の移行期ガード（AI-CUE 固有の暗黙 free 許可 = `plan_code === null` を通す。`devnotes/20260712-0927-bugfix-billing-free-access`）を撤去**し、`state()->grantsAccess()` 一本にする（= aigenba verbatim の本体になる）。**判定変更はこの 1 点のみ**。
2. `RequireActiveSubscription` の遮断分岐を aigenba verbatim（`/tmp/aigenba/app/Http/Middleware/RequireActiveSubscription.php:60-91`）へ。JSON/XHR の 402 は維持（D15）。
3. **declarer-less grandfathering backfill**（既存 org に `free_plan_code='personal'` を入れて `ActiveFreePlan` にする data migration）。
4. **free 撤去（D11）**（`plans.code='free'` 行の削除 + `PlanSeeder` + `config/quota.php` の `fallback_plan='personal'`）。

**DoD**: `列/index（P1 済）→ backfill 完了・件数検証 → ゲートコード deploy` の順序を守り、**backfill が失敗したらゲートを反転しない**（migration の throw でデプロイが中断し、旧リリースが生き続ける）。加えて **D22**（SQL 更新 ID 集合 == 分類表 grandfather 対象 ID 集合の双方向完全一致）を migration テストで機械検証する。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| ファイル（AI-CUE） | 変更 | 移植元（aigenba） |
|---|---|---|
| `/workspace/app/Services/Billing/BillingAccess.php` | **移行期ガード 3 行（`if ($organization->plan_code === null) { return true; }`）を削除**し、`hasActiveAccess()` を `return $this->state($org)->grantsAccess();` だけにする（P2 で移植済みの `state()` は無改変）。クラス docblock の「plan_code null = fallback free プラン = 支払い不要 tier として許可（devnotes/20260712-0927-bugfix-billing-free-access）」節を、**反転記録**（本設計を正とする / 旧 devnote は歴史として保持 / 無料枠は `free_plan_code='personal'` で表現し `plan_code` は判定に一切使わない）へ差し替える | `/tmp/aigenba/app/Services/Billing/BillingAccess.php:26-30`（`hasActiveAccess()` = `state($org)->grantsAccess()`） |
| `/workspace/app/Http/Middleware/RequireActiveSubscription.php` | 遮断分岐を verbatim 化。`state($org)->grantsAccess()` で通過、不許可なら `manage-billing` 保持者を `onboarding.checkout`、非保持者を `onboarding.billing-required` へ redirect。`billing.index` + `error` flash の誘導を廃止。**JSON/XHR は 402 を維持**（D15。文言は state で 2 分岐）。`resolveOrganization()`（route binding 優先 → `currentOrganization` → null 素通し）・非メンバー 404 defense-in-depth・`session()->reflash()` は**現行のまま維持**（aigenba も L88 で reflash する）。docblock を反転後の方針へ更新 | `/tmp/aigenba/app/Http/Middleware/RequireActiveSubscription.php:60-91`。**`OnboardingReturnResolver` による destination 記憶（L74-81）は移植しない = P7** |
| `/workspace/database/migrations/2026_07_17_000300_backfill_grandfathered_free_plan_code.php`（新規 data migration） | 分類表の述語で `free_plan_code='personal'` / `free_plan_activated_at=now()` を chunk 更新（`personal_declared_by_user_id` / `personal_declared_at` は **NULL のまま**）。**grant を発火しない**（`signup_tickets_granted_at` に触れない）。末尾で残余件数を検証し 0 でなければ `RuntimeException`。`down()` は意図的 no-op | 構造は `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php`（列追加と分離した data migration + `whereNull` ガード + `down()` no-op）。grandfather backfill 自体は aigenba に無い（ゲート有り前提のスタート）ため**移行データ固有** |
| `/workspace/database/migrations/2026_07_17_000400_remove_free_plan_row.php`（新規 data migration。D11） | `PlanSeeder` は `updateOrCreate` のため既存 DB の `free` 行が消えない。(1) `organizations.plan_code='free'` の参照行と `plans.code='free'` に紐づく `plan_prices` を**事前検証し、残存すれば fail-closed（throw）**、(2) `plans` から `code='free'` を削除、(3) **残余 0 件を検証**。`down()` は `free` 行（`name='Free'` / `monthly_ticket_grant=10` / `sort_order=0` / `is_active=true`）を復元する（config には触らない） | —（AI-CUE 固有の移行。aigenba に free plan 行が無い） |
| `/workspace/database/seeders/PlanSeeder.php` | `['code' => 'free']` の `updateOrCreate` を削除（後継は P1 で seed 済みの `personal`）。docblock の「free プランは Stripe Price を持たない = 未契約の既定 = BillingAccess の前提」節を「free entitlement は `organizations.free_plan_code='personal'` で表現する。`plan_code` は entitlement 判定に使わない」へ更新 | `/tmp/aigenba/database/seeders/PlanSeeder.php`（Personal / Starter / Standard / Business / Enterprise のみ。free 行を持たない） |
| `/workspace/config/quota.php` | `fallback_plan` を `'free'` → **`'personal'`**（P1 で追加済みの `personal` limits は旧 `free` と同値 = **実効 limits 不変**）。`plans` から `'free'` キーを削除（対応 Plan 行が消えるため並走を残さない） | — |
| `/workspace/app/Services/Billing/QuotaService.php` | **コード変更なし**。docblock の「plan_code null は fallback_plan（free）」を `personal` 表記へ更新 | — |
| `/workspace/app/Models/Organization.php:109` / `/workspace/app/Services/Billing/StripeWebhookProcessor.php:49` | docblock の「plan_code null = 未契約 = 支払い不要の free tier」記述を反転後の事実（`plan_code` は quota の解決キーのみ。利用可否は `BillingAccess::state()` が決める）へ更新 | `/tmp/aigenba/app/Models/Organization.php` |
| `/workspace/database/seeders/ManualTestSeeder.php` | current base Price を持たない Personal プラン組織を `PersonalPlanService::activate($org, $owner)` 経由で有効化する（`plan_code` は null のまま / declarer = owner / marker + grant は activate 内で 1 回）。手動テスト環境が反転後に締め出されないため | —（AI-CUE 固有 fixture） |
| `/workspace/database/seeders/BughuntBillingSeeder.php` | 「課金なしで通る組織」を `plan_code='free'` ではなく **declarer-less grandfathered 相当**（`free_plan_code='personal'` / declarer NULL）で作る。free 行が消えるため | — |
| `/workspace/database/factories/OrganizationFactory.php` | **変更なし**（`freePersonal(User $declarer)` / `grandfathered()` state は P1 で追加済み） | `/tmp/aigenba/database/factories/OrganizationFactory.php`（`freePersonal()`） |
| `/workspace/tests/Pest.php:118-133` | `createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true)` に拡張。既定で **backfill 相当の状態**（`free_plan_code='personal'` / `free_plan_activated_at` / declarer NULL）を付与する。**`activate()` は呼ばない**（呼ぶと signup grant が発火して既存の残高期待が壊れ、declarer partial unique index にも触れる）。ゲート / onboarding テストは `grandfatherFreePlan: false` で真の未契約 org を作る。docblock を反転後へ更新 | — |
| `/workspace/routes/web.php:302-317,343-348` | コメントのみ更新（「free（未契約）組織は遮断されない」→「未契約組織は onboarding へ遮断される。billing / purchase-tickets / notifications / onboarding は gate group 外の構造的 allowlist」）。**route 定義の変更なし**（`onboarding.*` は P3 が gate group 外に定義済み） | `/tmp/aigenba/routes/web.php` |
| `/workspace/docs/architecture.md:85` / `/workspace/docs/app-integration-guide.md:129-139,190` / `/workspace/docs/template-divergence.md §D9` | 課金ゲート方針を反転後へ更新。**D9（free tier は課金ゲートを通す）は「解消（本設計で反転。無料枠は `free_plan_code='personal'` の明示申告へ移行）」として記録を更新**（削除しない） | — |

**P4 に含めない（フェーズ境界）**: `OnboardingReturnResolver` / `IntendedPlanResolver`（P7）/ 月次付与の廃止（D28 = P5）/ サブスク checkout の attempt token・着地 feedback（P9）/ `PlanCode` の case 追加や `plans.is_active` の変更（P1 で確定済み）。

#### 波及変更

- **TypeScript 型定義**: なし（`OnboardingBillingState` は middleware / Service 内部の判定にのみ使い、Inertia props に載せない = aigenba と同じ）。
- **DTO / JsonResource**: なし（`state()` の戻り値・`grantsAccess()` の shape は P2 のまま。**値**が変わるのは `hasActiveAccess()` の `plan_code IS NULL` 経路だけ）。
- **Inertia props**: なし。遮断理由の提示は P3 の `Onboarding/Checkout` / `Onboarding/BillingRequired` の props に依存する（middleware は `->with('error', ...)` を渡さない = aigenba 同様「理由は着地ページが持つ」）。
- **テストファイル（更新。削除しない）**
  - `/workspace/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`
  - `/workspace/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`
  - `/workspace/tests/Feature/Billing/OnboardingBillingStateTest.php`（P2 成果物。`plan_code IS NULL` + sub なし + `free_plan_code` NULL の org が `NoSubscription` = **遮断**になることの期待を更新）
  - `/workspace/tests/Feature/Billing/QuotaTest.php:15`（`fallback_plan` の表記を `personal` へ。limits 期待値は不変）
  - `/workspace/tests/Feature/Billing/BillingPageTest.php:26`（`plans.0.code` `'free'` → `'personal'`）
  - `/workspace/tests/Feature/Marketing/PricingPageTest.php:35`（`page.plans.0.code` `'free'` → `'personal'` / 件数）
  - `/workspace/tests/Feature/Billing/PlanSeederPriceInvariantTest.php:23`（`where('code','free')` → `'personal'`）
  - `/workspace/tests/Feature/Database/BughuntBillingSeederTest.php:68`（`forceFill(['plan_code' => 'free'])` を撤去し grandfathered 相当へ）
  - `/workspace/tests/Feature/Database/ManualTestSeederTest.php:30`（Personal 組織が `plan_code` null かつ `free_plan_code='personal'` + declarer 非 null）
  - `/workspace/tests/js/pages/Pricing.test.ts`（fixture の `code: "free"` を実在プランへ）
  - `/workspace/tests/Pest.php`（helper 既定の変更）
  - `Organization::factory()` を直呼びして gate 対象 route を叩くファイルの棚卸し: `/workspace/tests/Feature/Organization/OrganizationSwitchTest.php` / `/workspace/tests/Feature/Auth/ApiKeyGuardTest.php` / `/workspace/tests/Feature/Organization/DefaultTeamInvariantTest.php` / `/workspace/tests/Feature/Billing/BillingNotificationDispatchTest.php` / `/workspace/tests/Feature/Billing/SendBillingRemindersTest.php` / `/workspace/tests/Feature/Filament/UserResourceTest.php`。業務 route を叩くものだけ `grandfathered()` state を付与する。
- **テストファイル（新規）**: 下記「テスト計画」。

#### 主要な契約

**判定（P2 成果物 = aigenba verbatim。P4 は `hasActiveAccess()` の移行期ガードだけを外す）**

```php
// App\Services\Billing\BillingAccess — P4 の判定変更はここだけ
public function hasActiveAccess(Organization $org): bool
{
    // P2（移行期）: AI-CUE 固有の暗黙 free 許可。P4 でこの 3 行を削除する
    // if ($org->plan_code === null) { return true; }
    return $this->state($org)->grantsAccess();      // P4 = aigenba verbatim
}

public function state(Organization $org): OnboardingBillingState;   // P2 で verbatim 移植済み・無改変
```

```text
state() の解決順（aigenba verbatim。plan_code を一切見ない）        grantsAccess
1. subscription('default') が deriveEntitlement()->entitled   Subscribed        true
2. free_plan_code === PersonalPlanService::FREE_PLAN_CODE      ActiveFreePlan    true
3. subscription('default') 行がある                            ExpiredCheckout   false
4. BillingCheckoutSession の live pending がある                PendingCheckout   false
5. BillingCheckoutSession の expired / failed がある            ExpiredCheckout   false
6. それ以外                                                    NoSubscription    false
```

**middleware（差分。gate 分岐は aigenba verbatim / org 解決は AI-CUE の current-org 規約を維持）**

```php
private const string NO_PLAN_MESSAGE  = 'ご利用にはプランの選択が必要です。';
private const string BLOCKED_MESSAGE  = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。'; // 現行文言のまま（D15）

public function handle(Request $request, Closure $next): Response
{
    // 未認証素通し / resolveOrganization()（route binding 優先 → currentOrganization → null 素通し）
    // 非メンバー 404 defense-in-depth は現行のまま維持
    $state = $this->access->state($organization);
    if ($state->grantsAccess()) {
        return $next($request);
    }

    if ($request->expectsJson()) {                       // D15: JSON/XHR は 402 を維持
        abort(Response::HTTP_PAYMENT_REQUIRED,
            $state === OnboardingBillingState::ExpiredCheckout ? self::BLOCKED_MESSAGE : self::NO_PLAN_MESSAGE);
    }

    $request->session()->reflash();                      // 直前 hop の flash 延命（招待受諾等）。aigenba L88 と同じ

    return redirect()->route(
        Gate::forUser($user)->allows('manageBilling', $organization)
            ? 'onboarding.checkout'                      // route parameter なしの current-org スコープ
            : 'onboarding.billing-required'
    );
}
```

- 認可 ability は既存の `manageBilling`（`app/Policies/OrganizationPolicy.php:37`）を使う（ability を増やさない）。
- `onboarding.{checkout,activate-personal,billing-required}` は **gate group 外**（`routes/web.php:349` の `require-active-subscription` group に入れない = 構造的 allowlist）。入れると遮断 → 遮断の無限ループ = 詰みになる。
- **DB 列 / index の追加は無い**（すべて P1）。P4 は既存列への UPDATE と `plans` の 1 行削除のみ。partial unique index `organizations_personal_free_declarer_unique` は `WHERE free_plan_code='personal' AND personal_declared_by_user_id IS NOT NULL` のため、**declarer NULL の backfill 行は対象外 = 衝突しない**。
- backfill migration は `'personal'` リテラルを直書きする（migration をアプリ定数に依存させない aigenba の流儀）。ドリフトは invariant テストで固定する。

**backfill 分類表（effective entitlement snapshot ベース。確定）**

判定は raw な `plan_code IS NULL` ではなく、**「P4 直前の gate（`plan_code IS NULL` → 許可 / 非 null → `state()->grantsAccess()`）」×「P4 後の gate（`state()->grantsAccess()`。backfill 前）」**の 2 値。`sub` = `subscription('default')`、`entitled` = `SubscriptionService::deriveEntitlement($sub)->entitled`。**`plan_code` 非 null の org は P2 で既に `state()` 委譲済み = P4 で挙動が変わらない**ため、影響は `plan_code IS NULL` の行に閉じる。

| # | effective entitlement snapshot | 旧 gate | 新 state()（backfill 前） | 新 gate | 処置 |
|---|---|---|---|---|---|
| 1 | `plan_code` 非 null + `entitled` | 許可 | `Subscribed` | 許可 | **何もしない** |
| 2 | `plan_code` 非 null + sub あり + `¬entitled`（`past_due` / `unpaid` / `incomplete` / `canceled` / `paused` / PM 無 trial 終了） | 遮断 | `ExpiredCheckout` | 遮断 | **何もしない**（今日遮断中の org に free entitlement を与えない） |
| 3 | `plan_code` 非 null + sub 行なし（webhook 順序逆転の壊れ状態） | 遮断 | `NoSubscription` | 遮断 | **何もしない**（fail-closed 維持） |
| 4 | `plan_code` null + `free_plan_code` null + sub 行なし + checkout session 行なし | 許可 | `NoSubscription` | 遮断 | **grandfather** |
| 5 | `plan_code` null + `free_plan_code` null + sub 行なし + live pending checkout session | 許可 | `PendingCheckout` | 遮断 | **grandfather** |
| 6 | `plan_code` null + `free_plan_code` null + sub 行なし + expired / failed checkout session のみ | 許可 | `ExpiredCheckout` | 遮断 | **grandfather** |
| 7 | `plan_code` null + `free_plan_code` null + sub が `canceled` / `incomplete` / `unpaid` / `past_due` / `paused`（= `subscription.deleted` 後に webhook が `plan_code` を null 化した paid→free 経路） | 許可 | `ExpiredCheckout` | 遮断 | **grandfather** |
| 8 | `plan_code` null + sub `active`/`trialing` かつ `entitled`（webhook 同期ラグ / price → plan 解決不能） | 許可 | `Subscribed` | 許可 | **何もしない**（entitled subscription と free entitlement を併存させない invariant） |
| 9 | `plan_code` null + sub `active`/`trialing` だが `¬entitled`（PM 無 trial 終了） | 許可 | `ExpiredCheckout` | 遮断 | **何もしない**（生きた subscription 行を持つ = paid 導線の org。checkout / Customer Portal は gate allowlist のため詰まず、PM 登録で `Subscribed` に戻る。**aigenba verbatim の帰結であり先回り修正しない**） |
| 10 | `free_plan_code='personal'`（declarer 有無を問わず。P3〜P4 間に自発 activate した org / migration 再実行） | 許可 | `ActiveFreePlan` | 許可 | **何もしない**（`whereNull('free_plan_code')` ガード = 冪等） |
| 11 | signup grant 履歴（`ticket_ledger_entries.idempotency_key LIKE 'signup_grant:%'`）/ `signup_tickets_granted_at` の有無 | — | — | — | **分類に影響しない**（backfill は grant を発火せず marker にも触れないため、未付与 org は将来の activate / paid 成立時に 1 回だけ付与される） |

→ 実効述語は分類 4・5・6・7 を 1 本の SQL に縮退させたもの（分類 10 は `free_plan_code IS NULL` ガード、分類 1・2・3 は `plan_code IS NULL` ガード、分類 8・9 は `NOT EXISTS` で除外される）:

```sql
UPDATE organizations SET free_plan_code = 'personal', free_plan_activated_at = :now
WHERE free_plan_code IS NULL
  AND plan_code IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM subscriptions s
    WHERE s.organization_id = organizations.id
      AND s.type = 'default'
      AND s.stripe_status IN ('active', 'trialing')
  );
-- personal_declared_by_user_id / personal_declared_at は NULL のまま（= partial unique index の対象外）
-- signup_tickets_granted_at には触れない（grant を発火しない）
```

この集合は分類表の grandfather 行（4・5・6・7）と一致し、**「未契約で今日使えている org は誰もアクセスを失わない」「今日遮断中の org は誰もアクセスを得ない」**を同時に満たす。**D22: この同値を migration テストで双方向に機械検証する**。

**free 撤去（D11）の実変更一式**

| 対象 | 変更 |
|---|---|
| `plans` テーブル | `remove_free_plan_row` migration が事前検証（`organizations.plan_code='free'` の参照行 / `free` の `plan_prices` が残存すれば throw = fail-closed）→ 削除 → 残余 0 件検証 |
| `PlanSeeder` | `free` 行の投入を削除（後継 = `personal`） |
| `config/quota.php` | `fallback_plan: 'free' → 'personal'` / `plans` から `'free'` キーを削除（`personal` limits は P1 で投入済み・旧 free と同値 = 実効 limits 不変） |
| `/pricing`・`/billing` の一覧 | P1 の `plans.is_active` フィルタ下で **`personal` / `starter` / `standard` が公開**（`is_active=true`）。free 行の消滅により料金表の先頭は `personal` になる |
| rollback | 運用手順（下記）。**migration の `down()` は config を戻さない**（リポジトリ内 config を migration が書き換えられないため） |

**デプロイ順序（DoD）**

1. 列 + partial unique index は **P1 で適用済み**。
2. `php artisan migrate` が `backfill_grandfathered_free_plan_code` → `remove_free_plan_row` の順に完了し、それぞれ末尾の**件数検証（残余 = 0）**を通る。
3. その後にゲートコード（`BillingAccess` の移行期ガード撤去 + middleware）のリリースが活性化する。

migration が throw した場合はデプロイが中断し、**旧リリース（ゲート未反転）が生き続ける** — これが「backfill 失敗ならゲートを反転しない」の実現機構。

**rollback 手順（運用手順として分離）**

1. **コード / config を revert**（`hasActiveAccess()` の移行期ガード復帰 / middleware / `config/quota.php` の `fallback_plan='free'` + `plans.free` キー復活）→ 締め出しが即座に解消する。
2. 必要なら **`remove_free_plan_row` の `down()`** を実行し `plans` の `free` 行を復元する（1 と 2 の間は `fallback_plan='free'` が config の limits だけを引く = 実害なし。`/pricing` に free が出ないだけ）。
3. **grandfather backfill は revert しない**（`down()` は no-op）。旧コードのガードは `plan_code` のみを見るため、`free_plan_code` が入った行は無害に無視される。

#### PHPStan 適合チェック

- `BillingAccess::hasActiveAccess(): bool` は `state()` の戻り（`OnboardingBillingState`）→ `grantsAccess(): bool` をそのまま返す。行削除のみのため新たな型注釈は不要。抽象型の widen・baseline は使わない（禁止事項 2）。
- `RequireActiveSubscription::handle()`: `$request->route('organization')` は `mixed` → 既存の `resolveOrganization(): ?Organization` の `instanceof` narrowing を維持。`$user` の `instanceof User` narrowing も維持。
- 402 文言の分岐は `$state === OnboardingBillingState::ExpiredCheckout ? … : …`。**`grantsAccess()` の早期 return 後**に置くため、enum case 比較で `alwaysFalse` / `alwaysTrue` にならない（`match` を使わないことで網羅性 error も出さない）。
- `Gate::forUser($user)->allows('manageBilling', $organization)` は `bool`、`route(string): string`、`redirect()->route()` は `RedirectResponse`（⊂ `Response`）、`abort()` は `never`。`@param Closure(Request): Response $next` docblock を維持し全経路が `Response` を返すことを型で保証する。
- migration は `DB::table()` クエリビルダのみ（Eloquent モデル・アプリ定数に依存しない）。件数は `->count(): int`、削除は `->delete(): int` で受け、違反時は `RuntimeException` を直接 throw。
- `config()->string('quota.fallback_plan')` の typed accessor を維持（`QuotaService` のコードは無改変）。
- `tests/Pest.php` の `createOrganizationWithOwner(): array{Organization, User}` は戻り値型不変。追加引数は `bool` 既定値付き。

#### テスト計画（テストファースト）

**先に red で書く（F-07 回帰。新規 `/workspace/tests/Feature/Billing/GateInversionF07RegressionTest.php`）**

- **(a) 既存 `plan_code IS NULL` 組織が移行後も業務ルートに到達する**: `createOrganizationWithOwner()`（既定 = backfill 相当の declarer-less grandfathered）で org を作り、`/projects` が `assertOk()` + `assertInertia(component 'Projects/Index')`、`POST /projects` でプロジェクト作成に到達、`/app` に到達。**declarer NULL でも `ActiveFreePlan` で通る**ことを固定する。
- **(b) 新規登録者が遮断されても activate-personal / checkout に到達し詰まない**: `createOrganizationWithOwner(grandfatherFreePlan: false)` の owner → `/projects` が `onboarding.checkout` へ redirect、着地が 200 → `POST onboarding.activate-personal` → 再度 `/projects` が `assertOk()`（= 導線が閉じている）。`manageBilling` 非保持 member は `onboarding.billing-required` へ redirect し着地が 200。
- **(c) 遮断時に理由が画面に出る**（H1「説明なしリダイレクト」の再発検知）: 遮断 redirect を follow した着地が **`billing.index` でないこと**を明示 assert し、Inertia component が `Onboarding/Checkout` / `Onboarding/BillingRequired` であること、理由提示の素材が props に載っていること（Checkout: `pageData.plans` 非空 + `pageData.personalEligibility` 非 null / BillingRequired: `pageData.ownerEmail`・`pageData.contactUrl`）。JSON は 402 + `message` が state 別の確定文言と一致。
- **(d) 無限ループ不在**: `onboarding.*` / `billing.*` / `purchase-tickets` / `notifications` が gate group 外である構造的 allowlist の検証（遮断 redirect 先を再度叩いて 302 が返らないこと）。

**backfill migration テスト（新規 `/workspace/tests/Feature/Billing/GrandfatherFreePlanBackfillTest.php`）**

- **D22（必須 DoD）**: 分類表 11 行を Factory で組み、各 fixture に「分類 #」と `expectGrandfather: bool` を宣言した dataset を持たせる。**expected 集合** = `expectGrandfather=true` の org ID 集合（分類表そのもの）、**actual 集合** = migration の `up()` 実行前後の差分（`free_plan_code='personal'` かつ declarer NULL になった org ID）。`expected \ actual === []` **かつ** `actual \ expected === []` の**双方向完全一致**をアサートする（片側包含では締め出しも誤救済も検出できない）。
- 分類 2・3 が**救われない**（支払い不健全 / 壊れ状態が free に落ちない）/ 分類 8・9 が**救われない**（生きた subscription 行を持つ org を free へ倒さない）。
- **grant が 1 枚も発火しない**（`ticket_ledger_entries` の件数不変 + `signup_tickets_granted_at` 不変）。
- 2 回実行して結果不変（冪等。`whereNull('free_plan_code')` ガード）。
- declarer-less 行が partial unique index に衝突せず、**同一 user が複数 org を持っていても全件救われる**。backfill 後に当該 owner が別 org で `activate()` しても index 違反にならない。
- 残余件数検証が 0 でないときに `RuntimeException` を throw する（= デプロイ中断 = ゲート非活性）。

**free 撤去テスト（新規 `/workspace/tests/Feature/Billing/RemoveFreePlanRowMigrationTest.php`）**

- `organizations.plan_code='free'` の参照行が残る状態 / `free` の `plan_prices` が残る状態で **fail-closed（throw）**し、`plans` の `free` 行が消えないこと。
- 参照ゼロなら削除され、`plans.code='free'` の残余が 0 件。
- `down()` で `free` 行が復元される（config は無改変であることを assert）。

**arch / invariant テスト（新規 `/workspace/tests/Architecture/BackfillPlanCodeLiteralInvariantTest.php`）**

- backfill migration が直書きする `'personal'` リテラルが `PersonalPlanService::FREE_PLAN_CODE` と一致すること（ドリフト検知）。
- `config('quota.fallback_plan')` が `PersonalPlanService::FREE_PLAN_CODE` と一致し、対応する limits が `config('quota.plans')` に存在すること（`QuotaService.php:33` の `?? []` による「未知キー = 無制限」silent 退行の防止）。
- `app/` 内で `plan_code` を entitlement 判定に使う参照が存在しないこと（`BillingAccess` / middleware に `plan_code` が現れない = verbatim の固定）。

**既存テストの更新（削除しない）**

- `/workspace/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`
  - 冒頭コメントの gate 方針を反転後の記述へ。
  - 「Free（未契約）組織は業務 route に到達できる（F-07 再現）」3 本（L30 / L37 / L45）→ 「**未契約組織（`free_plan_code` NULL）は `onboarding.checkout` へ遮断される**」+「**grandfathered / activated な free 組織（`ActiveFreePlan`）は到達できる**」へ期待を更新。
  - 「有償契約 + 支払い不健全は billing へ redirect + 理由 flash」(L62) → **`onboarding.checkout` へ redirect（flash なし）**へ。dataset に `paused` を追加（`past_due` / `unpaid` / `incomplete` / `canceled` / `paused`）。
  - 「有償契約 + subscription 行なしは fail-closed」(L71) → 遮断先を `onboarding.checkout` へ更新。
  - 「有償契約 + 支払い不健全の JSON は 402 + message 固定」(L79) は**文言も含めて維持**（D15）。加えて「**未契約の JSON は 402 + `NO_PLAN_MESSAGE`**」を 1 本追加。
  - `BillingAccess` 単体マトリクス (L109) を `state()` ベースへ更新: `plan_code` null + sub なし + `free_plan_code` null → **`NoSubscription` = false** / `free_plan_code='personal'`（declarer 有無を問わず）→ **`ActiveFreePlan` = true** / `plan_code` null + entitled sub → **`Subscribed` = true**（分類 8）/ `plan_code` 非 null + 不健全 → `ExpiredCheckout` = false。
  - 「free プランは Stripe Price を持たない」(L99) → 対象を `config('quota.fallback_plan')`（= `personal`）へ読み替えて**維持**。
  - 「billing ページは遮断対象でも到達できる」(L90) / 「route bound organization が…redirect」(L139) / 「非メンバーが binder を通過しても 404」(L154) は**維持**（遮断先の期待のみ更新）。
- `/workspace/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`: 「seeder の Free 組織が**素通り**する」→「seeder が `PersonalPlanService::activate()` 済みのため全ロールが `/projects` に到達する」へ。`seededFreePlan()`（current base Price を持たない Plan）の解決先は free 消滅により `personal` になる。`expect($organization->plan_code)->toBeNull()`（L33）は**維持**し、`free_plan_code === 'personal'` と declarer 非 null を追加（F-C3 の不変条件を残したまま反転後の事実を固定）。
- `/workspace/tests/Feature/Billing/OnboardingBillingStateTest.php`（P2 成果物）: `hasActiveAccess()` の移行期ガード撤去に伴い、`plan_code` null + `free_plan_code` null の期待を `true` → `false` へ。`state()` 自体の期待は**不変**（= 反転が 1 点に閉じている証明）。
- `/workspace/tests/Feature/Billing/QuotaTest.php:15`: 「`plan_code` 未設定の組織には `fallback_plan`（free）の既定 limits が効く」→ `personal` 表記へ。**limits の期待値（`max_projects=1` / `max_members=3` / `max_storage_bytes=1GiB`）は 1 つも変えない**（実効 limits 不変の証明）。
- `/workspace/tests/Feature/Billing/BillingPageTest.php` / `/workspace/tests/Feature/Marketing/PricingPageTest.php` / `/workspace/tests/js/pages/Pricing.test.ts` / `/workspace/tests/Feature/Billing/PlanSeederPriceInvariantTest.php` / `/workspace/tests/Feature/Database/BughuntBillingSeederTest.php` / `/workspace/tests/Feature/Database/ManualTestSeederTest.php`: 上記「波及変更」のとおり `free` 参照を実在プランへ更新。
- `/workspace/tests/Pest.php`: helper 既定の変更（docblock 含む）。

**新規（UI 文言の固定）**

- `/workspace/tests/js/pages/OnboardingBillingRequired.test.ts`: `Onboarding/BillingRequired` が「なぜ操作を続けられないか」の説明コピーと owner 連絡導線をレンダすること（props 追加はしない。**ボタンの disabled 化はしない** = 禁止事項 #8）。

**共通**: テストデータは Factory 生成（手組み禁止）/ `RefreshDatabase` グローバル + `--parallel` 前提を維持（個別 `DatabaseTransactions` を追加しない）。

#### リスク

| リスク | 緩和 |
|---|---|
| **既存ユーザー締め出し（F-07 再発）** = backfill 漏れ or デプロイ順序逆転 | 述語を分類表の grandfather 行と一致させ、**D22 の双方向集合同値アサート**で分類表と実 SQL のズレを機械検出。migration 末尾の残余 0 件検証が throw → デプロイ中断（ゲート非活性）。 |
| **106 テストファイルの一斉 red** | `createOrganizationWithOwner` の既定を **backfill 相当（declarer-less grandfathered）**に変更して吸収。`activate()` を呼ばないため **signup grant が発火せず残高期待が壊れない**、かつ declarer partial unique index に触れないため 1 user 複数 org のテストも壊れない。`Organization::factory()` 直呼び 6 ファイルのみ `grandfathered()` を手当。 |
| **分類 9（`plan_code` null + active/trialing だが `¬entitled`）が P4 でアクセスを失う** | **aigenba verbatim の帰結**であり先回り修正しない（原則 5）。当該 org は `manageBilling` 保持者なら `onboarding.checkout`、非保持者なら `onboarding.billing-required` へ落ち、checkout / Customer Portal は gate allowlist のため詰まない。PM 登録で `Subscribed` に復帰する。分類表の行として明示し、`OnboardingBillingStateTest` が挙動を固定する。 |
| **支払い不健全の paid org が遮断先の checkout から Personal(free) へ自主降格する** | **aigenba も同挙動**であり独自ガードを足さない（原則 2）。`PersonalPlanService::eligibility()` の `HasEntitledSubscription` は `¬entitled` では発火しないが、降格が成立すれば `state()` は `ActiveFreePlan` を返す（`plan_code` を見ないため）= aigenba と同一の結論。分岐を発明しない。 |
| **`error` flash 廃止で遮断理由が失われる** | 理由は着地ページが持つ（aigenba 方式）。F-07 テスト (c) と `OnboardingBillingRequired.test.ts` が固定。`reflash()` は招待受諾等の直前 flash 延命のため維持する（aigenba L88 と同じ）。 |
| **JSON 402 の文言変更で既存 XHR クライアントが後退** | 支払い不健全経路（`ExpiredCheckout`）の文言は現行と同一のまま維持（D15）。新文言は「未契約」という**新しい遮断事由にのみ**追加される。 |
| **`fallback_plan` 切替で quota が silent に緩む**（`QuotaService` は未知キーを `?? []` = 無制限に倒す） | `personal` limits は P1 で投入済み・旧 free と同値。`QuotaTest` の limits 期待値を 1 つも変えないことで実効不変を証明し、`BackfillPlanCodeLiteralInvariantTest` が `fallback_plan` ⊆ `quota.plans` を CI で固定する。 |
| **`free` Plan 行の削除が参照行を壊す** | migration が `organizations.plan_code='free'` / 関連 `plan_prices` を事前検証して **fail-closed**（黙って消さない）。free は Stripe Price を持たないため `plan_code` に載る経路が構造的に無く、本番の残存は 0 件が期待値。残存が出たらデプロイを止めて調査する。 |
| **grandfathered org が declarer-less のまま滞留**（濫用防止が既存 org に効かない） | 概念設計で受容済み（自然収束しない旨を明記）。P4 は主張を広げない。 |
| **遮断先ページ（P3）の欠落・rename** | P4 のテストが route 名 3 本を直接叩くため欠落は red で検知。P4 単独マージ不可の依存として DoD に明記。 |
| **backfill の長時間ロック**（大量 org） | chunk 更新 + `whereNull('free_plan_code')` ガードで再実行安全。additive な UPDATE のみで index 再構築を伴わない。 |

---

### P5: チケット残高会計を aigenba verbatim へ移植（per-source clamp / 消費優先 / commit-wins）

現行 `TicketLedgerService::balance()` は docblock（`app/Services/Billing/TicketLedgerService.php:217-225`）自身が「失効は未消費分も含めた全額失効として保守的に働く」と近似を認める単一 int。これを **aigenba `TicketService` の per-source 会計へ verbatim 移植**する。**debt は発明しない**（v1 の `debt` フィールド・債務保全数式・`consume_monthly_amount` の分割配賦はすべて撤回）。台帳（`ticket_ledger_entries`）は**列追加ゼロ・既存行の書き換えゼロ**、変更は `ticket_reservations` への additive 2 列と読み取り計算のみ。維持する逸脱は **amount ベース reserve**（`AnalysisPipeline.php:121` / `RenderPipeline.php:177` の `reserve($organization, $cost)`。`manual.analysis_ticket_cost`=1 / `render_ticket_cost`=3）と **reserve→commit/release の 2 フェーズ**（AGENTS.md 不変条件 #7。aigenba も同形）の 2 点のみ。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| ファイル | 内容 | 移植元（aigenba） |
|---|---|---|
| `database/migrations/2026_07_17_000500_add_consume_columns_to_ticket_reservations.php`（新規） | `ticket_reservations` へ additive 2 列: `consume_source`(string nullable) / `consume_expires_at`(timestamp nullable)。**backfill しない**（既存 Reserved 行は 2 列 null = legacy）。新規 index なし | `TicketService.php:426-436` の `ticket_reservations` insert 列 |
| `app/Enums/Billing/TicketCommitResult.php`（新規） | `Committed` / `AlreadyCommitted` / `ReleasedExpired` | `app/Enums/Billing/TicketCommitResult.php` **verbatim**（case を足さない） |
| `app/DataTransferObjects/Billing/TicketBalanceDto.php`（新規） | `monthlyRemaining` / `purchasedRemaining` / `activeReservations` / `nextExpireAt` + `totalAvailable()` + `toArray()` | `app/DataTransferObjects/Billing/TicketBalanceDto.php` **verbatim**（フィールド追加なし） |
| `app/Models/Billing/TicketReservation.php` | `consume_source` / `consume_expires_at` の `@property` + `casts()`。`$fillable` は持たない（明示代入のみ）。`HasFactory` 追加 | `app/Models/Billing/TicketReservation.php` |
| `app/Services/Billing/TicketLedgerService.php` | 中核。`balance()` を DTO 化 / `availableTrueBalance()` 追加 / `reserve()` を per-source clamp + 消費優先 + `consume_*` 固定へ / `commit()` を commit-wins + `TicketCommitResult` 化 / `releaseStale()` に失効 monthly hold を追加 / private `sumBalance()` `sumActiveHolds()` `nearestMonthlyExpiry()` `isExpiredMonthlyHold()` `expiredMonthlyHoldCondition()` 追加 / `insertIdempotent()` を `int`（挿入行数）返しへ / `lockReservationRow()` に `bool $requireReserved = true` | `TicketService.php:312-342`(balance) / `:349-453`(reserve) / `:465-588`(commit) / `:595-623`(失効述語) / `:992-1005`(releaseStale) / `:1029-1083`(availableTrueBalance / sumBalance / countActiveReservations / nearestMonthlyExpiry) |
| `app/Http/Controllers/Billing/BillingController.php:63` | `'ticketBalance' => $tickets->balance($organization)->totalAvailable()`（props は int のまま） | — |
| `app/Http/Controllers/Billing/TicketPurchaseController.php:66` | `balance:` へ `->totalAvailable()` | — |
| `app/Services/Dashboard/DashboardService.php:221` | `$balance = $this->tickets->balance($organization)->totalAvailable()`（`isLowBalance` も同値） | — |
| `app/Services/Manual/AnalysisJobService.php:81` / `RenderJobService.php:90` | 入口 fail-fast の残高を `availableTrueBalance()` へ（表示 clamp を判定に使わない） | `TicketService.php:1019-1027`「UI 表示には balance() を使うこと — 判定に使うと負残高で誤判定する」 |
| `app/Services/Manual/AnalysisPipeline.php:219-223` / `RenderPipeline.php:293-297` | commit の docblock/コメントを commit-wins へ更新（「非 Reserved は LogicException → rollback」を撤回）。**戻り値は分岐に使わない** | `TicketService.php:455-464` |
| `database/factories/Billing/TicketReservationFactory.php`（新規） | 新規テスト用（手組み禁止）。state: `forOrganization($org)` / `legacy()`(`consume_*`=null) / `monthlyHold(?CarbonImmutable $consumeExpiresAt)` / `purchasedHold()` / `stale()`。`docs/factories.md` の表へ追記 | — |

**移植しない（二重実装を作らない / 対象が無い）**: `app/Enums/CreditSource.php` は移植せず既存 `App\Enums\Billing\TicketSource`（`monthly` / `purchased`）を使う（値・意味が 1:1。`plan_monthly` へ改名すると `ticket_ledger_entries.source` 全行の書き換え = append-only 違反）。`ensureSufficient()`（`<1` 固定で AI-CUE の可変 cost に合わない。入口 gate は `availableTrueBalance() < $cost` で同義）/ `insertOrIgnore(encounter_id)` の冪等 reserve（AI-CUE は job 行の `ticketReservation()` 関連が冪等化を担う = `AnalysisPipeline.php:105-118`）/ `TicketBalanceResource`（Inertia props のため JsonResource 不使用）/ `AutoRechargeTriggerJob` の dispatch（P8a 所管）。

#### 波及変更

- **TypeScript 型定義**: **なし**。`resources/js/types/billing.ts:14`(`balance: number`) / `types/dashboard.ts:36`(`ticket_balance: number`) / `pages/Billing/Index.svelte:35`(`ticketBalance: number`) は int のまま（per-source の UI 露出は P8b 所管）。この props 形状不変が P5 の revert 安全性の根拠。
- **DTO・JsonResource**: 新規 `TicketBalanceDto`。`PurchaseTicketsPageDto.balance`（`@phpstan-type` の `balance: int`）/ `Dashboard/BillingSummaryData.ticketBalance` は**形状不変**（供給値の算出元のみ変更）。JsonResource は不使用。
- **Inertia props**: `Billing/Index` の `ticketBalance` / `Billing/PurchaseTickets` の `page.balance` / `Dashboard` の `dashboard.billing.ticket_balance` — キー・型とも不変。
- **テストファイル（更新対象・全列挙）**:
  `tests/Feature/Billing/TicketLedgerTest.php`(:26,:38,:43,:56,:61,:92,:95,:103,:115) /
  `tests/Feature/Billing/TicketGrantTest.php`(:28,:43,:58,:63,:79,:91,:101) /
  `tests/Feature/Billing/TicketRefundClawbackTest.php`(:142,:147) /
  `tests/Feature/Billing/TicketPurchaseWebhookTest.php`(:87,:106,:116,:131,:147,:163,:184,:202,:226,:256) /
  `tests/Feature/Billing/WebhookIdempotencyTest.php`(:94,:112,:123,:137,:150,:164,:214,:227,:280,:293) /
  `tests/Feature/Organization/InvitationTest.php:387` / `tests/Feature/Database/BughuntBillingSeederTest.php`(:50,:61,:83,:87) /
  `tests/Feature/Auth/RegistrationTest.php:29` / `tests/Feature/Auth/RegistrationInvitationPrefillTest.php:178` /
  `tests/Feature/Projects/AnalysisPipelineTest.php`(:166 + :294 近傍の不変条件コメント) /
  `tests/Feature/Manual/RenderPipelineTest.php`(:143,:164) / `tests/Feature/Manual/RenderStaleRecoveryTest.php:92` /
  `tests/Feature/Manual/RenderTriggerTest.php:254` / `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php`(:88,:117)。
- **更新なし・回帰確認のみ**: `tests/Feature/Billing/TicketVolumeTierTest.php` は `TicketVolumePrice::currentTierFor` のみを検証し `TicketLedgerService` を注入しない（`balance()` / `reserve()` の呼び出しゼロ = grep 確認済み）ため期待更新は発生しない。`tests/Feature/Billing/BillingPageTest.php` / `tests/Feature/DashboardTest.php` も props 形状不変で更新不要。
- **削除するテストは無い**（期待の更新のみ）。**ルート変更なし**。`docs/factories.md` に `Billing\TicketReservationFactory` 行を追加。

#### 主要な契約

```php
/** 表示用の per-source 会計 (aigenba TicketService::balance verbatim) */
public function balance(Organization $organization): TicketBalanceDto;
/** 与信・判定用の真値 (per-source clamp 後に合算。常に 0 以上。aigenba :1029 verbatim) */
public function availableTrueBalance(Organization $organization): int;
public function reserve(Organization $organization, int $amount): TicketReservation; // シグネチャ不変 (amount ベース維持)
public function commit(TicketReservation $reservation): TicketCommitResult;          // void → enum (commit-wins)
public function release(TicketReservation $reservation): void;                       // 不変 (非 Reserved は LogicException)
public function releaseStale(): int;                                                 // 失効 monthly hold を対象に追加
```

**DTO 形状（aigenba verbatim。`debt` を足さない）**

```php
/**
 * @phpstan-type TicketBalanceShape array{monthlyRemaining: int, purchasedRemaining: int,
 *   totalAvailable: int, activeReservations: int, nextExpireAt: string|null}
 */
final readonly class TicketBalanceDto
{
    public function __construct(
        public int $monthlyRemaining,   // = max($monthly, 0)   ※hold は控除しない (raw clamp)
        public int $purchasedRemaining, // = max($purchased, 0)
        public int $activeReservations, // 拘束「枚数」= SUM(amount) (aigenba は 1 枚固定のため count)
        public ?string $nextExpireAt,
    ) {}

    public function totalAvailable(): int   // aigenba verbatim
    {
        return max($this->monthlyRemaining + $this->purchasedRemaining - $this->activeReservations, 0);
    }
}
```

**バケット定義（台帳 backfill を不要にする唯一の適応。発明ではなくスキーマ事実への必然）**
- aigenba の `ticket_ledger_entries.source` は常に非 null。**AI-CUE には `source IS NULL` 行が既存する**（`kind=reserve_commit` の既存消費行 / 手動 `grant()` / `adjustment` / `release` の 0 行）。純粋な per-source SUM にすると当該行が**両バケットから消え、過去消費が帳消しになる over-grant**（金銭事故）になる。台帳は append-only（Model が update を例外化）で `source` の backfill は不可。
- よって **`purchased` バケット = `source = 'purchased' OR source IS NULL`**（いずれも無期限で寿命特性が一致）、`monthly` バケット = `source = 'monthly'`。両バケットとも `expires_at IS NULL OR expires_at > now` のみ合算（aigenba `:1045-1053` の `sumBalance` と同形）。P5 以降の消費行には `source` が載るため、null 行は P5 以前の履歴と手動 `grant()` / `adjustment` に限られる。
- `nextExpireAt` = `delta > 0 AND expires_at IS NOT NULL AND expires_at > now` の最小 `expires_at` の ISO8601（aigenba `:328-334` 同型。`amount` → `delta`）。

**hold（拘束）集計 — aigenba verbatim + amount 一般化**

```php
// aigenba :1056-1069 (countActiveReservations) の amount 版。count() → sum('amount')
private function sumActiveHolds(Organization $org, TicketSource $source, CarbonImmutable $now): int
    → status=reserved AND consume_source=$source AND NOT expiredMonthlyHold の SUM(amount)
// aigenba :322-326 (balance の activeReservations)
$activeReservations = status=reserved AND NOT expiredMonthlyHold の SUM(amount)  // legacy(null) も計上
```
`reserve` TTL 切れ（`expires_at <= now`）でも Reserved である限り枠は保持する（aigenba `:1062-1066`。commit-wins と対称。30 分超ジョブ中の同枠二重予約 = オーバーセルを防ぐ）。枠の解放は `releaseStale` の Released 化に委ねる。

**失効 monthly hold の述語（aigenba `:595-623` verbatim。legacy 枝のみ AI-CUE の事実に合わせる）**

```php
private function isExpiredMonthlyHold(TicketReservation $r, CarbonImmutable $now): bool
{
    if ($r->consume_source !== TicketSource::Monthly) return false;  // legacy(null) / purchased
    if ($r->consume_expires_at === null) return false;               // 無期限 monthly からの消費
    return $r->consume_expires_at->lessThanOrEqualTo($now);
}
// query 版 (3 値論理事故を避けるため whereNotNull で確定 boolean にする。aigenba :613-623 同型)
$q->where('consume_source', TicketSource::Monthly->value)
  ->whereNotNull('consume_expires_at')->where('consume_expires_at', '<=', $now);
```
aigenba の `$boundary = consume_expires_at ?? expires_at` 枝は、legacy 行が先頭の `consume_source` 判定で false になるため**到達不能**（`Assert` により新規行の monthly は必ず非 null 期限）。AI-CUE ではこの空き枝が「無期限 monthly からの消費」に割り当たる。

**`Assert::isInstanceOf($consumeExpiresAt, CarbonImmutable::class)`（aigenba `:417-421`）は移植しない** — これは「monthly grant は必ず期限付き」という aigenba 固有の DB 事実に依存する assertion であり、AI-CUE では前提が成立しない。`grantMonthly(Organization, int, ?CarbonImmutable $expiresAt, string, string)` は null を受け、生存する呼び出しが実際に null を渡す: `BughuntBillingSeeder.php:63-68`（無期限 100 枚）/ `StripeWebhookProcessor.php:286-291`（`invoice.paid`。D28 で seed 値 0 のため既定は不発だが、Filament `PlanResource` で `monthly_ticket_grant` を戻せば復活する）/ `TicketGrantTest.php:26,:43,:57`。移植すると当該環境の monthly reserve が全て例外で落ちる。値・ロジックの変更ではなく、移植先スキーマ事実に対する必然の措置。

**reserve（aigenba `:385-436` verbatim + amount 一般化。既存 `lockOrganizationRow()` = org 行 `lockForUpdate` 下で評価 = 直列化点は不変）**

```text
$monthly   = sumBalance(monthly)      // clamp 前の生値
$purchased = sumBalance(purchased ∪ null)
$availableMonthly   = max($monthly   - sumActiveHolds(monthly),   0)   // aigenba :394 verbatim
$availablePurchased = max($purchased - sumActiveHolds(purchased), 0)   // aigenba :395 verbatim
$consumeSource = $availableMonthly >= $amount ? Monthly : Purchased    // 消費優先 monthly → purchased
$capacity = max($availableMonthly, $availablePurchased)
if ($capacity < $amount) throw InsufficientTicketsException::forReserve($amount, $capacity)
$consumeExpiresAt = $consumeSource === Monthly ? nearestMonthlyExpiry($org, $now) : null  // null 許容
→ TicketReservation を明示代入で作成 (organization / amount / status=Reserved / expires_at=now+30min
   / consume_source / consume_expires_at)
```
`$consumeSource` は aigenba `:406-408` の `$availableMonthly > 0` の amount 一般化（amount=1 で完全一致）。不足判定も aigenba `:396` の `$availableMonthly + $availablePurchased < 1` の amount 一般化で、非負値では amount=1 のとき sum 形と max 形は同値。**単一 `consume_source`（aigenba verbatim の予約行形状）を維持する以上、実際に賄える容量は max 側**であり、sum 形を採ると「どちらの source も単独では amount を賄えない」ケースで選んだ source を超過消費し、clamp がそれを隠して最大 `amount − 1` 枚のタダ配りになる。同値な 2 つの一般化のうち金銭不変条件を壊さない側を採る（分割配賦 `consume_monthly_amount` は v1 の発明として撤回済み）。

低残高クロス検知（`TicketLedgerService.php:269-280`）は `$balance = $availableMonthly + $availablePurchased`（= `availableTrueBalance` と同一意味論）に差し替えるのみ。`$after = $balance - $amount`。閾値・通知回数の意味論は不変（`billing.ticket_low_balance_threshold`=5）。

**commit（aigenba `:465-587` verbatim。commit-wins）**

```text
lockReservationRow($reservation, requireReserved: false)     // 行ロックは維持。status guard は撤去
status === Committed                        → TicketCommitResult::AlreadyCommitted   // 冪等 no-op
lockOrganizationRow($organization)
if (isExpiredMonthlyHold($locked, $now)):                    // aigenba :489-515
    Reserved なら Released 化 + Log::warning / 既に Released なら Log::info
    → TicketCommitResult::ReleasedExpired                    // 台帳行を書かない (決定的 no-charge)
$source = $locked->consume_source ?? TicketSource::Monthly   // aigenba :522 verbatim (legacy 既定)
$expiresAt = match:
    legacy (consume_source === null) → $locked->expires_at + Log::warning   // aigenba :527-536 verbatim
    Monthly                          → $locked->consume_expires_at          // null = 無期限 monthly
    Purchased                        → null
insertIdempotent(delta = -$locked->amount, kind = ReserveCommit, source = $source,
                 expires_at = $expiresAt, reservation_id = $locked->id,
                 key = "consume:{$locked->id}")               // aigenba :539-548 (consume:{encounterId}) 同型
挿入 0 行 → Log::warning (既存 consume 行あり = 冪等だが不整合検知のため可観測化。aigenba :550-557)
status === Reserved のときのみ Committed へ (Released は据え置き + Log::info。課金の真実源は台帳)
→ TicketCommitResult::Committed
```
- **消費行に grant と同じ `expires_at` を載せる**のが精緻化の核心。バケット失効時に `+grant` と `−consume` が同時に合算から落ち、現行 docblock の「全額失効」近似が消える（aigenba `:524-537` 同型）。
- status guard 撤去で失われる二重課金防止は **`idempotency_key` UNIQUE（`consume:{reservationId}`）が肩代わり**する（既存列。列追加なし）。`insertIdempotent()` は `kind` / `reservation_id` / `description` を含む任意属性を受ける既存実装のままで足り、戻り型のみ `void → int`（挿入行数）に変える（既存呼び出し側は戻り値を捨てる）。Query Builder 直書きで Eloquent イベントを通らないが insert のみのため append-only 不変条件は保たれる（既存 `insertIdempotent` の docblock 済み契約）。
- `release()` の意味論は不変（非 Reserved は `LogicException`）。`releaseStale()` は解放条件を `expires_at <= now OR expiredMonthlyHold` へ拡張する（aigenba `:996-1005` verbatim。単一 `consume_source` のため monthly 予約は行全体が monthly = 失効時に行ごと Released にして安全）。

**既存 reserved 行（`consume_source` 未設定の旧予約）の扱い — 決定（aigenba verbatim）**

1. **migration で backfill しない**（2 列 null のまま）。デプロイ中の並行 reserve と競合せず、誤配賦を固定しないため。
2. **hold 集計**: `sumActiveHolds` は `where('consume_source', $source)` のため legacy 行は**どちらの source にも計上されない**（aigenba `:1061` verbatim）。一方 `balance()` の `activeReservations` は全 Reserved を計上するので**表示は保守側**。結果、legacy 行が reserve を拘束しない窓が TTL 30 分だけ開く（aigenba と同一の既知窓）。
3. **commit 時**: `consume_source ?? Monthly` で monthly として計上し、`expires_at = $locked->expires_at`（予約 TTL）を境界に一回限り採用する（aigenba `:527-536` verbatim。null-expiry の不滅ゴーストを作らない）。TTL 境界は既に経過しているか 30 分以内に経過するため、当該消費行は速やかに合算から外れる = **移行期の過少課金**になるが、対象はデプロイ時に in-flight だった予約のみ（TTL 30 分で消滅）で、`Log::warning('legacy reservation without consume_source')` により可観測。aigenba の移行期挙動をそのまま採り、先回り修正しない（原則 5）。
4. `releaseStale()` が 5 分 cron で TTL 切れ legacy 行を Released 化し、window は自然終息する。

**D28（月次付与 0）後に per-source clamp が実質どう働くか**

- D28 により `PlanSeeder` の全 tier `monthly_ticket_grant = 0`（seeder 変更は P1 所管）。`StripeWebhookProcessor.php:274` の既存 guard `$plan->monthly_ticket_grant <= 0` で `invoice.paid` の月次付与は抜ける（aigenba の `if ($count < 1) return;` と同形）。
- **`monthly` バケットに残る生きた source は signup grant のみ**（`billing.signup_grant_tickets`=10 / `signup_grant_expiry_days`=30。org 生涯 1 回）。加えて dev 限定で `BughuntBillingSeeder`（無期限 100 枚。`bughunt.local` + `fake_externals` + bug_hunt DB でのみ実行）。定常状態の monthly は「登録後 30 日で必ず 0 に落ちる一過性バケット」で、**`purchased` が唯一の恒常 source**になる。
- `monthlyRaw` が負になる経路は存在しない（monthly への負計上は commit のみで、reserve が `availableMonthly >= $amount` を満たした source にしか予約を立てないため）。したがって **`max($monthly, 0)` は monthly 側では常に恒等**、clamp が実効を持つのは `purchased` 側だけ = **clawback で `purchasedRaw < 0` になった場合の表示・与信からの遮蔽**の 1 点に収束する（`TicketRefundClawbackTest:147` の `-2` がこれ）。
- したがって「clamp は現行モデルでは実質 no-op（債務の逃げ道になる生きた source が無い）」という aigenba 側の判断は、AI-CUE では「**monthly（signup grant 10 枚 / 30 日）が生きている登録後 30 日間だけ、purchased の未回収債務が monthly 残高で相殺されずに見過ごされる**」という有限窓に対応する。窓は org 生涯 1 回・最大 10 枚・30 日で、その間の返金債務は `purchasedRaw` に負値として保全され、次回購入で一度だけ自然回収される（`purchasedRaw` に加算されるため）。**この挙動は aigenba 現行仕様であり先方が verbatim 移植で問題なしと回答済み。AI-CUE 側で先回り修正しない**（原則 5）。

**DB 列 / index**

```text
ticket_reservations:
  consume_source      string     nullable  // monthly|purchased (App\Enums\Billing\TicketSource)。null = legacy
  consume_expires_at  timestamp  nullable  // monthly 消費の失効境界。null = 無期限 monthly または legacy
```
**新規 index を追加しない**: hold 集計は `where(organization_id, status)` = 既存 `['organization_id','status']`、`releaseStale` は既存 `['status','expires_at']` で覆われ、予約行は org あたり TTL 30 分の少数。`ticket_ledger_entries` は**列追加ゼロ**（`source` / `expires_at` / `idempotency_key` は既存）。**ルート変更なし**。

#### PHPStan 適合チェック

- 戻り型を明示: `balance(): TicketBalanceDto` / `availableTrueBalance(): int` / `commit(): TicketCommitResult` / `insertIdempotent(): int` / `sumBalance(): int` / `sumActiveHolds(): int` / `nearestMonthlyExpiry(): ?CarbonImmutable` / `isExpiredMonthlyHold(): bool`。`commit` の呼び出し側（`AnalysisPipeline:223` / `RenderPipeline:297`）は戻り値を捨てる（level 10 は未使用戻り値を咎めない）。
- `TicketBalanceDto` は `final readonly` + `@phpstan-type TicketBalanceShape` + `toArray(): TicketBalanceShape`。`PurchaseTicketsPageDto` の `@phpstan-type` は `balance: int` のまま（形状不変）。
- `->sum('delta')` / `->sum('amount')` は mixed → 既存踏襲で `(int)` キャスト。`->value('expires_at')` は mixed → `$v instanceof CarbonInterface ? CarbonImmutable::instance($v) : null` で null 安全に絞る（AI-CUE は `immutable_datetime` cast のため `Carbon` 決め打ちの aigenba `:1083` をそのままは使えない）。
- `expiredMonthlyHoldCondition(Builder $query, CarbonImmutable $now): void` に `@param Builder<TicketReservation> $query`（aigenba `:611` 同型）。`whereNot(fn ($w) => $this->expiredMonthlyHoldCondition($w, $now))` のクロージャ引数も同型で注釈。
- `TicketReservation` へ `@property ?TicketSource $consume_source` / `@property ?CarbonImmutable $consume_expires_at` を追加し、`casts(): array<string, string>` へ `'consume_source' => TicketSource::class` / `'consume_expires_at' => 'immutable_datetime'`（既存戻り型に適合）。
- `commit()` の `$source = $locked->consume_source ?? TicketSource::Monthly;` で null 合体してから `TicketSource` に確定させ、以降 null を伝播させない。`consume_expires_at` は `?CarbonImmutable` のまま扱い、**`Assert::isInstanceOf` で非 null を強制しない**（前述の接地事実）。
- Factory は `/** @extends Factory<TicketReservation> */` + `definition(): array<string, mixed>`、Model へ `/** @use HasFactory<TicketReservationFactory> */`。
- `TicketCommitResult` は純粋 enum。呼び出し側で分岐しないため `match` の網羅義務は発生しない。
- **型の widen・baseline 化は行わない**（禁止事項 2）。

#### テスト計画（テストファースト）

**先に red を作る新規テスト**

1. `tests/Feature/Billing/TicketBalanceAccountingTest.php`
   - monthly grant +10（30 日期限）→ reserve/commit −3 → 期限到達で **grant と消費行が同時に落ち** `monthlyRemaining = 0`（現行実装なら `-3` が残るため red）。消費行の `expires_at` が grant と同じ日時であること。
   - `balance()` が DTO（`monthlyRemaining` / `purchasedRemaining` / `activeReservations` / `nextExpireAt`）を返し、**`debt` フィールドを持たない**こと（`toArray()` のキー集合を固定）。
   - **per-source clamp**: `purchased` を clawback で `-2` にした org に monthly 10 を付与 → `purchasedRemaining = 0` / `monthlyRemaining = 10` / `totalAvailable() = 10`（clamp verbatim = monthly が purchased 債務を肩代わりしない・かつ債務を打ち消しもしない）。
   - `source IS NULL` の既存消費行が **purchased バケットへ畳まれる**（帳消しにならない = over-grant しない）。
   - `nextExpireAt` が最短の未失効・正 delta の ISO8601。`activeReservations` が拘束**枚数**（`SUM(amount)`）。
   - **無期限 monthly grant のみの org で `reserve()` が例外にならず** `consume_expires_at = null` で固定される（`BughuntBillingSeeder:63` / `TicketGrantTest:26` の経路。aigenba の `Assert` を移植した場合の red）。
2. `tests/Feature/Billing/TicketConsumeOrderTest.php`
   - 消費優先: monthly 10 / purchased 10 で `reserve(3)` → `consume_source = monthly`・`consume_expires_at = 最短 monthly 期限`。monthly 使い切り後の `reserve` は `consume_source = purchased`・`consume_expires_at = null`。
   - commit で `source = monthly` の `-3` が 1 行（**source ごとの 2 行分割をしない** = 単一 consume_source の verbatim 維持）。
   - **単一 source 容量ガード**: monthly 2 / purchased 2 で `reserve(3)` が `InsufficientTicketsException`（メッセージの残高は `max(2,2)=2`）。この状態で `purchasedRaw` が負に振れない（タダ配りが発生しない）ことを台帳で固定。
   - `availableTrueBalance()` が per-source clamp 後の合算で常に 0 以上（purchased `-2` + monthly 10 → 10）。
3. `tests/Feature/Billing/TicketCommitWinsTest.php`
   - TTL 切れで `releaseStale` に Released 化された生存予約の commit → **課金され `Committed`**（status は Released 据え置き）。
   - 再 commit → `AlreadyCommitted` かつ台帳の消費行は 1 行のみ（`consume:{id}` UNIQUE）。
   - monthly 予約の `consume_expires_at` 経過 → `ReleasedExpired`・台帳行ゼロ・status Released。無期限 monthly 予約（`consume_expires_at = null`）は TTL 経過後も `ReleasedExpired` にならず課金されること。
   - `releaseStale()` が「TTL 切れ」に加え「失効 monthly hold」も解放すること。
4. `tests/Feature/Billing/TicketLegacyReservationTest.php`
   - Factory `legacy()`（`consume_*` = null）の Reserved 行が **per-source hold に計上されない**一方、`balance()->activeReservations` には計上される（表示は保守側）。
   - legacy 行の commit が `source = monthly` / `expires_at = 予約 TTL` で 1 行計上し `Committed` を返し、`Log::warning` を出すこと（移行期の verbatim 挙動を固定）。
   - legacy 行の再 commit が `AlreadyCommitted`。
5. `tests/Feature/Billing/TicketAmountBasedReserveTest.php`（**AGENTS.md #7 / ドメイン境界の回帰**）
   - `reserve($org, 5)` が amount=5 の予約 1 行を作る（1 枚固定に退化していない）。
   - `config('manual.analysis_ticket_cost')`(1) ≠ `config('manual.render_ticket_cost')`(3) の可変コストで解析/レンダが完走する。
   - reserve→commit / reserve→release の 2 フェーズが残っている（直接デクリメントが無い = 台帳 append-only）。

**既存テストの更新（削除しない）**

- `tests/Feature/Billing/TicketLedgerTest.php`: `balance()->toBe(int)` を `balance()->totalAvailable()` へ（:26,:38,:43,:56,:61,:95,:103,:115。期待値は不変 = 回帰の網）。**:85-96「committed / released の予約は再 commit / 再 release できない」は commit-wins へ期待更新** — :92 の再 commit は `LogicException` ではなく **`TicketCommitResult::AlreadyCommitted`**（台帳の消費行は 1 行・残高 7 のまま）、:93 の**再 release は引き続き `LogicException`**（release の意味論は変えない）。:98-116 `releaseStale` は期待不変（:103 = 6 / :115 = 8）。
- `tests/Feature/Billing/TicketRefundClawbackTest.php`: :142 は API 差し替え（`balance()->totalAvailable()` = 3）。**:147 の `-2` 期待は `0` へ更新** — per-source clamp 移植の結果 `purchasedRemaining = max(-2, 0) = 0` / `totalAvailable() = 0`。併せて `purchasedNet($organization)`（同ファイル :42 のヘルパ = `source=purchased` の台帳純額）が **`-2` のまま**であること（台帳では債務が保全され、clamp は表示・与信のみに効く）と、直後の `reserve(1)` が `InsufficientTicketsException` であることを検証する。P5 後は消費行にも `source=purchased` が載るため `purchasedNet` は同じく `-2`。
- `tests/Feature/Billing/TicketGrantTest.php`（:28,:43,:58,:63,:79,:91,:101）: `balance()` の戻り値変更に伴う API 差し替え。**期待値は不変**（:63「期限付き 10 が失効し無期限 5 が残る」= 両行とも monthly バケット、:79「reserve(3)→commit で 7」= monthly からの消費、:101「signup grant が期限到達で 0」= monthly バケットの失効。per-source 化後も同値であることを同時に検証する）。
- `tests/Feature/Billing/TicketVolumeTierTest.php`: **更新なし・回帰確認のみ**（`TicketVolumePrice::currentTierFor` のみを検証し `balance()` / `reserve()` を呼ばない）。
- `tests/Feature/Billing/{TicketPurchaseWebhookTest,WebhookIdempotencyTest}.php` / `tests/Feature/Organization/InvitationTest.php:387` / `tests/Feature/Database/BughuntBillingSeederTest.php`（:50,:61,:83,:87）/ `tests/Feature/Auth/{RegistrationTest.php:29,RegistrationInvitationPrefillTest.php:178}` / `tests/Feature/Projects/AnalysisPipelineTest.php:166` / `tests/Feature/Manual/{RenderPipelineTest.php:143,:164, RenderStaleRecoveryTest.php:92, RenderTriggerTest.php:254}` / `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php`（:88,:117）: `->balance($org)` → `->balance($org)->totalAvailable()` の API 差し替え。**期待値が不変であることを同時に検証する**。低残高通知はクロス判定・通知回数とも不変。
- `tests/Feature/Projects/AnalysisPipelineTest.php:294` 近傍の不変条件記述「succeeded ∧ released の非共存」を **「succeeded ∧ 無課金の非共存」へ読み替え更新**（commit-wins は Released 据え置きのまま課金するため、守るべきは「成果物を渡して無課金 = タダ乗り」と「失敗して課金」の排除であり、これは強化される）。
- テストデータは Factory 生成（手組み `new TicketReservation` を書かない）。`RefreshDatabase` グローバル・`--parallel` 前提を維持（個別 `DatabaseTransactions` を足さない）。

#### リスク

| リスク | 緩和 |
|---|---|
| **無期限 monthly grant が AI-CUE には実在する**（`BughuntBillingSeeder:63` / Filament で `monthly_ticket_grant` を戻した場合の `StripeWebhookProcessor:286`）。aigenba の `Assert::isInstanceOf(consumeExpiresAt)` を移植すると当該環境の monthly reserve が全て例外で落ちる | `nearestMonthlyExpiry()` を nullable のまま扱い Assert を移植しない。`consume_source=monthly && consume_expires_at IS NULL` = 無期限 monthly 消費（`isExpiredMonthlyHold` は false）と定義し、legacy は `consume_source IS NULL` で判別する。新規テスト 1 の必須ケースで固定 |
| **単一 consume_source のため「表示残高 4 / 各 source は 2 ずつ / cost 3」で reserve が不足になる** | 発生条件は monthly と purchased が双方非空かつ双方が cost 未満のときのみ。D28 後 monthly は signup grant 10 枚 / 30 日の一過性バケットなので窓は org 生涯 1 回。失敗は既存の `InsufficientTicketsException` 経路（購入導線への誘導）に乗り詰みにならない。sum 形ガードを採ると最大 `amount−1` 枚のタダ配りが clamp に隠れるため、金銭側を優先。新規テスト 2 で固定 |
| **commit-wins により「succeeded ∧ released」が成立し得る**（status 据え置き・課金は台帳が真実源） | 既存 guard（`AnalysisPipeline:202` の job status 検査）が cron 先勝ちケースを先に弾くため、実際に到達するのは「TTL 切れだが Running」= 成果物を渡す正当な課金ケースのみ。不変条件記述を更新し `Log::info` で可観測化（aigenba `:577-583` 同型） |
| **legacy 予約（デプロイ時 in-flight）の移行期過少課金**（monthly 計上 + `expires_at = 予約 TTL` により消費行が即失効） | aigenba `:527-536` verbatim の移行期挙動。対象は TTL 30 分以内の少数で、`Log::warning` で可観測。専用テスト 4 で挙動を固定し、`releaseStale`（5 分 cron）が window を終息させる。先回り修正しない（原則 5） |
| **legacy 予約が per-source hold に計上されず reserve を拘束しない窓**（≤ TTL 30 分） | aigenba `:1056-1069` と同一の既知窓。`balance()` の `activeReservations` は legacy も計上するため表示は保守側。window は `releaseStale` で自然終息 |
| **reserve TTL 30 分 < 長時間レンダ**。`releaseStale` が Running 中の予約を解放 → 解放枠が別 reserve に取られ、後で commit-wins が課金 → 一時的オーバーセル | aigenba と同じ既知窓。hold 側で TTL 切れを除外しない（枠を保持する）ことで窓を cron 実行間隔（5 分）に限定する。TTL 方針は現状維持（P5 のスコープを会計移植に閉じる） |
| commit の status guard 撤去で二重課金 | `idempotency_key` UNIQUE（`consume:{reservationId}`）+ org 行ロックで DB 保証。`insertIdempotent` の挿入 0 行を `Log::warning` で可観測化（aigenba `:550-557` 同型） |
| **`source IS NULL` 行の purchased 畳み込みを誤ると過去消費が帳消し**（over-grant） | 畳み込みを `sumBalance(purchased)` の 1 箇所に閉じ、新規テスト 1 が「legacy 消費行が帳消しにならない」を機械検証。台帳は無変更（append-only 維持） |
| 呼び出し側 5 箇所（`BillingController:63` / `TicketPurchaseController:66` / `DashboardService:221` / `AnalysisJobService:81` / `RenderJobService:90`）の取りこぼし | `int → TicketBalanceDto` のシグネチャ変更で PHPStan level 10 が全箇所を機械検出する |
| revert 可能性 | additive 2 列 + 読み取り計算 + props 形状不変。旧コードは `consume_*` 列と `consume:*` 台帳行を無視するだけ（台帳の置換・二重書き・差分再同期は無い） |

---

### P6

> **責務境界の確定（Codex Round 2 Warning）**: **P1 で `PersonalPlanService::activate()` 側を完成させる**
> （marker claim + grant を含む。P1 のテストがこれを期待している）。**P6 は (a) 登録経路（`CreateNewUser`）から旧 grant を
> 撤去する / (b) paid webhook 側に claim+grant ブロックを追加する / (c) `claimSignupGrantMarker()` を private 化して
> 移行専用 API を撤去する（D13）** の 3 点のみ。P1 と P6 で activate 処理を二重に定義しない。
: signup grant 契機変更 (F2) + LP 文言

付与契機を「登録 tx 内」→「プラン有効化時 (free activate / paid 成立)」へ移す。真実源は P1 で導入済みの
`organizations.signup_tickets_granted_at` (org 単位で生涯 1 回・両経路共用)。**LP/料金表の「新規登録でチケット N 枚が無料」は
この変更で事実と乖離するため同一 PR で修正する**。

**接地で判明した重要事実 (設計を単純化する)**: AI-CUE は既に
`ticket_ledger_entries_signup_grant_unique` = **partial UNIQUE (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'**
を持つ (`database/migrations/2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php:46`)。
aigenba の鍵形式 (`signup_grant:{stripeSubId}` / `signup_grant:personal:{orgId}`) は**いずれもこの述語にマッチする**ため、
鍵を aigenba 形式へ変えても **DB 層の「org 生涯 1 回」は維持されたまま**である (marker と二重の防御。marker が主・index が保険)。
よって鍵形式の変更は移行リスクを持たない。

#### 変更箇所

| ファイル | 変更内容 | 移植元 (aigenba) |
|---|---|---|
| `app/Actions/Fortify/CreateNewUser.php:96-107` | `provisionPersonalOrganization` 直後の **`grantSignupGrant($organization)` と P1 で同 tx に置いた marker 設定を「一体で」撤去**。個人組織生成のみ残す。docblock の「初回 signup grant」記述と L101-105 コメントを削除。コンストラクタの `TicketLedgerService $tickets` 依存・`use` も除去 | 対応なし (aigenba の登録経路は grant しない) |
| `app/Services/Billing/TicketLedgerService.php` | **P1 で 2 引数化済み → P6 はコード変更なし・`signup_grant:` prefix 契約の回帰確認のみ** | — |
| `app/Services/Billing/PersonalPlanService.php` (P1 で完成済) | **変更なし・回帰確認のみ**。`activate()` 内の marker 条件付き先取 + `grantSignupGrant` は **P1 で完成済み**（P1 と P6 で activate 処理を二重定義しない）。P6 で行うのは **`claimSignupGrantMarker()` の private 化（D13。移行専用 public API の撤去）** のみ | — |
| `app/Services/Billing/SubscriptionService.php` (P2 で移植済) | **`grantSignupInitialTickets(Organization $org, Subscription $sub, string $stripeSubId): void` を追加**。`DB::transaction` 内で `DB::table('organizations')->where('id',$org->id)->lockForUpdate()->get()` → marker 条件付き UPDATE → `$claimed === 1` のみ `grantSignupGrant($org, 'signup_grant:'.$stripeSubId)` | `app/Services/Billing/SubscriptionService.php:423-455` |
| `app/Services/Billing/StripeWebhookProcessor.php:245-271` | `grantMonthlyTickets` 内の `if ($billingReason === 'subscription_create') { $this->tickets->grantSignupGrant($organization); }` を **`$this->subscriptions->grantSignupInitialTickets($org, $sub, $stripeSubId)` へ差し替え**。`$stripeSubId` は `data.object.parent.subscription_details.subscription` ?? `data.object.subscription` を `stringAt` で解決し、取れなければ `$org->subscription('default')?->stripe_id` へ fallback。**いずれも解決できなければ `report()` して grant を skip (fail-closed。既存 `$invoiceId === null` と同じ作法)**。月次付与 (`monthly:{invoiceId}`) は無変更。docblock L248-250 の冪等キー記述を更新 | `app/Http/Controllers/Billing/StripeWebhookController.php:323-326` (契機は AI-CUE 既存の invoice.paid/subscription_create を維持。※下記リスク参照) |
| `resources/js/pages/Welcome.svelte:348-351` | `Free プランで今すぐ試せます。新規登録でチケット {page.signupGrantTickets} 枚が無料 (AI 解析 1 枚・動画レンダ 3 枚を消費)。` → **`Free プランで今すぐ試せます。Free プランを有効化すると、チケット {page.signupGrantTickets} 枚が無料でついてきます (AI 解析 1 枚・動画レンダ 3 枚を消費)。`** | — |
| `resources/js/pages/Pricing.svelte:168-169` (`data-testid="signup-grant-note"`) | `新規登録でチケット {N} 枚が無料でついてきます (付与から {D} 日間有効)` → **`プランを有効化するとチケット {N} 枚が無料でついてきます (付与から {D} 日間有効)`**。Welcome:349 と同一の乖離であり同一 PR で直す | — |
| `resources/js/pages/Pricing.svelte:54` (FAQ) | `…さらに新規登録でチケット ${N} 枚 (${D} 日間有効) が無料でついてくるので…` → `…さらに Free プランを有効化するとチケット ${N} 枚 (${D} 日間有効) が無料でついてくるので…` | — |

**hero CTA の整合 (L137 nav / L160 `hero-register` / L358 pricing-cta) は文言変更不要**: いずれも「無料で始める」であり、
Personal(free) が実在する以上 P6 後も事実。**チケットを約束していないため乖離しない**ことを確認済み。
`?plan=` handoff (`/register?plan=personal`) は P7 の責務で、本フェーズでリンク先は変えない。

#### 波及変更

- **TypeScript 型定義**: **なし**。`resources/js/types/marketing.ts:9,37` の `signupGrantTickets` は**名称・型とも維持**
  (aigenba も config key `billing.signup_grant_tickets` / `TicketService::signupGrantTicketCount()` の命名を保持している。
  意味は「初回無償付与の枚数」で不変であり、変わったのは契機だけ。rename は無用な波及)。
- **DTO / JsonResource**: **なし**。`LandingPageDto` (`signupGrantTickets`) / `PricingPageDto` (`signupGrantTickets`,
  `signupGrantExpiryDays`) は無変更。LP 新文言に expiry を出さないため `LandingPageDto` への列追加も不要。
  `PersonalPlanActivationResultDto{granted: bool}` は P1 で導入済 (本 PR で `granted` が初めて意味を持つ)。
- **Inertia props**: **なし**。`HandleInertiaRequests::share` は `signupGrantTickets` を**共有していない**
  (grep 済。渡しているのは `HomeController:57` と `Marketing/PricingController:49` のページ単位 props のみ)。両 Controller とも
  `TicketPricingService::signupGrantTickets()` を呼ぶだけで変更不要。
- **DI**: `CreateNewUser` から `TicketLedgerService` 依存が消える (自動解決のため binding 変更なし)。
  `StripeWebhookProcessor` に `SubscriptionService` を注入 (`TicketLedgerService` は monthly/purchased 用に残す)。
- **テストファイル (全件)**:
  - 更新: `tests/Feature/Auth/RegistrationTest.php:26-31`
  - 更新: `tests/Feature/Auth/RegistrationInvitationPrefillTest.php:176-180`
  - 更新: `tests/Feature/Billing/TicketGrantTest.php:82-136` (3 test)
  - 更新: `tests/Feature/Billing/WebhookIdempotencyTest.php:128-170`
  - 更新: `tests/js/pages/Welcome.test.ts:12-51` (L44 の期待文字列)
  - 更新: `tests/js/pages/Pricing.test.ts:79`
  - コメントのみ更新 (期待は不変・green のまま): `tests/Feature/Organization/InvitationTest.php:387-392`
  - **無変更で green を維持すべき**: `tests/Feature/Architecture/SignupGrantUniqueIndexInvariantTest.php`
    (index 述語 `LIKE 'signup_grant:%'` は新鍵もカバー = 本 PR の安全性の根拠。**この test が赤くなったら設計違反**),
    `tests/Feature/Marketing/LandingPageTest.php:18`, `tests/Feature/Marketing/PricingPageTest.php:68` (props 不変)
  - 新規: `tests/Feature/Billing/SignupGrantOnActivationTest.php`

#### 主要な契約

```php
// TicketLedgerService (鍵を外出し。claim 判定は持たない)
public function grantSignupGrant(Organization $organization, string $idempotencyKey): void

// SubscriptionService (aigenba: SubscriptionService.php:432)
public function grantSignupInitialTickets(Organization $org, Subscription $sub, string $stripeSubId): void

// PersonalPlanService (aigenba: PersonalPlanService.php:98)
public function activate(Organization $org, User $declarer): PersonalPlanActivationResultDto
```

- **冪等キー**: free = `signup_grant:personal:{orgId}` / paid = `signup_grant:{stripeSubId}` (aigenba verbatim)。
  旧 `signup_grant:org:{orgId}` は**新規発行しない**が、既存行は partial index の述語内に留まるため引き続き二重付与を弾く。
- **claim パターン (両経路で同一・交渉不可)**: 単一 `DB::transaction` 内で
  `org 行 lockForUpdate()` → `UPDATE organizations SET signup_tickets_granted_at=now() WHERE id=? AND signup_tickets_granted_at IS NULL`
  → `affected === 1` のときのみ `grantSignupGrant()`。**grant が例外なら marker ごと rollback** される (付与漏れの marker を残さない)。
- **DB 列/index**: **追加なし**。`organizations.signup_tickets_granted_at` (P1)・
  `ticket_ledger_entries_signup_grant_unique` (既存) を使うだけ。**migration ゼロのフェーズ** = revert がコード revert のみで完結。
- **ルート**: 変更なし (`onboarding.activate-personal` は P3 で導入済)。

#### PHPStan 適合チェック (level 10)

- `grantSignupGrant` の 2 引数シグネチャ（`string` 明示 + `Assert::stringNotEmpty` / `Assert::startsWith` で narrow）は **P1 で導入済み**。P6 での型変更はない。config 読みは
  既存どおり `Assert::integer` / `Assert::greaterThan` (`mixed` を widen しない)。
- `DB::table(...)->update()` は `int` を返すため `$claimed === 1` は型安全 (`> 0` にしない = 意味を「先取した」に固定)。
- webhook の sub id は `data_get` の `mixed` を既存 `stringAt(): ?string` で narrow し、**`?string` のまま握らず null 分岐で早期 return**
  (`$stripeSubId` を non-nullable にしてから `grantSignupInitialTickets` へ渡す)。
- `$org->subscription('default')` は Cashier 契約上 `Laravel\Cashier\Subscription|null` を返すため
  `instanceof App\Models\Billing\Subscription` で narrow してから使う (aigenba `PersonalPlanService:153` と同じ作法)。
- `activate()` は配列でなく `PersonalPlanActivationResultDto` を返す (禁止事項 4 の DTO 返却)。generics 新規導入なし。
- `CreateNewUser` は `TicketLedgerService` の import/プロパティを**残さず削除** (未使用 property は level 10 で検出される)。

#### テスト計画

**先に red を作る (新規)** — `tests/Feature/Billing/SignupGrantOnActivationTest.php`:

1. `登録だけではチケットが付与されず marker も立たない` — 登録 POST 後、個人組織の `balance() === 0` かつ
   `signup_tickets_granted_at === null` (現行実装では red)。
2. `Personal 有効化で marker 先取と同時に signup_grant:personal:{orgId} が付与される` — `activate()` が
   `granted === true`、`balance() === config('billing.signup_grant_tickets')`、`idempotency_key === "signup_grant:personal:{$org->id}"`、
   `expires_at` が `signup_grant_expiry_days` 後。
3. `marker 済み org を再 activate しても付与されない` — 2 の後にもう一度 `activate()` → `granted === false` /
   ledger 1 行 / 残高不変。
4. `解約→再契約で再付与されない (aigenba が backfill で塞いだ穴の回帰)` — paid 成立 (`sub_A`) で付与 →
   `customer.subscription.deleted` → 別 sub id (`sub_B`) で再度 `invoice.paid(subscription_create)` →
   **ledger の `signup_grant:%` 行は 1 件のまま・残高不変**。marker と partial index の**両方**が効く
   (marker を一時的に null にしても index が弾くことを別 assert で固定 = 二重防御の回帰)。
5. `free activate と paid webhook の競合で二重付与しない` — 同一 org に marker 未設定の状態から
   activate と `invoice.paid(subscription_create)` を連続適用 (順序 2 通り) → **先着のみ `granted`・ledger 1 行・残高 = N**。
   後着は例外にせず正常終了する。
6. `付与が失敗すると marker も残らない` — `grantSignupGrant` を throw する fake に差し替えて `activate()` →
   例外後 `signup_tickets_granted_at === null` (同一 tx rollback の固定)。
7. `paid 経路で sub id が解決できない invoice.paid は付与しない (fail-closed)` — payload から sub id を落とし、
   org に default subscription も無い → ledger 0 行・marker null。
8. `P1〜P6 の移行期に登録された org (marker 済み・付与済み) を activate しても再付与されない` —
   Factory で `signup_tickets_granted_at` + `signup_grant:org:{id}` 行を持つ org を作り `activate()` → `granted === false` / 残高不変。

**既存テストの更新 (削除しない)**:

- `tests/Feature/Auth/RegistrationTest.php:26-31` — 「LP が約束する新規登録で無償チケット」の期待を
  **`balance($personalOrg) === 0` + `signup_tickets_granted_at === null`** へ反転 (コメントも「付与はプラン有効化時」に更新)。
- `tests/Feature/Auth/RegistrationInvitationPrefillTest.php:176-180` — 同上 (「個人組織が生成され signup grant 済み」→
  「個人組織が生成されるが未付与」)。`current_organization_id` の期待は維持。
- `tests/Feature/Organization/InvitationTest.php:387-392` — 期待値 (0 件) は**不変で green**。コメントの根拠を
  「招待経由では付与しない」→「付与契機はプラン有効化時」へ更新。
- `tests/Feature/Billing/TicketGrantTest.php:82-136` — 3 test を新シグネチャへ。(a) `grantSignupGrant($org, "signup_grant:personal:{$org->id}")`
  の二重呼び出しで 1 行、(b) config 不正で停止、(c) **異なる鍵 (`signup_grant:personal:{id}` と `signup_grant:sub_x`) でも
  部分 UNIQUE index が高々 1 行に抑える** (旧 `signup_grant:sub_legacy` 直挿入 assert はそのまま活かす)。
  **追加**: `signup_grant:` prefix を持たない鍵は `InvalidArgumentException`。
- `tests/Feature/Billing/WebhookIdempotencyTest.php:128-170` — `invoicePaidPayload` に sub id
  (`data.object.parent.subscription_details.subscription`) を追加し、期待鍵を
  **`signup_grant:org:{id}` → `signup_grant:{stripeSubId}`** へ更新 + `signup_tickets_granted_at` が立つ assert を追加。
  「event_id 違いの同一 invoice 再通知で二重付与しない」既存 assert は維持。
- `tests/js/pages/Welcome.test.ts:44` — 期待文字列を `"Free プランを有効化すると、チケット 10 枚が無料"` へ。
  L51 の「無料で始める」 CTA assert は不変 (hero CTA は事実のまま)。
- `tests/js/pages/Pricing.test.ts:79` — `"プランを有効化するとチケット 10 枚が無料でついてきます (付与から 30 日間有効)"` へ。

**LP 文言と実挙動の一致 (乖離の再発検知)**: `tests/Feature/Billing/SignupGrantOnActivationTest.php` に
「LP が約束する枚数 = 有効化で実際に付与される枚数」を `config('billing.signup_grant_tickets')` 経由で突き合わせる assert を置き、
`Welcome.test.ts` / `Pricing.test.ts` は同じ config 由来値 (10) を props に渡す。**固定値を直書きしない**ことで
config 変更時に文言と実挙動が同時に追随する。

#### リスク

| リスク | 緩和 |
|---|---|
| **marker だけ残り付与されない org が生まれる** (最悪の後退。ユーザーが永久にチケットを得られない) | claim と grant を**同一 tx** に置く (grant 例外 → marker rollback)。新規テスト 6 で固定。さらに `CreateNewUser` から **marker 設定と grant を必ず一体で撤去**する (marker 設定だけ残すと全新規 org が「marked but never granted」になる。レビュー時の最重要チェック項目) |
| **grant 契機を invoice.paid のまま維持 (aigenba は `customer.subscription.created`)** = 契機が完全一致しない | 意図的。aigenba 準拠にすると trial/incomplete sub にも付与され、AI-CUE の「paid 成立で付与」という現行意味論を静かに変えてしまう。**移植単位は `SubscriptionService::grantSignupInitialTickets` (鍵・claim・ロック) で aigenba verbatim**、呼び出し点のみ AI-CUE の既存イベントを維持する。概念設計の「paid 成立」記述と一致。→ open question に上げる |
| **revert 時の付与漏れ**: P6 後〜revert までに登録した org は marker/付与とも無く、旧コードは登録時にしか付与しない | データ変更ゼロ (migration なし) のためコード revert は即時。残余は `signup_grant:org:{id}` 鍵での一括付与で救済可能 (partial index が二重付与を弾くため**無条件に流して安全**) |
| **paid 経路で sub id が解決できず grant を skip** (Stripe API version 差で payload 形状が変わる) | 2 系統 fallback (`parent.subscription_details.subscription` / `subscription`) + org の default subscription の `stripe_id` の 3 段。skip 時は `report()` で可観測化 (silent loss にしない)。新規テスト 7 |
| **LP 文言変更でコンバージョンが落ちる** (「新規登録で無料」→「有効化すると無料」) | 文言と実挙動の乖離は F-07 の根本原因そのもの (概念設計)。「無料で始める」CTA と「Free プランで今すぐ試せます」は維持しており、無料訴求の強度は保たれる |
| `TicketPricingService::signupGrantTickets()` の命名が契機と食い違って読める | aigenba も `signup_grant_tickets` / `signupGrantTicketCount()` を保持 (契機変更後も名称据え置き)。rename は TS 型・DTO・2 Controller・4 テストへ波及するだけで parity を損なうため**しない**。docblock で「付与契機はプラン有効化時」と明記して補う |

##### 起草時の未決事項（上位決定は冒頭 §横断決定 / §ユーザー判断を要する残件 を参照）

- paid 経路の grant 契機: AI-CUE 既存の `invoice.paid` (billing_reason=subscription_create) を維持するか、aigenba verbatim の `customer.subscription.created` へ寄せるか。本設計は前者を採用した (aigenba へ寄せると trial/未払い incomplete サブスクにも付与され、AI-CUE の『paid 成立で付与』という意味論が静かに変わるため)。概念設計の文言 (『paid 成立』) とも一致する。完全 parity を優先するなら P2 の SubscriptionSnapshot / webhook イベント配線ごと寄せる必要があり、P6 の範囲を超える。
- aigenba の subscription 行単位マーカー `subscriptions.signup_initial_tickets_granted_at` (SubscriptionService.php:447-455。claim 成否に関係なく更新する観測・サポート用途の列) を移植するか。真実源は org marker であり機能的には不要だが、aigenba verbatim 移植の方針に照らすと `App\Models\Billing\Subscription` への additive 列追加 (migration 1 本) が必要。本設計は P6 のスコープを『契機の切替のみ・migration ゼロ』に保つため**除外**した。
- P1 の担当範囲との境界: 本設計は『P1 = 列 + backfill + CreateNewUser での marker 同時設定 (旧契機を維持) まで』『P6 = PersonalPlanService::activate / paid webhook への claim+grant ブロック追加と CreateNewUser からの撤去』と解釈した。もし P1 が PersonalPlanService を aigenba verbatim (= grant claim ブロック込み) で移植済みなら、P1 時点で契機が二重 (登録時 + activate 時) になり marker で片方が no-op になる。その場合 P6 の当該変更は『既存の検証のみ』に縮む — P1 側の詳細設計と突き合わせて確定が必要。
- LP 文言の最終確定: 『Free プランを有効化すると、チケット {N} 枚が無料でついてきます』を採用したが、P3 の activate-personal 導線の実文言 (ボタン名・オンボーディング上の呼称) と用語を揃えるべき。P3 が『Free プラン』でなく『Personal プラン』と表示する場合、LP も同語に合わせる必要がある。

---

---

---

### P7

> **D16 の実変更（Codex Round 2 Warning: 決定が P7 本文に落ちていなかった）**: `resources/js/pages/Welcome.svelte` の
> `/register` 直リンク **3 箇所（L137 nav / L160 hero / L358 料金 CTA）を `/pricing` へ変更**し、対応する
> `tests/js/pages/Welcome.test.ts` を更新する（**P8b 所管ではなく P7 所管**）。根拠: F1 でプラン選択が必須関門になる以上、
> 登録直行リンクは「選ばせない導線」で矛盾する。aigenba の `Guest/Landing.svelte` も `/register` 直リンクを持たない。
 新規登録経路 (`?plan=` handoff + verify ソフトゲート継続)

料金表 → `/register?plan={code}` → 登録 → `verification.notice` → onboarding checkout の「プラン意図」を
aigenba と同一構造 (2 キー session + 3 規約) で一貫保持する。ゲートは P4 で確定済み・導線は P3 で実在済みが前提。

#### 変更箇所

**新規 (aigenba verbatim 移植)**

| AI-CUE (新規) | 移植元 aigenba | 内容 |
|---|---|---|
| `app/Services/Onboarding/IntendedPlanResolver.php` | `app/Services/Onboarding/IntendedPlanResolver.php` | `PENDING_KEY='onboarding.intended_plan.pending'` / `orgKey()='onboarding.intended_plan.org.{id}'` / `normalizeRaw()` (小文字化・trim・`PlanCode::tryFrom` **のみ**。**Enterprise 特判は D1/D2 により削除** = AI-CUE に case が存在せず `identical.alwaysFalse` になるため) / pending 系 = 常に書き換え (不在・null・空・改ざん → forget) / org-scoped 系 = 不在は no-op (リロード耐性) / `promotePendingToOrganization()`。**docblock 込みで verbatim**。`App\Enums\PlanCode` は P1 導入済みのものを使う |
| `app/Services/Onboarding/OnboardingReturnResolver.php` | 同名 | `orgKey()='onboarding.return_to.org.{id}'` / `normalizePath()` の多段 open-redirect 防御 (制御文字・raw+decoded 二重判定・scheme/protocol-relative・バックスラッシュ・`parse_url` の scheme/host/user/pass/port・先頭 `/` 必須・fragment drop) / put は不正値 no-op / peek は再正規化。**verbatim** |
| `app/Support/Auth/EmailVerificationContinuation.php` | 同名 (`app/Support/Auth/`) | session キー `verify_continue_organization_id` に **org id のみ**保持。`resolveUrl()` は `$user->organizations()->whereKey($id)->first()` の membership 確認を通してから route 再構築 (URL 直保持しない = IDOR/ルート変更耐性)。`remember` (登録時) → `forget` (verify 完了時)。※ AI-CUE に `app/Support/Auth/` は無いため新設 |
| `app/Http/Responses/Fortify/VerifyEmailResponse.php` | `app/Responses/Fortify/VerifyEmailResponse.php:23-27` | continuation の **forget 側ライフサイクル**。`resolveUrl` → `forget` → `continueUrl !== null` なら `redirect()->to($continueUrl)`、null なら **Fortify 既定と同値** (`redirect()->intended(config('fortify.home').'?verified=1')`) に落とす。aigenba の flash 再設計 (`ATTR_ALREADY_VERIFIED` / `auth.verify_*`) は AI-CUE に `VerifyEmailController` が無いため**移植しない** |

**改修**

- `app/Actions/Fortify/CreateNewUser.php` (現行 :52-69 に plan 系なし): `IntendedPlanResolver` を DI し、validate 通過後・`DB::transaction` 前に `rememberPendingFromForm($input)` を 1 行呼ぶ。**`intended_plan` は validation rules に足さない** (aigenba `CreateNewUser` の明示規約: 無効値でも登録は通す / 422 で止めない)。既存の招待 token 解決・`MatchesInvitationEmail` には触らない。**signup grant は P7 が P6 の後に入るため `CreateNewUser` に grant 呼び出しは既に存在しない**（P6 で撤去済み。**P7 で復活させない**。grant 契機は P6 の管轄）。
  - aigenba の `starter_migration_acknowledged` (`intended_plan==='starter'` 時のみ `accepted`) は **移植しない** — AI-CUE の Starter に「30 日後 Standard 自動移行」が存在せず、同意対象の事実が無いため (openQuestions #5)。
- `app/Http/Responses/Fortify/RegisterResponse.php` (現行 :26-33 は verification.notice redirect のみ): 移植元 `app/Responses/Fortify/RegisterResponse.php:37-72`。ただし **AI-CUE では組織生成が `CreateNewUser` の tx 内で完了済み**のため provisioning 呼び出しは持ち込まず、分岐だけを移植する。
  - **招待経由分岐** (aigenba の `InvitationContinuation::pull` 相当): AI-CUE は招待受諾を `CreateNewUser` 内で行い成立時は個人組織を作らないため、判定は `$user->organizations()->where('is_personal', true)->first()`。null (= 招待組織へ参加) なら `forgetPending()` して現行どおり `verification.notice` へ (continuation を張らない)。
  - 通常分岐: `promotePendingToOrganization($personalOrg)` → `EmailVerificationContinuation::remember($session, $personalOrg->id)` → `verification.notice`。既存の `wantsJson() → 201` 後方互換は**維持**し、session 副作用を先に実行してから返す。
- `app/Providers/FortifyServiceProvider.php`
  - `configureViews()` の `registerView` (:182-203): 既存 `socialProviders` / `invitationEmail` / `Cache-Control: no-store` を保ったまま `'intendedPlan' => IntendedPlanResolver::normalizeRaw($request->query('plan'))?->value` を追加 (移植元 :141-157)。正規化は resolver 一本化 (Provider 側で分岐を書かない)。
  - `verifyEmailView` (:219): `'continueUrl' => EmailVerificationContinuation::resolveUrl($user instanceof User ? $user : null, $request->session())` を渡す (移植元 :173-184)。`status` は AI-CUE の `VerifyEmail.svelte` が持たないため追加しない。
  - `register()`: `$this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class)` を追加 (既存 :83 の RegisterResponse と同型)。
- `resources/js/pages/Auth/Register.svelte`: `intendedPlan?: PlanCode | null` prop を受け、`useForm` に `intended_plan: intendedPlan` を含めて**常に送信** (null も送る = resolver の `array_key_exists` 規約で stale pending を消す)。SSO href に `plan` を伝播 (下記)。
- `resources/js/pages/Auth/VerifyEmail.svelte`: `continueUrl?: string | null` を受け、非 null のときのみ二次 CTA「あとで認証する（プラン選択へ進む）」= `router.visit(continueUrl)` を出す (移植元 `Auth/VerifyEmail.svelte:45-49`)。既存の再送信・ログアウトは不変。
- `resources/js/pages/Pricing.svelte:124`: `<Button href="/register">` → `` href={`/register?plan=${encodeURIComponent(plan.code)}`} `` (移植元 `Guest/Pricing.svelte:164,189`)。`page.isAuthenticated` 分岐 (`/billing`) はそのまま。nav の `/register` (:82) は plan なし = pending forget (fresh state) で aigenba 規約どおりのため変更しない。
- **P3 導線への配線** (クラスを置くだけでは handoff が閉じないため P7 で結線する。移植元の呼び出し位置に対応):
  - P3 の onboarding checkout controller: `rememberForOrganizationFromQuery($request, $organization)` → `peekForOrganization($organization)` を props の preselect に (移植元 `Onboarding/OnboardingController.php:71,80`)。
  - P3 の activate-personal controller: `returnResolver->peekForOrganization()` → `forgetForOrganization()` で復帰先へ (移植元 `Onboarding/ActivatePersonalController.php:64-65`)。
  - P4 の `app/Http/Middleware/RequireActiveSubscription.php`: 遮断時に `returnResolver->rememberForOrganization($org, '/'.ltrim($request->path(), '/'))` (移植元 `Http/Middleware/RequireActiveSubscription.php:80`)。
  - `app/Http/Controllers/Billing/BillingController.php`: checkout 成功/キャンセル着地で `intendedPlanResolver->forgetForOrganization($organization)` (移植元 `Billing/BillingController.php:593,606,717,728`) と `returnResolver->peek/forget` (:294-297)。
- **SSO 経路** (aigenba `SsoController.php:150` 相当。AI-CUE は POST ではなく GET のため `rememberPendingFromQuery` を使う):
  - `resources/js/pages/Auth/Register.svelte` の `ssoHref`: `/auth/{provider}/redirect/register?terms_accepted=1` に `&plan={intendedPlan}` を付与。
  - `app/Http/Controllers/Auth/SocialAuthController.php::redirect` (:43 の register 分岐直後): `intent === 'register'` のとき `rememberPendingFromQuery($request)`、`intent === 'login'` のとき `forgetPending()` (aigenba `redirectLogin` と同型)。
  - 同 `callback` の register 成立後 (:118 `$service->register()` 直後): 個人組織を取得して `promotePendingToOrganization($personalOrg)`。redirect 先 (`dashboard`) は P7 では変えない。

#### 波及変更

- **TypeScript 型定義**
  - `resources/js/types/Auth.ts` (**新規**。移植元 `resources/js/types/Auth.ts:9-15`): `RegisterPageProps { intendedPlan: PlanCode | null; socialProviders: string[]; invitationEmail: string | null }`。※ aigenba の `consentVersion` は AI-CUE の SSO 同意が query `terms_accepted=1` 方式のため含めない。
  - `resources/js/types/plan.ts`: **`export type PlanCode = 'personal' | 'starter' | 'standard';`**（D1 の 3 case と一致。**`'enterprise'` を含めない**）
  - `VerifyEmail.svelte` の Props に `continueUrl?: string | null` (ページ内 interface。既存は inline 定義のため d.ts 追加不要)。
  - `resources/js/types/marketing.ts` は **変更なし** (`PricingPlanShape.code` を既に持つ)。
- **DTO / JsonResource**: **なし** (`intendedPlan` / `continueUrl` はいずれもスカラー prop。既存 `PricingPageDto` は無改変)。
- **Inertia props (追加分のみ)**
  - `Auth/Register`: `+ intendedPlan: string|null`
  - `Auth/VerifyEmail`: `+ continueUrl: string|null`
  - P3 checkout ページ: `+ intendedPlan` (preselect。P3 の props 型に追加)
- **テストファイル**
  - 更新: `tests/Feature/Auth/RegistrationTest.php` (登録成功時の session キー期待を追加。既存 verification.notice 期待は維持)、`tests/Feature/Auth/RegistrationInvitationPrefillTest.php` (招待経由で pending が消費されない/継続が張られないことを追加)、`tests/Feature/Auth/FortifyResponseTest.php` (`VerifyEmailResponseContract` bind 追加による verify 着地の期待)、`tests/Feature/Auth/EmailVerificationGateTest.php` (continuation 無し時に既定着地が変わらないことの非退行)、`tests/Feature/Auth/SocialAuthTest.php` (SSO register の pending → promote)、`tests/Feature/Marketing/`(Pricing) の CTA href 期待。
  - 新規: `tests/Unit/Services/Onboarding/IntendedPlanResolverTest.php`、`tests/Unit/Services/Onboarding/OnboardingReturnResolverTest.php`、`tests/Unit/Support/Auth/EmailVerificationContinuationTest.php`、`tests/Feature/Auth/RegisterPlanHandoffTest.php`、`tests/Feature/Auth/RegisterVerifyFlowTest.php`、`tests/Feature/Onboarding/OnboardingCheckoutPlanHandoffTest.php` (移植元: aigenba `tests/Unit/Services/Onboarding/*`・`tests/Feature/Auth/RegisterPlanHandoffTest.php`・`tests/Feature/Auth/RegisterVerifyFlowTest.php`・`tests/Feature/Onboarding/RegisterRedirectsToCheckoutTest.php`)。

#### 主要な契約

```php
final class IntendedPlanResolver {
    public const PENDING_KEY = 'onboarding.intended_plan.pending';
    public function __construct(private readonly Session $session) {}
    public static function orgKey(Organization $organization): string;   // onboarding.intended_plan.org.{id}
    public static function normalizeRaw(mixed $value): ?PlanCode;        // 無効/非string → null (Enterprise 特判は無し。D2)
    public function rememberPendingFromQuery(Request $request): void;    // 'plan' 不在 → forget
    public function rememberPendingFromForm(array $input): void;         // 'intended_plan' key 不在 → forget
    public function peekPending(): ?PlanCode;  public function forgetPending(): void;
    public function rememberForOrganizationFromQuery(Request $r, Organization $o): void; // 不在 → no-op
    public function peekForOrganization(Organization $o): ?PlanCode;
    public function forgetForOrganization(Organization $o): void;
    public function promotePendingToOrganization(Organization $o): void; // pending は必ず forget で消費
}
final class OnboardingReturnResolver {
    public static function orgKey(Organization $o): string;              // onboarding.return_to.org.{id}
    public static function normalizePath(mixed $value): ?string;         // same-origin 内部 path のみ (query 保持/fragment drop)
    public function rememberForOrganization(Organization $o, string $path): void; // 不正値 no-op
    public function peekForOrganization(Organization $o): ?string;  public function forgetForOrganization(Organization $o): void;
}
final class EmailVerificationContinuation {
    private const string SESSION_KEY = 'verify_continue_organization_id';
    public static function remember(Session $s, int $organizationId): void;
    public static function resolveUrl(?User $u, Session $s): ?string;    // membership 確認 → route 再構築
    public static function forget(Session $s): void;
}
```

- `RegisterResponse::toResponse($request): JsonResponse|RedirectResponse` (既存シグネチャ維持)。DI に `IntendedPlanResolver` を追加。
- `VerifyEmailResponse::toResponse($request): RedirectResponse` / `VerifyEmailResponseContract` に singleton bind。
- session キー (真実源。**DB 変更・route 追加は本フェーズでは無し**):
  `onboarding.intended_plan.pending` / `onboarding.intended_plan.org.{id}` / `onboarding.return_to.org.{id}` / `verify_continue_organization_id`。
- 依存 route (P3 が定義): **`onboarding.checkout`（引数なし。D21）**。`EmailVerificationContinuation` は **組織 ID を session に保持**しつつ、復帰時に **membership を確認してから引数なしの `route('onboarding.checkout')` を生成**する（Codex Round 2 の修正案どおり）。
- URL 契約: `/register?plan={PlanCode::value}` (未知値は `tryFrom` が null を返し無効扱い。Enterprise は AI-CUE に存在しない = 未知値として自然に null)。

#### PHPStan 適合チェック (level 10)

- `normalizeRaw(mixed): ?PlanCode` — `is_string()` ガードで mixed を絞ってから `tryFrom`。`session->get()` の戻り値 mixed は `is_string($raw) ? normalizeRaw($raw) : null` で narrowing (aigenba と同形)。
- `EmailVerificationContinuation::resolveUrl` — `is_int($organizationId)` で mixed session 値を絞り、`?User` の null 分岐を明示。`$user->organizations()` は `BelongsToMany<Organization, User>` generics が `User` モデル側に既存 (`OrganizationProvisioningService:64` が同型で level 10 通過済み)。
- `RegisterResponse` — `$request->user()` は mixed のため既存 aigenba 同様 `Assert::isInstanceOf($user, User::class)` (Webmozart は AI-CUE `CreateNewUser` で既に使用) で narrow。`->where('is_personal', true)->first()` は `?Organization` として `@var` ではなく generics 解決 (relation の型が付いていなければ `/** @var Organization|null */` を 1 箇所)。
- `verifyEmailView` クロージャ — `$request->user()` を `$user instanceof User ? $user : null` で絞ってから渡す (aigenba :180 と同形)。
- ~~`normalizeRaw` の Enterprise 分岐~~ → **D1/D2 で確定: 分岐を削除**し `tryFrom` 結果のみで正規化する (AI-CUE に Enterprise case は作らない)。`identical.alwaysFalse` は発生しない。**baseline / widen での回避は禁止**。
- `Session` を constructor 注入する resolver を、singleton の `RegisterResponse` が保持する点: `session.store` の Store は per-request に `setId/start` で再初期化される同一インスタンスのため安全 (aigenba も singleton bind)。
- 戻り値は全て具象型 (`RedirectResponse` / `JsonResponse|RedirectResponse` / `?string` / `?PlanCode`)。`response()->json()` 直書き無し。

#### テスト計画

**先に red を作る (新規)**

1. `tests/Unit/Services/Onboarding/IntendedPlanResolverTest.php` — pending 規約 3 状態 (key 不在 → forget / 有効 → put / 無効・Enterprise・配列・null・空文字 → forget)、org-scoped 規約 (不在 → **no-op = 既存値が残る**、無効 → forget)、`promotePendingToOrganization` (pending は必ず消費 / pending 無しなら org key を触らない)、`orgKey` 形状。
2. `tests/Unit/Services/Onboarding/OnboardingReturnResolverTest.php` — open-redirect データセット (`https://evil`, `//evil`, `/\evil`, `%2F%2Fevil`, `javascript:...`, `/path?a=1#frag`, `user:pass@`, port, `%0d%0a` 混入) の accept/reject と query 保持・fragment drop。peek の再正規化 (session 改ざん値 → null)。
3. `tests/Unit/Support/Auth/EmailVerificationContinuationTest.php` — remember→resolveUrl が checkout URL、**他組織 id を session に注入しても null** (membership 確認 = IDOR 防御)、非 int / null user → null、forget 後は null。
4. `tests/Feature/Auth/RegisterPlanHandoffTest.php` — `POST /register` (`intended_plan=starter`) で pending が forget され org key に `starter` が promote される / `enterprise`・`foo`・欠落は promote されない (aigenba `RegisterPlanHandoffTest` を Factory + `whereBlind` 検索でそのまま移植)。
5. `tests/Feature/Auth/RegisterVerifyFlowTest.php` — 登録 → `verification.notice` に `continueUrl` prop が乗る (Inertia assert) → verify 完了で continuation が forget され checkout へ着地 / continuation 無しは既定着地 (非退行)。
6. `tests/Feature/Onboarding/OnboardingCheckoutPlanHandoffTest.php` — 登録 → checkout GET で `intendedPlan` が preselect / plan なしリロードで preselect が消えない (org-scoped no-op 規約)。
7. `GET /register?plan=personal|enterprise|<不正>` の `intendedPlan` prop (Inertia assert)。招待経由 (`invitationEmail` あり) の `Cache-Control: no-store` 非退行を同ファイルで維持。
8. **招待競合**: 招待 token 保持 + `?plan=starter` で登録 → 招待組織へ参加 / **個人組織を作らない** / pending は forget / continuation は張らない / 既存の招待受諾着地が不変。

**既存の更新 (削除しない)**

- `tests/Feature/Auth/RegistrationTest.php` — **P7 は P6 の後に入るため、signup grant の期待は「登録時は未付与」**（P6 で `CreateNewUser` から旧 grant を撤去済み。**登録時付与の期待を復活させない**）。verification.notice / current_organization_id の期待は維持し、session キー（intended plan）の期待を追加する
- `tests/Feature/Auth/RegistrationInvitationPrefillTest.php` / `tests/Feature/Auth/FortifyResponseTest.php` / `tests/Feature/Auth/EmailVerificationGateTest.php` / `tests/Feature/Auth/SocialAuthTest.php` — 上記のとおり期待更新。
- Pricing の feature/コンポーネントテスト — CTA href 期待を `/register?plan={code}` へ更新。
- UI: `Register.svelte` / `VerifyEmail.svelte` は既存 `AuthLayout` 配下で primitive 構成を変えないため page-shell-structure / ds-purity / atomic-import-graph / lucide-scoped-import は現状維持 (新規 hex・新規 lucide import を入れない)。

#### リスク

- **stale pending の誤 promote**: 中断した OAuth 等で残った pending が、次の plan 無し登録に promote される。→ pending 規約「常に書き換え (key 不在は forget)」を `CreateNewUser` / SSO redirect の**両入口**で守る。Register.svelte が `intended_plan: null` を**必ず送る**ことが前提のため、送信漏れを feature テスト (4) で検知。
- **招待経由との競合** (最重要): 招待受諾が `CreateNewUser` tx 内で完結する AI-CUE では、RegisterResponse が「個人組織の有無」で分岐する。招待受諾の実装が将来 personal org も作るよう変わると誤って continuation を張る。→ テスト 8 を回帰網に固定。
- **open-redirect**: `OnboardingReturnResolver` は verbatim 移植し独自簡略化しない。peek 側の再正規化を落とすと session 汚染で外部遷移し得る (テスト 2 が検知)。
- **P3/P4 未実装での前倒し**: `EmailVerificationContinuation::resolveUrl` が存在しない route 名を引くと `RouteNotFoundException` で verify 画面が 500。→ P3 マージ済みを DoD にし、route 名を P3 の実装と一致させる。
- **verify 着地の後退**: `VerifyEmailResponseContract` bind 追加は既存 verify 完了フローを置換する。continuation 無し時に Fortify 既定 (`fortify.home` + `?verified=1`) と**同値**であることを非退行テストで固定。
- **PII キャッシュ**: registerView に prop を足しても `Cache-Control: no-store` の条件 (`invitationEmail !== null && !== ''`) を変えない。`?plan=` はキャッシュ抑止対象にしない (PII でない)。
- **rollback**: 本フェーズは additive (session キー + prop + CTA href)。コード revert のみで復帰可 (DB 変更・migration なし)。残留 session キーは旧コードが無視する。

##### 起草時の未決事項（**すべて解決済み**。下記は履歴。正は冒頭 §横断決定 D1-D27）

- ~~P3 の onboarding route の正式名とシグネチャ~~ → **D21 で確定: `onboarding.{checkout,activate-personal,billing-required}`（route parameter なし・current-org スコープ）**。continuation は組織 ID を保持し membership 確認後に引数なしで route 生成する。
- P1 の `PlanCode` に `Enterprise` case が含まれるか。`normalizeRaw` の Enterprise 除外は aigenba verbatim 移植の中核だが、case が無いと PHPStan level 10 が `identical.alwaysFalse` を出す (baseline 禁止)。含まれない場合、除外分岐を落とす (= 非 verbatim) か P1 に Enterprise を足すかの上位判断が要る。
- SSO (`SocialAuthController` + `SocialAccountService`) の `?plan=` handoff を P7 に含めるか。aigenba は `SsoController::redirectRegister` で `rememberPendingFromForm` する形を持つが、担当フェーズ記述には SocialAuthController が挙がっていない。含めないと `/register?plan=starter` から「Google で登録」を押した瞬間に plan 意図が失われる (silent dead-end)。本設計は含める前提で書いた。
- `resources/js/pages/Welcome.svelte` の `/register` CTA (3 箇所: :137 nav / :160 hero / :358 料金 CTA) の扱い。aigenba の `Guest/Landing.svelte` は `/register` 直リンクを一切持たず `/pricing` へ誘導する (プラン選択を先に通す) 構造で、AI-CUE の直リンクは parity 外。本設計では P7 は変更せず landing 導線 parity は P8b の管轄とした。P8b で拾うか、P7 で `?plan=personal` を付けるか要判断。
- aigenba `CreateNewUser` の `starter_migration_acknowledged` (intended_plan=starter 時のみ `accepted` 必須) を移植しない判断の確認。AI-CUE の Starter に「30 日後 Standard へ自動移行」という事実が無く、同意対象が存在しないため。P1 の Starter 定義が自動移行を持つなら移植が必要。
- `VerifyEmailResponse` (`VerifyEmailResponseContract` bind) の新設が P7 スコープでよいか。担当フェーズ記述には無いが、`EmailVerificationContinuation` の forget 側ライフサイクル (aigenba `VerifyEmailResponse.php:23-27`) がここにしか無く、これを欠くと continuation が session に残留して verify 後も checkout へ飛び続ける。

---

---

---

### P8a: 裏チャージ = オートリチャージ (opt-in・既定 off)

残高が閾値を割ったら Stripe invoice で自動補充する。AI-CUE には実装・語彙が **0 件** (audit `ticket-charge-1` / `billing-subscription-2`)。aigenba の `AutoRechargeService` (59KB / 43 メソッド) を中核として移植する。決済実行を伴うため、冪等キーと並行制御を契約として固定する。

**前提フェーズ**: P5 (`availableTrueBalance` = 与信真値残高)、P2 (Gateway 系置換)。P7 (onboarding) は下記「スコープ境界」参照。

#### 変更箇所 (ファイルパス + 何をするか。移植元 aigenba のパスを併記)

**マイグレーション (additive のみ)**

| AI-CUE (新規) | 内容 | 移植元 |
|---|---|---|
| `database/migrations/XXXX_create_ticket_auto_recharges_table.php` | 設定 1 org 1 行。`organization_id` unique / `enabled` default false / `threshold_count` / `max_count` / `stripe_payment_method_id` / `failure_count` / `disabled_reason` / 同意 snapshot 4 列 / `created_by_user_id`。`max_count > threshold_count` CHECK は pgsql/mysql のみ (sqlite は ALTER ADD CONSTRAINT 非対応 → driver guard) | `database/migrations/2026_07_09_000100_create_ticket_auto_recharges_table.php` |
| `database/migrations/XXXX_create_ticket_auto_recharge_attempts_table.php` | 試行の状態機械。`attempt_ulid` unique / `status` / `quantity` / `unit_amount` / `stripe_price_id` / `stripe_invoice_id` unique nullable / `stripe_payment_intent_id` / `failure_code` / `resolved_at`。**partial unique `tar_attempts_org_pending_unique ON (organization_id) WHERE status='pending'`** | `2026_07_09_000200_create_ticket_auto_recharge_attempts_table.php` (verbatim) |
| `database/migrations/XXXX_add_stripe_invoice_id_to_ticket_ledger_entries.php` | `ticket_ledger_entries.stripe_invoice_id` nullable + index。invoice アンカー付与の逆引き用 (現行は `stripe_checkout_session_id` のみ) | aigenba `ticket_ledger_entries.stripe_invoice_id` 相当 |

> **partial unique index の前例は AI-CUE 内に既にある**: `2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php` (pgsql/sqlite 限定 + 非対応 driver は `RuntimeException` で fail-closed)。attempts の partial unique も**同一の driver guard 様式に揃える** (aigenba の raw `DB::statement` は driver チェックが無いのでそこだけ AI-CUE 側の既存前例に合わせる)。

**Enum / DTO**

| AI-CUE (新規) | 移植元 |
|---|---|
| `app/Enums/Billing/AutoRechargeDisabledReason.php` (`PaymentFailures` / `User`) | 同名 (verbatim) |
| `app/Enums/Billing/AutoRechargeAttemptStatus.php` (`Pending`/`Paid`/`Failed`/`Canceled`) | 同名 (verbatim) |
| `app/Enums/CheckoutIntent.php` に `SetupPaymentMethod = 'setup_payment_method'` | `app/Enums/CheckoutIntent.php` |
| `app/DataTransferObjects/Billing/AutoRechargeConsentDto.php` (`version` のみ) | 同名 (verbatim) |
| `app/DataTransferObjects/Billing/AutoRechargeConsentTermsDto.php` | 同名 (verbatim) |
| `app/DataTransferObjects/Billing/AutoRechargeSettingsDto.php` (17 フィールド) | 同名 (verbatim) |
| `app/DataTransferObjects/Billing/DefaultPaymentMethodDto.php` | 同名 (verbatim) |
| `app/DataTransferObjects/Billing/OffSessionChargeResultDto.php` / `InvoiceStateDto.php` | 同名 (gateway 戻り値) |
| `app/Enums/Billing/BillingNotificationType.php` に 4 case 追加 | aigenba 同 enum L27-30 |

> `PurchaseTicketsDto` は **AI-CUE では `PurchaseTicketsPageDto`** が現行名。P8a では `autoRechargeEnabled: bool` の 1 フィールド追加に留める (formState/resumeUrl/returnTo 等は audit `ticket-charge-5`/`-6` = **P8b と別 finding** のため P8a では触らない)。

**Model / Factory**

- `app/Models/Billing/TicketAutoRecharge.php` ← aigenba 同名 (verbatim。`disabled_reason` は enum cast)
- `app/Models/Billing/TicketAutoRechargeAttempt.php` ← aigenba 同名
- `database/factories/Billing/{TicketAutoRechargeFactory,TicketAutoRechargeAttemptFactory}.php` ← aigenba 同名 (テストデータ手組み禁止のため必須)

**Service / Gateway**

- `app/Services/Billing/AutoRechargeService.php` ← aigenba 同名。**3 点だけ AI-CUE 側へ接地** (下記「主要な契約」)。
- `app/Services/Billing/AutoRechargeGateway.php` (interface) + `CashierAutoRechargeGateway.php` + `Fakes/FakeAutoRechargeGateway.php` ← aigenba `Contracts/StripeGatewayInterface.php` の auto-recharge 6 メソッドのみを切り出す。**AI-CUE の既存規約 (`TicketCheckoutGateway` + `Fakes/FakeTicketCheckoutGateway` の狭い gateway + fake bind) に合わせる** — aigenba の 41KB 単一 `CashierStripeGateway` は持ち込まない。
- `app/Services/Billing/TicketLedgerService.php` に `grantAutoRecharge()` 追加 + **`reserve()` に trigger dispatch を追加** ← aigenba `TicketService.php:771` / `:558-566`

**Job / Command / Notification**

- `app/Jobs/Billing/{AutoRechargeTriggerJob,ExecuteAutoRechargeAttemptJob,HandleAutoRechargeChargeFailureJob,SetDefaultPaymentMethodJob}.php` ← aigenba 同名
- `app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php` ← aigenba 同名 (verbatim) + `routes/console.php` に `Schedule::command('billing:reconcile-auto-recharge')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();` ← aigenba `routes/console.php:22`
- `app/Notifications/Billing/{AutoRechargeFailed,AutoRechargeDisabled,AutoRechargeActionRequired,AutoRechargeEnabled}Notification.php` ← aigenba 同名。AI-CUE の `TracksBillingDelivery` / `TracksBillingReminderDelivery` contract を実装する (既存 `BillingNotificationDispatcher::sendOnce` / `sendReminderOnce` が Assert で delivery key 一致を強制するため)。

**Controller / Request / Route / Config**

- `app/Http/Controllers/Billing/BillingController.php`: `updateAutoRecharge` / `startAutoRechargeSetup` / `index` に setup 着地解決 (`resolveAutoRechargeLanding` 相当 = 303 + flash) を追加 ← aigenba `BillingController.php:737` / `:778` / `:216`
- `app/Http/Requests/Billing/{UpdateAutoRechargeRequest,StartAutoRechargeSetupRequest}.php` ← aigenba 同名 (`ProhibitsProtectedKeys` trait は AI-CUE にも同名で存在すること要確認)
- `routes/web.php`: `POST /billing/auto-recharge` → `billing.auto-recharge.update` / `POST /billing/auto-recharge/setup` → `billing.auto-recharge.setup`。**current-org スコープ** (aigenba の org-slug スコープは移植しない — audit `billing-subscription`「課金画面の組織スコープ」で二重化を避ける判断済み)。既存 billing.* と同じく**課金ゲート allowlist** に置く。
- `config/billing.php`: `auto_recharge` ブロック追加 ← aigenba `config/billing.php:31-47` (`default_threshold=5` / `default_max=50` / `max_count=1000` / `max_failures=3` / `consent_version` / `pending_expiry_hours=24` / `setup_pending_window_minutes=30`)

**UI (最小。情報密度の作り込みは P8b)**

- `resources/js/components/features/billing/AutoRechargeCard.svelte` ← aigenba 同名。T071 primitive (`molecules/PageHeaderSection` 配下) に載せ、`Billing/Index.svelte` に組み込む。**P8a に含める理由**: これが無いと opt-in する導線が存在せず「1 フェーズで完結」しない (機能が到達不能なまま merge される)。

#### 波及変更

**TypeScript 型定義**
- `resources/js/types/billing.ts`: `AutoRechargeProps` 新規 (= `AutoRechargeShape` と exact 対)、`AutoRechargeConsentTerms` 新規、`PurchaseTicketsPageProps` に `autoRechargeEnabled: boolean` 追加、`BillingIndexProps` に `autoRecharge: AutoRechargeProps` 追加 ← aigenba `resources/js/types/Billing.ts`
- `resources/js/types/notification.ts`: 通知種別に auto_recharge 系 4 種を追加 (既存 union に追随)

**DTO / JsonResource**
- 新規 DTO 6 本 (上記)。`AutoRechargeSettingsDto` は `@phpstan-type AutoRechargeShape` を持ち、`subscription 有無に依存せず常に非 null` (free 組織も対象)
- `PurchaseTicketsPageDto` に `autoRechargeEnabled` 追加 (+ `PurchaseTicketsPageShape` 更新)
- `BillingController::index` の Inertia props に `autoRecharge` 追加 (**DTO 経由。`response()->json()` 直書きなし**)
- JsonResource の新設は無し (auto-recharge は API 公開面を持たない)

**Inertia props**
- `Billing/Index`: `autoRecharge: AutoRechargeShape` 追加
- `Billing/PurchaseTickets`: `autoRechargeEnabled: bool` 追加

**テストファイル (新規)**
- `tests/Feature/Billing/AutoRechargeServiceTest.php`
- `tests/Feature/Billing/AutoRechargeEndpointTest.php`
- `tests/Feature/Billing/AutoRechargeWebhookTest.php`
- `tests/Feature/Billing/AutoRechargeReconcileTest.php`
- `tests/Feature/Billing/TicketAutoRechargeModelTest.php`
- `tests/Feature/Billing/AutoRechargeTriggerTest.php` (reserve 起点の発火 = AI-CUE 固有)
- `tests/js/components/features/billing/AutoRechargeCard.test.ts` + `tests/js/support/autoRechargeProps.ts`
- (参考: aigenba 側対応 `tests/Feature/Billing/{AutoRechargeServiceTest,AutoRechargeEndpointTest,AutoRechargeWebhookTest,AutoRechargeReconcileTest,TicketServiceAutoRechargeGrantTest,TicketAutoRechargeModelTest}.php`)

**テストファイル (更新。削除しない)**
- `tests/Feature/Billing/TicketLedgerTest.php` — `reserve()` に trigger dispatch が増える (`Queue::fake()` 期待の追加。既存の低残高通知期待は**維持**)
- `tests/Feature/Billing/BillingPageTest.php` — Index props に `autoRecharge` 追加
- `tests/Feature/Billing/TicketRefundClawbackTest.php` — invoice アンカー付与 (`stripe_invoice_id` 列) の逆仕訳ケース追加
- `tests/Feature/Billing/WebhookIdempotencyTest.php` / `TicketPurchaseWebhookTest.php` — `invoice.paid` の auto_recharge 分岐追加に伴う期待更新
- `tests/Feature/Billing/BillingNotificationDispatchTest.php` — 新 4 種の dispatch 期待
- `tests/Architecture/*` — `MassAssignmentSafetyTest` / `FormRequestProhibitedKeyTest` 相当の inventory に新 Model / FormRequest が乗る (aigenba も同 2 テストが auto-recharge を掴んでいる)

#### 主要な契約

**冪等キー (全経路の合流点)**

| アンカー | キー | 保証 |
|---|---|---|
| 付与 | `recharge:{stripeInvoiceId}` (`ticket_ledger_entries.idempotency_key` UNIQUE) | webhook / 同期 pay / リコンサイルのどれが先でも **1 invoice = 1 回付与** |
| Stripe 呼び出し | `idempotencyKeyBase = "auto-recharge:{attempt_ulid}"` | invoice create / pay の再送で同一 invoice に収束 (プロセス死からの復帰でも二重 invoice を作らない) |
| pending | partial unique `tar_attempts_org_pending_unique` | **org あたり pending attempt は同時 1 つ** (アプリロックの最終防衛) |

**並行制御 (契約)**
- ロック名 `billing:auto-recharge:{orgId}` / **TTL 180 秒** (`LOCK_TTL_SECONDS`)。`updateSettings` (停止 + invoice 終端) と `executeAttempt` (invoice create/pay) が**同一ロック**を取るため、**停止後課金が構造的に起こらない**。TTL は Stripe client timeout より十分長く取る (TTL 失効による直列化の破れ防止)。
- `createAttemptLocked` は `Organization` 行を `lockForUpdate()` してから残高評価〜起票する。**`reserve()` と同順の org 行ロック**でロック順序の交差を作らない (AGENTS.md 金銭ドメイン並行制御)。
- lock 取得失敗はバックグラウンド経路では **structured no-op** (Log::info)、ユーザー明示操作 (`updateSettings`) のみ `CheckoutInProgressException` へ変換。

**`AutoRechargeService` 主要シグネチャ** (aigenba verbatim)

```php
public function isEnabledFor(Organization $org): bool
public function settingsFor(Organization $org, bool $canManage): AutoRechargeSettingsDto
public function updateSettings(Organization $org, User $user, bool $enabled, int $threshold, int $max, ?AutoRechargeConsentDto $consent): TicketAutoRecharge
public function consentTermsFor(): AutoRechargeConsentTermsDto
public function maybeCreateAttempt(Organization $org): ?TicketAutoRechargeAttempt
public function executeAttempt(TicketAutoRechargeAttempt $attempt): void
public function recordSuccessfulCharge(Organization $org, TicketAutoRechargeAttempt $attempt, string $invoiceId, int $amountPaid, int $amountDue, ?string $paymentIntentId): void
public function handleChargeFailure(Organization $org, TicketAutoRechargeAttempt $attempt, ?string $failureCode, bool $requiresAction): void
public function terminateAndFail(Organization $org, TicketAutoRechargeAttempt $attempt): void
public function terminateAndCancel(TicketAutoRechargeAttempt $attempt): void
/** @return array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int} */
public function reconcile(): array
public function applySetupCompletion(Organization $org, string $paymentMethodId): bool
```

**AI-CUE 接地のための 3 点の差分 (機械移植できない箇所。いずれも実コード由来)**

1. **trigger 点は `commit` ではなく `reserve`**。aigenba は `TicketService::commit` で `-1` が書かれた経路のみ発火する (`TicketService.php:558-566`)。**AI-CUE は `balance() = SUM(delta) − SUM(reserved)` のため実効残高が減るのは `reserve`、`commit` は拘束 −amount と台帳 −amount が相殺して balance 不変** (`TicketLedgerService.php:270-280` の docblock が明示)。よって `AutoRechargeTriggerJob::dispatch` は **`reserve()` の `DB::afterCommit` に、既存 `notifyTicketBalanceLow` と同居させる**。audit `ticket-charge-9` が「同じ『残高が減った』イベントへの応答」と両者を同一点として記録しており、これが接地された対応点。閾値判定は Job 側で再評価するため過剰 dispatch は無害 (pending 検査 + partial unique が吸収)。
2. **`grantAutoRecharge` は ledger インライン 1 本書き** (aigenba の ledger + `ticket_purchases` 両建て + 片肺検証は移植しない)。AI-CUE に `ticket_purchases` テーブルは無く、返金逆仕訳の正本は `ticket_ledger_entries` の `payment_intent_id` + `purchase_amount` インライン (`TicketLedgerService.php:152-215` `clawbackPurchasedByPaymentIntent`)。**clawback は `payment_intent_id` で引くため、auto-recharge invoice の PI を書けば既存の返金経路がそのまま効く** — `stripe_invoice_id` 列の追加のみで invoice アンカーが成立し、`ticket_purchases` 正本化は不要。audit `ticket-charge-4` は「単独での先行導入はしない」「invoice アンカーの返金逆引きが必須になる場合のみ」としており、本節はその必須要件を additive 列 1 本で満たす。**両建てが 1 本になるので片肺検証 (`ledgerInserted !== $purchaseInserted` の RuntimeException) は構造的に不要**になる。
   ```php
   public function grantAutoRecharge(Organization $org, int $count, string $stripeInvoiceId, int $amount, ?string $paymentIntentId): void
   // insertIdempotent($org, "recharge:{$stripeInvoiceId}", [
   //   delta: $count, kind: Grant, source: TicketSource::Purchased, expires_at: null,
   //   stripe_invoice_id: $stripeInvoiceId, payment_intent_id: $paymentIntentId, purchase_amount: $amount ])
   // Assert::greaterThan($count, 0); Assert::greaterThanEq($amount, 0);  // credit balance 全額適用で 0 は正当
   ```
3. **`resolveVolumeTier` / `PURCHASE_MAX_COUNT` の出典**。aigenba は `TicketService::PURCHASE_MAX_COUNT` / `resolveVolumeTier`。AI-CUE は **`TicketVolumePrice::PURCHASE_MAX_COUNT` / `PURCHASE_MIN_COUNT` (モデル定数)** と **`TicketVolumePrice::currentTierFor(int $count): TicketVolumeTier`** (`TicketPricingService` は表示専用)。`AutoRechargeService` はこちらを使う。`config('billing.auto_recharge.max_count')` は `TicketVolumePrice::PURCHASE_MAX_COUNT` と単一真実源で揃える。

**amount cross-check (fail-closed)**
`recordSuccessfulCharge` は `attempt.unit_amount * attempt.quantity === invoice.amount_due` を検証し、不一致は `RuntimeException`。**照合対象は `amount_due` であって `amount_paid` ではない** (customer credit balance 適用で `amount_paid < amount_due` は正当)。付与額 (`purchase_amount`) には**実回収額 `amount_paid`** を記録する。

**状態機械 (終端保証)**
`pending → paid` (冪等付与後) / `pending → failed` (invoice void/delete **成功後のみ**。`failure_count+1`) / `pending → canceled` (終端成功後のみ。`failure_count` 増分なし)。**open invoice を残したまま終端しない** = 遅延支払いによる二重課金・二重付与の構造的排除。invoice 終端に失敗したら pending 維持 → リコンサイルが再試行。SCA (`authentication_required`) は**終端させない** (pending 維持 + 日次リマインダ。期限切れ = `pending_expiry_hours` 超過で failed)。

**再同意判定 (単一述語)**
`reconsentRequiredFor(TicketAutoRecharge $config, int $max): bool` を **UI 表示 (`settingsFor.requiresReconsent`) / 設定更新 (`updateSettings.needsConsent`) / attempt 起票停止 (`createAttemptLocked`) の 3 箇所で共有**する。条件 = version 不一致 ∨ 同意記録欠落 ∨ `$max > consented_max_count` ∨ 現行カタログ最大請求額 > `consented_max_amount`。**同意金額は必ずサーバ再計算** (`resolveVolumeTier($max)->unitAmount * $max`)。client hidden の金額は信用しない (`AutoRechargeConsentDto` は `version` のみを受ける)。

**quantity 確定**
`quantity = min($config->max_count - availableTrueBalance($org), TicketVolumePrice::PURCHASE_MAX_COUNT)`、`Assert::greaterThan($quantity, 0)`。attempt 作成時に**一度だけ**確定し以降 `attempt.quantity` が真実源。`availableTrueBalance` が構造的に非負 (P5 の `max(...,0)+max(...,0)`) であることが `quantity <= max_count` = 同意上限 invariant の根拠。**P5 の当該契約に依存する**ため、P5 側 docblock に「変更時は AutoRechargeService の契約も見直す」旨を追記する。

**webhook 分岐 (`StripeWebhookProcessor`)**
現行 `invoice.paid` は `GRANTING_BILLING_REASONS = ['subscription_create','subscription_cycle']` の allowlist で弾くため、auto-recharge invoice (`billing_reason='manual'`) は**月次付与に誤混入しない** (既存ガードで安全)。新たに `metadata.purpose === 'auto_recharge'` かつ `metadata.recharge_attempt_ulid` を持つ invoice を `recordSuccessfulCharge` へ、`invoice.payment_failed` を `HandleAutoRechargeChargeFailureJob` へ振る分岐を追加。**metadata は照合専用** (org 解決・認可には使わない = 既存 `grantPurchasedTickets` の tenant キー不信規約に従う)。

**通知 dedup**
`AutoRechargeFailed` / `AutoRechargeDisabled` / `AutoRechargeEnabled` → `sendOnce($org, $type, invoiceId: "recharge:{$attempt->attempt_ulid}", ...)` (`sendOnce` は `Assert::stringNotEmpty($invoiceId)` のため invoice 未作成でもキーが立つ ULID を使う)。`AutoRechargeActionRequired` → `sendReminderOnce($org, $type, dedupKey: "auto_recharge_sca:{$invoiceId}:{JST Y-m-d}", ...)` (日次で再通知 = 放置失効の防止)。

**ルート**
```
POST /billing/auto-recharge        → billing.auto-recharge.update  (Gate: manage-billing)
POST /billing/auto-recharge/setup  → billing.auto-recharge.setup   (Gate: manage-billing)
```
両者とも課金ゲート allowlist (既存 billing.* と同扱い)。閲覧 (Index の card 表示) は組織メンバー全員、変更は owner/admin。

#### PHPStan 適合チェック (level 10 / widen・baseline 禁止)

- **`reconcile(): array` は `@return array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int}`** を付し、`ReconcileAutoRechargeAttempts::handle` 側は `Cache::lock(...)->block(5, fn (): array => ...)` の戻りが `mixed` になるため **`/** @var array{...} $stats *​/` で narrowing** (aigenba 同様)。`$stats['recovered_paid']` の存在は shape で保証。
- **`Cache::lock()->block()` のクロージャ戻り値は `mixed`**。`updateSettings` / `maybeCreateAttempt` / `executeAttempt` の各所で `/** @var T $result */` + `Assert` により narrowing する (aigenba と同型)。
- **`$attempt->organization` は `BelongsTo` の nullable 解決**のため `Assert::isInstanceOf($org, Organization::class)` で narrowing (`reconcile` ループ / `executeAttempt`)。`$attempt->created_at` は `Carbon|null` → `Assert::notNull` 後に `CarbonImmutable::instance()`。
- **`OffSessionChargeResultDto::$amountPaid` / `$amountDue` は `int|null`** (Stripe 応答由来) → `Assert::integer()` で narrowing してから `recordSuccessfulCharge(int, int)` へ渡す。**戻り型に nullable を漏らさない**。
- **`config()` 戻り値は `mixed`** → `intConfig(string $key, int $default): int` / `currentConsentVersion(): string` の private helper で `is_int` / `is_string` 判定して返す (AI-CUE 既存 `config()->integer()` ヘルパがあればそちらを優先。`TicketLedgerService` は `config()->integer('billing.ticket_low_balance_threshold')` を使用しているため**そちらに揃える**)。
- **generics**: `TicketAutoRecharge` / `TicketAutoRechargeAttempt` に `/** @use HasFactory<TicketAutoRechargeFactory> */`、`organization(): BelongsTo` に `@return BelongsTo<Organization, $this>`。Factory は `/** @extends Factory<TicketAutoRecharge> */`。
- **DTO 返却**: `settingsFor` / `consentTermsFor` は DTO を返し、Controller は `->toArray()` を Inertia props に渡す。`response()->json()` 直書きなし。`@phpstan-type AutoRechargeShape` / `@phpstan-import-type PurchaseTierShape from PurchaseTierDto` で TS 側と shape を固定。
- **`disabled_reason`** は `AutoRechargeDisabledReason|null` cast → DTO へは `$config?->disabled_reason?->value` (`string|null`) で渡す (null 安全連鎖)。
- **`isUniqueViolation(QueryException $e): bool`** は driver 別 SQLSTATE (`23505` pgsql / sqlite) 判定。`$e->getCode()` は `mixed` のため文字列比較前に narrowing。

#### テスト計画 (テストファースト。既存テストは削除せず期待を更新)

**先に red を作るテスト (実装前に書く)**

`tests/Feature/Billing/AutoRechargeServiceTest.php` (新規)
- `既定は off` — 設定行が無い org で `isEnabledFor` false / `settingsFor.enabled` false / trigger しても attempt が起票されない (**opt-in の回帰**)
- `有効化は fail-closed` — default PM 無しで `updateSettings(enabled: true)` → `ValidationException` (422)、同意 version 不一致 → `ValidationException`
- `同意金額はサーバ再計算` — client が偽の金額を送っても `consented_max_amount = resolveVolumeTier(max)->unitAmount * max` が記録される
- `再同意の 3 箇所一致` — 価格改定後に `settingsFor.requiresReconsent === true` **かつ** `createAttemptLocked` が起票しない (UI 文言と実挙動の一致)
- `quantity は attempt 作成時に一度だけ確定` — 作成後に残高が動いても `attempt.quantity` 不変
- `停止後課金の禁止` — pending attempt がある状態で `updateSettings(enabled: false)` → invoice 終端 + `canceled` 遷移、以降 `executeAttempt` は no-op
- `連続失敗で自動無効化` — `max_failures` (3) 回目の failed で `enabled=false` + `disabled_reason=payment_failures` + `AutoRechargeDisabled` 通知
- `SCA は終端しない` — `requires_action` で pending 維持 + `failure_count` 増えない + `AutoRechargeActionRequired` 通知

`tests/Feature/Billing/AutoRechargeTriggerTest.php` (新規。**AI-CUE 固有の要**)
- `reserve で閾値クロス → AutoRechargeTriggerJob が dispatch される` (`Queue::fake()`)
- **`既存の低残高通知が消えていない`** — 同一 reserve で `notifyTicketBalanceLow` も発火する (**parity の名での機能後退を防ぐ回帰**。audit `ticket-charge-9`)
- `commit では dispatch されない` (balance 不変のため) — AI-CUE 特有の意味論を固定
- `reserve が rollback したら dispatch されない` (`afterCommit` の保証)
- **`amount ベース reserve が壊れていない`** — 可変コスト (`reserve($org, 7)`) が従来どおり成立 (ドメイン境界の回帰)
- **`reserve→commit/release の 2 フェーズが維持されている`** (AGENTS.md 不変条件 #7)

`tests/Feature/Billing/AutoRechargeWebhookTest.php` (新規)
- **`二重課金・二重付与しない`** — 同一 invoice の `invoice.paid` を 2 回処理しても ledger は 1 行 (`recharge:{invoiceId}` 冪等)
- `webhook と同期 pay の競合` — どちらが先でも付与 1 回、`attempt.status=paid` 1 回
- `auto-recharge invoice が月次付与に混入しない` — `billing_reason='manual'` の invoice.paid で `grantMonthly` が呼ばれない (既存 allowlist の回帰)
- `amount_due 不一致で fail-closed` — `RuntimeException` + 付与なし
- `amount_paid < amount_due (credit balance 適用) は正当` — 付与成立 + `purchase_amount = amount_paid`

`tests/Feature/Billing/AutoRechargeReconcileTest.php` (新規。**5 分岐すべて**)
- (i) invoice 未作成 + 15 分超 → 再実行 (`retried`)
- (ii) Stripe 上 paid だが webhook 未着 → **付与回収** (`recovered_paid`)。**terminal drop の唯一のセーフティネット**
- (iii) SCA 待ち → 日次リマインダ (`sca_reminded`)、同日 2 回目は dedup で送られない
- (iv) `pending_expiry_hours` 超過 → SCA は failed / それ以外は canceled (`expired`)
- (v) enabled + 閾値割れ + pending なし → 取りこぼし起票 (`triggered`)
- `1 attempt の例外が他 org の回収を止めない` (隔離)
- `lock 競合で exit 1` (`ReconcileAutoRechargeAttempts` の `LockTimeoutException` 経路)

`tests/Feature/Billing/AutoRechargeEndpointTest.php` (新規)
- `manage-billing を持たない member は 403` (update / setup 両方)
- `他 org の設定を触れない` (IDOR)
- `enabled=true で consent_version 欠落 → 422`
- `max_count <= threshold_count → 422` / `max_count > config max → 422`
- setup 着地が 303 + flash (GET で副作用を起こさない)

`tests/Feature/Billing/TicketAutoRechargeModelTest.php` (新規)
- **`org に pending は同時 1 つ`** — 並行起票で `tar_attempts_org_pending_unique` が効き、後着は **500 にせず no-op** (`isUniqueViolation` 吸収)
- `max_count > threshold_count` CHECK (pgsql のみ。sqlite は skip)
- append-only / mass assignment 安全性

`tests/js/components/features/billing/AutoRechargeCard.test.ts` (新規)
- 既定 off の表示 / PM 未登録時はカード登録 CTA / `requiresReconsent` 時に「再同意まで自動購入は行われません」/ `canManage=false` で操作不可
- `tests/js/support/autoRechargeProps.ts` に props factory (aigenba 同名を移植)

**既存テストの更新 (削除禁止・期待の更新のみ)**
- `tests/Feature/Billing/TicketLedgerTest.php` — `reserve()` の dispatch 追加に伴い `Queue::fake()` を追加。**既存の低残高通知期待はそのまま残す**
- `tests/Feature/Billing/BillingPageTest.php` — Index props に `autoRecharge` が載る期待を追加
- `tests/Feature/Billing/TicketRefundClawbackTest.php` — `stripe_invoice_id` 経由付与の返金按分ケースを追加 (既存 checkout 経路の期待は不変)
- `tests/Feature/Billing/WebhookIdempotencyTest.php` / `TicketPurchaseWebhookTest.php` — `invoice.paid` 分岐追加の期待更新
- `tests/Feature/Billing/BillingNotificationDispatchTest.php` — 新 4 type の dispatch 期待
- arch テスト (`MassAssignmentSafetyTest` / `FormRequestProhibitedKeyTest` 相当) の inventory に新 Model / FormRequest が乗る

**arch テスト (UI 分)**
`AutoRechargeCard.svelte` + `Billing/Index.svelte` が `page-shell-structure` / `ds-purity` (token のみ・hex 直書き禁止) / `atomic-import-graph` / `lucide-scoped-import` を満たす。

**共通 DoD**: Factory 必須 (手組み禁止) / 個別 `DatabaseTransactions` 不使用 (`RefreshDatabase` グローバル・`--parallel`) / Stripe は `FakeAutoRechargeGateway` を bind (実 API を撃たない)。

#### リスク (副作用・後退の可能性と緩和)

| リスク | 緩和 |
|---|---|
| **二重課金 (最重大)** | 3 層: (1) Stripe idempotency key `auto-recharge:{ulid}` で invoice create/pay が収束、(2) `tar_attempts_org_pending_unique` で org あたり pending 1 つ、(3) 付与は `recharge:{invoiceId}` の ledger UNIQUE。加えて **failed/canceled は invoice 終端 (void/delete) 成功後のみ** = open invoice を残して終端しないため遅延成功による二重課金が構造的に起きない |
| **停止後課金** | `updateSettings` と `executeAttempt` が**同一ロック** `billing:auto-recharge:{orgId}`。lock 内で `enabled` を再確認してから invoice 作成 → 停止側は実行完了後にしか pending を終端できない |
| **課金済み・付与なし (webhook terminal drop)** | `MAX_PROCESSING_ATTEMPTS = 8` で webhook は恒久 drop し得る。**リコンサイル (ii) が唯一のセーフティネット**。scheduler 15 分毎 + `onOneServer()` + `withoutOverlapping()`。リコンサイルが止まると資金回収済み・チケット未付与が滞留するため、**`ReconcileAutoRechargeAttempts` の失敗監視を運用条件に含める** |
| **迷子 invoice (プロセス死)** | `stripe_invoice_id` の永続化を `pay` より**必ず前**に行う。復帰時は同一 key base で Stripe 冪等により同一 invoice が返る |
| **trigger 点の変更 (commit→reserve) が aigenba と非対称** | 意図的。AI-CUE の `balance()` は reserve で減り commit で不変 (実コード docblock + audit `ticket-charge-9`)。commit に置くと**閾値クロスを取り逃す**。`AutoRechargeTriggerTest` で両方向 (reserve で発火 / commit で発火しない) を固定 |
| **低残高通知との二重通知 (体験後退)** | audit `ticket-charge-9` は「AI-CUE 固有の低残高通知は parity の名で削除しない」と明記。P8a は**両立**させる (opt-in・既定 off のため既存挙動は無変更)。補充成功時に通知を抑制するかは**要判断 (openQuestions)** |
| **`ticket_purchases` を持たない差分** | `payment_intent_id` + `purchase_amount` + 新 `stripe_invoice_id` で返金逆引きが成立することを `TicketRefundClawbackTest` で固定。aigenba の片肺検証は両建てが無いため不要。**parity 逸脱として記録** (audit `ticket-charge-4` は「単独先行導入はしない」= 本判断と整合) |
| **P5 依存 (`availableTrueBalance`)** | P5 未達だと閾値判定が現行の保守的近似 (過小評価) になり**過剰補充**する。P5 マージ後に着手する順序を DoD に固定。P5 側 docblock に AutoRechargeService の契約依存を明記 |
| **ロック TTL 失効による直列化の破れ** | TTL 180 秒 (Stripe client timeout より十分長い)。`block` 待機は短く (3〜10 秒) し、競合時は no-op → リコンサイルが再試行 |
| **消費者保護 / 特商法** | 自動課金・カード保管・同意上限は規約影響あり (audit が `requiresProductDecision: True`)。同意文言の実質を変える改定では **`consent_version` を上げる** = `reconsentRequiredFor` 経由で既存同意が自動失効し自動購入が停止する (fail-closed)。**既定値と同意文言は人の決定必須 (openQuestions)** |
| **rollback** | 全変更が additive (新テーブル 2 + 列 1 + 新 route/Job/Command)。**コード revert で即時復帰** — 既定 off のため設定行が存在せず、`reserve` の dispatch も消える。pending attempt が残る場合のみ、revert 前に `billing:reconcile-auto-recharge` を 1 回流して収束させる (資金回収済みは必ずチケットになる) |

##### 起草時の未決事項（上位決定は冒頭 §横断決定 / §ユーザー判断を要する残件 を参照）

- 【製品判断・必須】既定値と同意文言。config billing.auto_recharge の default_threshold=5 / default_max=50 / max_count=1000 / max_failures=3 は aigenba の値。AI-CUE は 1 encounter=1 枚ではなく解析/レンダの可変コスト消費 (reserve は amount ベース) のため、『閾値 5 枚』『上限 50 枚』が AI-CUE の消費単価で妥当かは不明。同意文言 (consent_version の実質: 開始残高・補充枚数・1 回上限額の提示形式・停止方法・即時課金可能性・カードの取得手段) は特商法/規約に影響するため人の決定が必須 (audit ticket-charge-1 / billing-subscription-2 が requiresProductDecision: True)。
- 【スコープ境界】signup-funding 事前同意層 (aigenba T1003/T1004) をどのフェーズが持つか。aigenba の SignupFundingChoice / recordPreConsent / ReuseSubscriptionPaymentMethodJob / applyReusedPaymentMethod / AutoRechargeSettingsDto の pendingAutoEnable・setupPending(b) は『登録直後の funding 選択関門』(audit registration-funnel-8) に依存するが、概念設計の P7 は IntendedPlanResolver / OnboardingReturnResolver / EmailVerificationContinuation / RegisterResponse / registerView(?plan) / verifyEmailView(continueUrl) / ?plan= handoff のみで funding 選択を含まず、8 フェーズのどこにも割り当てられていない。本設計は DTO の shape は aigenba verbatim (pendingAutoEnable / setupPending を保持) としつつ、呼び出し側 (onboarding funding gate) を P8a 外に置いた。結果 pendingAutoEnable は常に false になる。(a) P8a に含めて P7 の onboarding へ funding 選択を足すか、(b) P8c / 後続タスクに分離するか、(c) consent_version の既定を aigenba の 'v2' (= サブスク決済カードの流用を明示) ではなく 'v1' (カード登録経路のみ) にするか、の 3 点の決定が要る。
- 【parity 逸脱の承認】ticket_purchases 正本化を P8a に含めないこと。AI-CUE は返金逆仕訳の正本を ticket_ledger_entries のインライン (payment_intent_id + purchase_amount) で持ち、clawback は PI で引くため、stripe_invoice_id 列を 1 本足せば invoice アンカーの返金逆引きが成立する (aigenba の ledger + ticket_purchases 両建て + 片肺検証は不要になる)。audit ticket-charge-4 は『単独での先行導入はしない』『finding #1 を採用する場合のみ前提タスクとして先行』としており本判断と整合するが、『aigenba にある物は aigenba の形で移植する』方針からの意図的逸脱であるため明示承認が要る (reserve の amount ベース維持に次ぐ 2 つ目の非 parity 項目になる)。
- 【P2 との境界】Gateway の粒度。aigenba は 41KB の単一 CashierStripeGateway + StripeGatewayInterface に全 Stripe 呼び出しを集約するが、AI-CUE は狭い gateway (TicketCheckoutGateway / SubscriptionCheckoutGateway + Fakes/) を並べる規約。本設計は AI-CUE 規約に従い AutoRechargeGateway (invoice create/pay/terminate/retrieve + default PM + setup checkout の 6 メソッド) を新設したが、概念設計 P2 の『Gateway 系を置換』が aigenba の単一 fat gateway 移植を意味するなら P8a の gateway は P2 に吸収されるべき。P2 の gateway 設計確定が先行する必要がある。
- ~~【運用条件】billing:reconcile-auto-recharge の失敗監視~~ → **D20 で確定: 監視アラートの実装 / 既存監視への接続確認を P8a の DoD に必須化する**（設計項目として明文化し「注意喚起」で終わらせない）。(以下は起草時の記述) billing:reconcile-auto-recharge の失敗監視。webhook が MAX_PROCESSING_ATTEMPTS=8 で恒久 drop した『課金済み・付与なし』を回収する唯一の経路であり、この scheduler が静かに止まると資金回収済み・チケット未付与が滞留する (ユーザー被害 + 会計不整合)。AI-CUE 側に scheduler 失敗の監視/アラート機構が既にあるか、無ければ本フェーズで何を DoD に含めるかの決定が要る。
- 【体験判断】オートリチャージ有効時に既存の低残高通知 (notifyTicketBalanceLow) を抑制するか。audit ticket-charge-9 が『通知と自動補充を併存させるか (補充成功時は通知不要等) をセットで人判断する』と明記。本設計は既定 off の opt-in 機能で既存挙動を変えないため『両立』を既定としたが、有効化した org では『残高が少なくなっています』→ 直後に自動補充完了、という順で 2 通が届きノイズになる。

---

---

---

### P8b: 課金 UI parity（Guest/Pricing 三層 + Billing/Plans + PlanCard + PurchaseTickets 状態機械 + Index 情報密度）+ 監査「判断不要 15 件」の消化

前提（v2）: P1〜P7 / P8a がマージ済み。すなわち **`PlanCode` 5 case**・**`plans.is_active` は P1 で `true` seed 済み（再公開フェーズは存在しない）**・**`App\Enums\Billing\OnboardingBillingState` + `BillingAccess::state()`（aigenba verbatim）**・**per-bucket `TicketBalanceDto`（`monthlyRemaining` / `purchasedRemaining` / `totalAvailable` / `activeReservations` / `nextExpireAt`。**`debt` は存在しない**）**・**D28 により全 tier `monthly_ticket_grant = 0`**・`?plan=` handoff（P7）・AutoRecharge（P8a）が既にある。本フェーズは **UI 層と、それを支える Controller / DTO のみ**を触る。会計（`TicketLedgerService`）と Stripe 境界（`*Gateway`）には手を入れない（監査 ticket-charge-11 の 4 分割境界を維持）。

**所管の境界**: **billing contact（列 / フォーム / props / 更新 Action）・checkout 着地 feedback（`resolveBillingFeedback` / feedback バナー）・subscription checkout 用 attempt token は P9 所管**であり本フェーズに登場しない。**チケット決済の `ticketAttemptToken` は既存冪等マシンに必要なため維持・安定化する**（P9 の subscription 用とは型名で区別）。

##### 監査「A. 判断不要 = 機械的に aigenba へ寄せられる (15 件)」の消化台帳

| # | finding | 本フェーズでの対応 |
|---|---|---|
| 1 | registration-funnel-7 `EmailVerificationContinuation` | **P7 で完了**。P8b では触らない。 |
| 2 | registration-funnel-11 `OnboardingReturnResolver` | **P7 で完了**。P8b では触らない。 |
| 3 | registration-funnel-14 オンボ外枠の T071 primitive 整合 | **P3 で完了**（`Onboarding/{Checkout,BillingRequired}.svelte`）。P8b は同じ規約を新設 `Billing/Plans.svelte` に適用する（aigenba の `PageHeaderSection` + breadcrumbs / `max-w-6xl` 直書きは移植しない = 監査 ticket-charge-7 が「breadcrumbs はサイト共通ナビ方針として別 slice」と結論済み）。 |
| 4 | pricing-plans-6 `Billing/_helpers/PlanCard.svelte` | **P8b で実施**（下記 (a)）。移植元 `/tmp/aigenba/resources/js/pages/Billing/_helpers/PlanCard.svelte`。 |
| 5 | pricing-plans-7 `PricingPlanCard` の headerBadges / contactLabel | **headerBadges snippet を追加**（← `/tmp/aigenba/resources/js/components/molecules/PricingPlanCard.svelte`）。**contactLabel は追加しない** — AI-CUE に enterprise の Plan 行が無く（`PlanSeeder` = personal / starter / standard）、監査 action 自体が「enterprise プラン採否に連動」と条件付き（原則 4）。既存コメント（`/workspace/resources/js/components/molecules/PricingPlanCard.svelte:7-8`）どおり大規模利用はカード外バナーの責務を維持する。 |
| 6 | billing-subscription-4 `SubscriptionService` / `SubscriptionSnapshot` | **P2 で完了**。P8b は `BillingAccess::state()` の返す `OnboardingBillingState` を読むだけで、gate 判定を UI / Controller で再実装しない。 |
| 7 | billing-subscription-6 料金プラン画面 `Billing/Plans` | **P8b で実施**（下記 (a)）。 |
| 8 | billing-subscription-7 サブスク checkout の冪等・着地 feedback | **P9 へ移譲**。`Billing/Plans` の POST body は **`{plan_code}` のみ**とする（aigenba の `attempt_token` 同梱は P9 の成果物が揃ってから）。 |
| 9 | billing-subscription-10 `BillingCustomerSynchronizer` / 請求先情報 | **P9 へ移譲**（列 + 更新 Action + 同期 job + フォーム + props の全体）。 |
| 10 | billing-subscription-11 Customer Portal の事前ガード | **P8b で実施**（下記 (c)）。移植元 `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:978-1002`。 |
| 11 | billing-subscription-14 `Billing/Index` の構造と情報密度 | **P8b で実施**（下記 (d)）。外枠は既に T071 準拠のため是正不要。プラン一覧を `Billing/Plans` へ移設し、Index を請求ダッシュボード（現在プラン / per-bucket 残高 / quota / 導線）へ寄せる。auto-recharge カード実体は **P8a 所管**（P8b は差し込み位置のみ）。 |
| 12 | billing-subscription-15 `billing-required` 画面 | **P3 で完了**。P8b では触らない。 |
| 13 | ticket-charge-5 購入フォームの状態機械 + attempt_token 安定化 | **P8b で実施**（下記 (b)）。対象は `ticketAttemptToken`。 |
| 14 | ticket-charge-8 spot 単価の出典 | **合わせない（対応不要）**。監査 action の宿題「production の livemode / synced_at 必須チェック」は実装済み（`/workspace/app/Models/Billing/TicketVolumePrice.php:91-96`）。単一テーブル集約を維持。 |
| 15 | ticket-charge-11 サービス分割構造 | **合わせない（逆行しない）**。会計 = `TicketLedgerService` / 導線・状態 = Controller + DTO / Stripe = `*Gateway` の 4 分割境界を守り、`resolveResumablePurchase` は冪等 Checkout マシンの一部として `TicketCheckoutService` に置く。 |

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

**(a) プラン提示の専用ページ化（bs-6 / pp-6 / pp-7）**

- 新規 `/workspace/resources/js/pages/Billing/Plans.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/Plans.svelte`
  - `AppLayout > PageContainer > PageHeader > PageContent`（T071 primitive。aigenba の `PageHeaderSection` + breadcrumbs は採らない）。title「プラン比較」/ description「現在のプランの変更・新規契約ができます」/ icon `CreditCard` は verbatim。
  - `data-testid="plans-grid"` に `PlanCard` を並べる（aigenba :171-189 verbatim）。
  - `canSwitchTo` / `disabledReasonFor`（aigenba :60-90）を **AI-CUE に事実が存在する分岐だけ verbatim 移植**する: `!canManage` →「プランを変更する権限がありません」/ `currentPlanCode === plan.code` →「現在ご利用中のプランです」/ `isPersonal(plan)` →「パーソナルプラン（無料）は個人専用のため、こちらからは変更できません」。**移植しない分岐**: enterprise（Plan 行なし）/ starter 自動移行（AI-CUE に自動移行が無い = 監査 pricing-plans-4 は要プロダクト判断）/ `pendingPlanCode`（変更予約が無い = 監査 pricing-plans-9）。
  - 送信は既存 `POST /billing/checkout`、**body は `{plan_code}` のみ**（aigenba :117-119 の `attempt_token` 同梱は P9 所管）。`plan-change` / `upgrade-now` / `earlyUpgradePlanCodes` は移植しない（要プロダクト判断・原則 4）。aigenba の「free plan は plan-change でなく checkout」（:100-109）は AI-CUE では全経路が checkout のため自然に成立する。
  - 確認ダイアログは AI-CUE 既存 `organisms/ConfirmDialog.svelte` を使う。aigenba の inline `Modal` + `@confirm-modal` selector は **aigenba 自身が「browser test 都合の負債。ConfirmDialog atom への置換を検討」とコメントしている**（Plans.svelte:192-199）ため、その負債は移植しない。サーバ validation エラー（`page.props.errors.plan_code`）は dialog 内に `Alert` で描画し、**成功時のみ閉じる**（aigenba :121-127 verbatim）。
- 新規 `/workspace/resources/js/pages/Billing/_helpers/PlanCard.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/_helpers/PlanCard.svelte`
  - page-local adapter 規約（`Billing/Plans` 以外から import しない）をコメントごと踏襲し、`PricingPlanCard` 分子へ委譲する。
  - **移植する**: `isCurrent`（headerBadges「現在のプラン」Badge）/ `canSwitch` / features 組み立て / `formatYen` / `formatLimit` / `priceAmount`（personal は `0` を渡して「無料」表示。aigenba :98-100）。
  - **移植しない（データ源が AI-CUE に無い）**: `includedSeats` / `currentSeatAmount`（席課金）/ `isPending` / `isStarter` + `starterMigrationText` / `isEnterprise` + contact CTA。
  - **features は D28 準拠**: aigenba のコメント「月次のチケット付与は廃止済 (常に 0 枚) のため表記しない (料金ページと同一方針)」（PlanCard.svelte:78）を verbatim で採る。AI-CUE の Plan 台帳の語彙に写して `プロジェクト {formatLimit(maxProjects)}` / `メンバー {formatLimit(maxMembers)} 名` / `ストレージ {maxStorageGb} GB`（出典は `/workspace/resources/js/pages/Pricing.svelte:28-38` の `buildFeatures` と同一）。
  - **D4 適合（AGENTS.md 禁止事項 #8 = 原則 2 の逸脱理由）**: aigenba は `canSwitch=false` を `disabled` ボタン +「変更不可」+ `title` / `aria-label` で表現する（:146-157）が、**AI-CUE では CTA を enabled のまま描画**し、押下時に理由を `Alert`（`data-testid="plan-switch-blocked"`）で表示する。理由文言（`switchBlockedReason`。aigenba の `disabledReason` 相当）はカード内 caption としても常時可視にし、情報を失わない。`disabled` 属性は使わない。
- 変更 `/workspace/resources/js/components/molecules/PricingPlanCard.svelte`: `headerBadges?: Snippet` を追加し `<h3>` 行を `flex items-center justify-between` へ（← `/tmp/aigenba/resources/js/components/molecules/PricingPlanCard.svelte:29,:56-59`）。`contactLabel` は追加しない。未指定時の出力は不変。
- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php`: `plans()` を新設（← aigenba `BillingController::plans()` :399-440）。`index()` のプラン一覧構築（:43-58）を移す。プラン台帳 → DTO の mapper は **aigenba が Billing / Marketing 双方で `PlanDto::fromModel` を共有している**のと同型に、AI-CUE 既存の `PricingService::listPublicPlans()`（`PricingPlanDto`）を共有する（新 DTO を発明しない）。`currentPlanCode` の解決規則は aigenba verbatim: `state() === ActiveFreePlan ? $organization->free_plan_code : $organization->plan_code`（**表示用途のみ。gate 判定には使わない**）。
- 変更 `/workspace/routes/web.php`: `Route::get('/billing/plans', [BillingController::class, 'plans'])->name('billing.plans');` を billing 群（課金ゲート allowlist 内・**route parameter を持たない current-org スコープ**）へ追加。

**(b) 購入画面: per-bucket 残高 + 状態機械 + ticketAttemptToken 安定化（tc-5）**

- 新規 `/workspace/resources/js/pages/Billing/ticketCount.ts` ← `/tmp/aigenba/resources/js/pages/Billing/ticketCount.ts`（**verbatim**。`^-?\d+$` の符号付き整数のみ許容し clamp / floor しない。docblock も移植）。`/workspace/resources/js/pages/Billing/PurchaseTickets.svelte:43-48` のインライン正規表現（`^\d+$`）を置換する。
- 新規 `/workspace/app/Enums/Billing/PurchaseFormState.php` ← `/tmp/aigenba/app/Enums/Billing/PurchaseFormState.php`（`Normal|Resume|Completed`。**verbatim**）。
- 変更 `/workspace/resources/js/pages/Billing/PurchaseTickets.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/PurchaseTickets.svelte`
  - 残高カードを **per-bucket 表示**へ（aigenba :255-290 verbatim）: 「今すぐ使える残高」= `balance.totalAvailable` / 「プラン付与残」= `balance.monthlyRemaining` + caption「プラン付与・初回特典分の残り（有効期限あり）」/ 「購入済み残」= `balance.purchasedRemaining` + caption「追加購入した分の残り」/ `data-testid="balance-next-expire"` = `balance.nextExpireAt`。**`debt` 行は無い**（aigenba に概念が存在しない）。出典は P5 の `TicketBalanceDto` のみで、画面で再計算しない。
  - `formState` による出し分け（aigenba :205-215 / :292-359 verbatim）: `normal` = 購入フォーム / `resume` = 進行中バナー +「決済を続ける」（`resumeUrl` へ `window.location.href`）+「新しく購入し直す」（`newPurchaseUrl` = `?fresh=1`）/ `completed` = 完了バナー +「もう一度購入する」。**resume / completed では購入フォームを描画せず `boundCount` を読み取りテキストで表示**する（`disabled` にしない = 禁止事項 #8）。
  - **単位は「枚」を維持**（aigenba の「回」は移植しない = 監査 ticket-charge-10 が「要プロダクト判断」に分類済み。AI-CUE の可変コスト消費という製品語彙）。
  - 既存の「購入したチケットに有効期限はありません」（:209-212）は **purchased バケツの caption 位置へ移す**（aigenba の per-bucket caption と同じ位置。monthly / signup grant 分と誤読されない = tc-10 の誤読リスク解消）。
  - submit ボタンは `disabled` にしない（aigenba :353 の `disabled={submitting || countError !== null}` は D4 により非移植。既存 `/workspace/tests/js/pages/PurchaseTickets.test.ts:86` の契約を維持）。
  - POST body は既存契約どおり `{count, attempt_token}`（サーバ `TicketCheckoutRequest` の field 名は不変。props 名のみ `attemptToken` → `ticketAttemptToken`）。
- 変更 `/workspace/app/Http/Controllers/Billing/TicketPurchaseController.php:42-73` ← aigenba `BillingController::showPurchase()`（:461-520）
  - `attemptToken: (string) Str::ulid()` の毎 render 発行をやめ、**`canManage && ! $request->boolean('fresh')` のときのみ** `resolveResumablePurchase()` 由来の token を再利用する（aigenba :479-481 verbatim。ブラウザバック / bfcache で既存 replay 冪等が効く）。
  - `match(true)` による `[$formState, $attemptToken, $boundCount, $resumeUrl]` の写像を verbatim 移植（aigenba :484-502）。
  - **非管理者には resume / completed を出さない**（aigenba :476-478 のコメントごと移植。`resumeUrl` は Stripe 直リンクで purchase gate を迂回する）。
  - `balance:` を `int` から `TicketLedgerService::balance()` の `TicketBalanceDto` へ差し替える。
- 変更 `/workspace/app/Services/Billing/TicketCheckoutService.php`: `resolveResumablePurchase()` を追加（← aigenba `TicketService.php:1393-1417`）。AI-CUE の `TicketCheckoutSession` は購入専用テーブルのため aigenba の `intent` 絞り込みは不要。live pending 判定は既存 `TicketCheckoutSession::isLivePending()` を使う。会計には触れない。
- 変更 `/workspace/config/billing.php`: `purchase_resume_window_minutes`（**既定 30 = aigenba の既定値そのまま**）を追加。

**(c) Customer Portal の事前ガード（bs-11）**

- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php:98-104` ← aigenba `BillingController::redirectToPortal()`（:978-1002）
  - `Gate::authorize('manageBilling')` の後、**`state() === ActiveFreePlan` または `subscription('default')` が無い**なら `back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。')`（**文言含め verbatim**）。Cashier `ManagesCustomer::billingPortalUrl()` の `assertCustomerExists()`（例外 = 500）に到達させない。
- 変更 `/workspace/resources/js/pages/Billing/Index.svelte:121`: portal ボタンの表示条件を `canManageBilling && billingState !== 'active_free_plan'` へ（aigenba Index.svelte:85,:181 verbatim。UI は `billingState` から導出し、独自の `canOpenPortal` prop を発明しない）。

**(d) Billing/Index の情報密度（bs-14）**

- 変更 `/workspace/resources/js/pages/Billing/Index.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/Index.svelte`
  - `data-testid="plan-list"` のインラインプラン一覧（:139-171）と page-local `formatPrice` / `startCheckout` を**撤去**し、「プラン比較」導線（`/billing/plans`・`data-testid="billing-plans-link"`）へ置換する。
  - 現在プランカード（`data-testid="current-plan-card"`。aigenba :225-260 verbatim）: `plan !== null` なら プラン名（`data-testid="current-plan-code"`）+ 月額（`billingState === 'active_free_plan'` なら「月額 無料（チケット代のみ）」/ それ以外は `¥{baseAmountJpy}`）+ **`active_free_plan` 以外でのみ**「次回請求日」。`plan === null` なら「まだプランに契約していません。「プラン比較」から新規契約できます。」（aigenba :255-258 の文言を導線名だけ合わせて移植）。
  - 残高カードを **per-bucket**（aigenba :263-310 verbatim。totalAvailable / monthlyRemaining / purchasedRemaining / `balance-next-expire`。**債務行は無い**）へ。「月 {monthlyTicketGrant} 枚のチケット付与」表記（:154）は **D28 により撤去**する。
  - quota 表示（`maxProjects` / `maxMembers` / `maxStorageGb` の現行 limits）を追加する。aigenba の `QuotaSnapshotDto`（使用量つき）は AI-CUE に集計経路が無いため移植せず（原則 4）、`QuotaService::limits()`（override 反映済み）の値のみを出す。
  - `data-testid="auto-recharge-card"` の差し込み位置と `?highlight=auto-recharge` の着地 anchor を用意する（カード実体は **P8a 所管**）。
  - **feedback バナー / 請求先フォームは追加しない**（P9 所管）。
- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php::index()`: 4 props の array 直書き（:60-65）を `BillingDashboardDto` へ（禁止事項 #4 の遵守）。`billingState` は `BillingAccess::state($organization)`、`plan` は (a) と同じ解決規則、`balance` は `TicketLedgerService::balance()` の DTO。

**(e) Guest/Pricing の配置と三層構成（pp-8）**

- 移動 `/workspace/resources/js/pages/Pricing.svelte` → `/workspace/resources/js/pages/Guest/Pricing.svelte`（← `/tmp/aigenba/resources/js/pages/Guest/Pricing.svelte` の配置）。
- 変更 `/workspace/app/Http/Controllers/Marketing/PricingController.php:73`: `Inertia::render('Pricing', …)` → `Inertia::render('Guest/Pricing', …)`。**route path `/pricing`・route 名 `pricing`・SEO メタは不変**。
- 三層構成を移植（監査 pricing-plans-8 の前提「Personal 無料実体の存在」が **P1 で確定済み**のため、条件成就により機械移植する）:
  - `personalPlan` バナー（`data-testid="personal-banner"`。aigenba :144-175）— 「基本料金はかからず、トレーニングに使うチケット代だけでご利用いただけます。」等の文言は verbatim、席語彙のみ AI-CUE の `maxMembers` / `maxProjects` / `maxStorageGb` へ写す。CTA href（`/register?plan={code}`）は **P7 で導入済み**のためそのまま使う。
  - 法人グリッド（`data-testid="pricing-plan-grid"`。aigenba :177-195）= `personal` を除いた残り（starter / standard）。
  - enterprise 層は **AI-CUE 既存の大規模利用バナー**（`data-testid="pricing-enterprise-banner"`）を正とする（enterprise の Plan 行が無く `enterprisePlan` が常に null になるため。原則 4）。
- **D28 の文言反映**: `buildFeatures`（:28-38）の `月 {monthlyTicketGrant} 枚のチケット付与` バレットを**撤去**する（aigenba :57-59 のコメント「月次のチケット付与は廃止済 (常に 0 枚) のため表記しない。チケットは購入制で、料金はプラン表下のチケット料金表に集約する」を採る）。
- 併せて文言を aigenba verbatim へ寄せる: 見出し下の説明（:89-91）「無料で始めて…シンプルな 2 プランです。」→「個人から法人まで、規模や利用量に合わせて選べるプランをご用意しています。」（**プラン数を決め打ちしない** = aigenba :127 verbatim）。FAQ（:53-55）の「Free プランは基本料金なしで…」→ aigenba :91-93 の「パーソナルプランは基本料金無料でご利用いただけます。…」（`free` 行は P4 で撤去済み）。
- `aigenba` の `bg-primary/10` は AI-CUE の DS token `bg-primary-soft` へ写す（`ds-purity` 準拠。既存 `Pricing.svelte:165` の先例）。

#### 波及変更

**TypeScript 型定義**

- `/workspace/resources/js/types/billing.ts`:
  - 追加 `BillingStateValue = 'no_subscription'|'pending_checkout'|'expired_checkout'|'subscribed'|'active_free_plan'`（← `/tmp/aigenba/resources/js/types/Billing.ts:103-109` verbatim。分岐退行を型で検知する）。
  - 再利用 `TicketBalanceShape`（P5 が同ファイルへ追加済み。**`debt` フィールドは無い**）。
  - `PurchaseTicketsPageProps`: `balance: number` → `balance: TicketBalanceShape`、`attemptToken` → **`ticketAttemptToken`**、追加 `formState: 'normal'|'resume'|'completed'` / `boundCount: number | null` / `resumeUrl: string | null` / `newPurchaseUrl: string`。
  - 追加 `BillingPlansPageProps` / `BillingDashboardProps` / `QuotaLimitsShape`。
- `/workspace/resources/js/components/molecules/PricingPlanCard.svelte` の `Props` に `headerBadges?: Snippet`。
- `/workspace/resources/js/types/marketing.ts`: 変更なし（`PricingPlanShape.monthlyTicketGrant` は **DTO からは外さない** — aigenba の `PlanDto` も `includedMonthlyTickets` を保持したまま表示だけを止めている。表示のみ撤去）。

**DTO / JsonResource**

- 新規 `/workspace/app/DataTransferObjects/Billing/BillingPlansPageDto.php`（`@phpstan-type BillingPlansPageShape`。`PricingPlanShape` を `@phpstan-import-type`）。
- 新規 `/workspace/app/DataTransferObjects/Billing/BillingDashboardDto.php`（Index の props DTO 化 = 禁止事項 #4 の遵守）。
- 新規 `/workspace/app/DataTransferObjects/Billing/QuotaLimitsDto.php`（`maxProjects` / `maxMembers` / `maxStorageGb`。GiB 換算は `PricingService::storageGb` と同一規則 `intdiv(bytes, 1024 ** 3)`）。
- 変更 `/workspace/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php`（`balance: int` → `TicketBalanceDto`、`attemptToken` → `ticketAttemptToken`、`formState` / `boundCount` / `resumeUrl` / `newPurchaseUrl` 追加。PHP shape / TS shape / constructor / fixture を同時更新）。
- 新規 `/workspace/app/Enums/Billing/PurchaseFormState.php`。
- JsonResource: 追加なし（本フェーズは Inertia のみ）。

**Inertia props**

- `Billing/Index`: 4 props → `['page' => BillingDashboardDto::toArray()]` 1 本（PurchaseTickets / Pricing と同じ `page` 規約へ）。
- `Billing/Plans`: 新規 `['page' => BillingPlansPageDto::toArray()]`。
- `Billing/PurchaseTickets`: `page` の shape 拡張。
- `Pricing` → `Guest/Pricing`: component 名のみ変更（props 不変）。

**テストファイル**

- 更新: `/workspace/tests/Feature/Billing/BillingPageTest.php`（:17 のプラン一覧期待を `BillingPlansPageTest` へ移設、:25-34 の props 名追随、:106 portal 期待、:87 の 404 は維持）
- 更新: `/workspace/tests/Feature/Billing/PortalConfigurationTest.php`（事前ガード導入後の到達条件）
- 更新: `/workspace/tests/Feature/Billing/TicketCheckoutTest.php`（画面 render 由来の安定 token を使う経路の期待を追加。既存 replay / stale ケースは維持）
- 更新: `/workspace/tests/Feature/Marketing/PricingPageTest.php`（`->component('Pricing')` → `'Guest/Pricing'`。プラン集合の期待は P1 / P4 で更新済み）
- 更新: `/workspace/tests/js/pages/Pricing.test.ts` → `/workspace/tests/js/pages/Guest/Pricing.test.ts`（import path + :62 の `月 100 枚のチケット付与` 期待を **非表示** の期待へ。**削除しない**）
- 更新: `/workspace/tests/js/pages/PurchaseTickets.test.ts`（:57 残高 fixture を per-bucket へ / :102 の POST 契約は `attempt_token` のまま維持 / :86 の「disabled にしない」契約を維持 / `formState` ケース追加）
- 更新: `/workspace/tests/js/components/molecules/PricingPlanCard.test.ts`（headerBadges）
- 新規: `/workspace/tests/Feature/Billing/BillingPlansPageTest.php` / `BillingPortalGuardTest.php` / `TicketPurchaseResumeStateTest.php`
- 新規: `/workspace/tests/js/pages/Billing/Plans.test.ts` / `Billing/PlanCard.test.ts` / `Billing/ticketCount.test.ts` / `Billing/Index.test.ts`
- 影響（変更なしで pass すること）: `/workspace/tests/js/architecture/{page-shell-structure,ds-purity,atomic-import-graph,lucide-scoped-import}.test.ts`

#### 主要な契約

ルート（課金ゲート allowlist 内・**route parameter を持たない current-org スコープ**。current org 不在 / 非所属は認可より前に 404）

```
GET  /billing                   billing.index          BillingController@index
GET  /billing/plans             billing.plans          BillingController@plans     ← 新規
POST /billing/checkout          billing.checkout       … 既存 (body: {plan_code} のみ)
POST /billing/portal            billing.portal         … 既存 (事前ガード追加)
GET  /purchase-tickets?fresh=1  billing.tickets.show   … 既存 route に fresh query を追加解釈
GET  /pricing                   pricing                PricingController (component 名のみ Guest/Pricing へ)
```

Controller

```php
public function plans(Request $request, PricingService $pricing, BillingAccess $access): Response;                  // ['page' => BillingPlansPageDto]
public function index(Request $request, TicketLedgerService $tickets, BillingAccess $access, QuotaService $quota): Response; // ['page' => BillingDashboardDto]
public function portal(Request $request, SubscriptionCheckoutGateway $gateway, BillingAccess $access): SymfonyResponse|RedirectResponse; // 事前ガードの back() のため union
```

現在プランの表示解決（aigenba `BillingController::plans()` :421-423 / `index()` :105-107 verbatim。**gate 判定には使わない**）

```php
$state = $access->state($organization);                       // OnboardingBillingState
$currentPlanCode = $state === OnboardingBillingState::ActiveFreePlan
    ? $organization->free_plan_code
    : $organization->plan_code;
```

Service（4 分割境界を維持）

```php
// App\Services\Billing\TicketCheckoutService
public function resolveResumablePurchase(Organization $org, int $userId, int $windowMinutes): ?TicketCheckoutSession;
// 2 段取得 (aigenba TicketService.php:1393-1417 verbatim):
//  (1) live pending (status=Pending / expires_at > now / checkout_url <> '') を latest('id') → resume
//  (2) completed (completed_at > now - window) を latest('id') → completed / (3) null → normal
// いずれも organization_id + initiated_by_user_id スコープ (cross-user の resumeUrl 漏洩を構造的に封じる)
```

DTO 形状（要点）

```
BillingStateValue        = 'no_subscription'|'pending_checkout'|'expired_checkout'|'subscribed'|'active_free_plan'
TicketBalanceShape       = { monthlyRemaining: int, purchasedRemaining: int, totalAvailable: int,
                             activeReservations: int, nextExpireAt: string|null }        // P5 由来 (aigenba verbatim / debt なし)
PricingPlanShape         = { code, name, baseAmountJpy: int|null, monthlyTicketGrant: int,
                             maxProjects: int|null, maxMembers: int|null, maxStorageGb: int|null }  // 既存を Billing と共有
QuotaLimitsShape         = { maxProjects: int|null, maxMembers: int|null, maxStorageGb: int|null }
BillingPlansPageShape    = { plans: list<PricingPlanShape>, currentPlanCode: string|null,
                             billingState: BillingStateValue, canManage: bool }
BillingDashboardShape    = { plan: PricingPlanShape|null, billingState: BillingStateValue,
                             currentPeriodEnd: string|null, balance: TicketBalanceShape,
                             quotas: QuotaLimitsShape, canManageBilling: bool }
PurchaseTicketsPageShape = { tiers: list<PurchaseTierShape>, minCount: int, maxCount: int, defaultCount: int,
                             balance: TicketBalanceShape, canManage: bool, ticketAttemptToken: string,
                             purchased: bool, formState: 'normal'|'resume'|'completed',
                             boundCount: int|null, resumeUrl: string|null, newPurchaseUrl: string }
```

`totalAvailable` は `TicketBalanceDto::totalAvailable()`（`max($monthly + $purchased - $reservations, 0)`。aigenba verbatim）の値をそのまま描画する。UI は再計算・clamp しない。

DB 列 / index: **追加なし**。data migration も無い（`plans.is_active` は P1 で `true` seed 済み = 再公開フェーズが存在しない）。`ticket_checkout_sessions` の既存 `UNIQUE(organization_id, attempt_token)` / `initiated_by_user_id` / `expires_at` / `completed_at` をそのまま使う。

config: `billing.purchase_resume_window_minutes` = 30（aigenba 既定値）。`config/quota.php` は触らない（`personal` / `starter` の limits は P1、`fallback_plan` は P4 で確定済み）。

#### PHPStan 適合チェック

- `plans()` / `index()` の戻り値は `Inertia\Response`、`portal()` は `SymfonyResponse|RedirectResponse`（`back()` 分岐のため union を明示。既存 `checkout()` :72 と同型）。
- 全ページ props は `readonly` DTO の `toArray()` 経由（`response()->json()` 直書きなし）。各 DTO に `@phpstan-type …Shape` を付け `@phpstan-import-type` で合成する（既存 `PurchaseTicketsPageDto` / `PricingPageDto` と同じ規約）。`TicketBalanceShape` は P5 の DTO から import（再宣言しない）。`PricingPlanShape` は `App\DataTransferObjects\Marketing\PricingPlanDto` から import する。
- `OnboardingBillingState` は backed enum。props へは `->value` を渡し、TS 側 `BillingStateValue` union と exact 対にする。Controller の分岐は `===` の enum 比較のみ（string 比較を書かない）。
- `resolveResumablePurchase(): ?TicketCheckoutSession` の null は `match(true)` の `default => [PurchaseFormState::Normal, (string) Str::ulid(), null, null]` へ縮退。各腕は同じ arity・型順を返し list shape 推論を保つ（aigenba :484-502 と同形）。
- `config('billing.purchase_resume_window_minutes')` は `mixed` のため `config()->integer(...)` typed accessor で取得する（aigenba の `Assert::integer($windowMin, …)` と等価）。
- `$request->user()` は `Assert::isInstanceOf($user, User::class)`（既存踏襲）。`$request->boolean('fresh')` は `bool` 確定。
- `QuotaService::limits(): array<string, int>` から `QuotaLimitsDto` を作る際、無い key は `null`（無制限）へ。`intOrNull` / `storageGb` と同じ規則を DTO の static factory に置き、`mixed` を残さない。
- `$organization->subscription('default')` は `?Subscription`（Cashier）。portal ガードは `instanceof Subscription` で narrowing する（aigenba :987 と同形）。`current_period_end` は `Carbon|null` のため `?->toIso8601String()`。
- `PricingService::listPublicPlans(): list<PricingPlanDto>` をそのまま使う（`is_active` フィルタは P1 実装。P8b は変更しない）。
- **禁止**: `@phpstan-ignore` / baseline 追加 / 戻り値 widen。

#### テスト計画

**先に red を作る（新規）**

1. `tests/Feature/Billing/BillingPortalGuardTest.php`（bs-11）
   - `stripe_id` null・サブスク無しの org の owner が `POST /billing/portal` → **302 back + `error` flash**（Fake gateway 未呼び出し = Stripe に到達しない）。現行は Cashier 例外 = red。
   - `free_plan_code='personal'`（= `ActiveFreePlan`）で **canceled サブスク行が残る** org の owner → 同じく back + error（aigenba :982-988 の趣旨の回帰）。
   - 有償サブスク保持 org の owner → 既存どおり `Inertia::location` で Portal URL。
2. `tests/Feature/Billing/TicketPurchaseResumeStateTest.php`（tc-5）
   - live pending session を持つ owner が `GET /purchase-tickets` → `formState='resume'` / **`ticketAttemptToken` が既存 session の `attempt_token` と一致** / `boundCount` = `ticket_count` / `resumeUrl` = `checkout_url`。現行は毎 render fresh ULID = red。
   - `?fresh=1` → `formState='normal'` かつ token が別値。
   - 完了済 session（窓内）→ `formState='completed'` / `resumeUrl` は null。窓外 → `normal`。
   - **非管理者（member）** → live pending があっても `formState='normal'` / `resumeUrl` null。
   - **他 user の pending は resume しない**（`initiated_by_user_id` スコープ）。
   - 二重課金回帰: resume 表示 → 同 token で `POST /purchase-tickets/checkout` → 既存 replay で同一 checkout URL へ収束し Stripe session が増えない。
3. `tests/Feature/Billing/BillingPlansPageTest.php`（bs-6）
   - owner: `GET /billing/plans` 200 / `page.plans` に `is_active=true` の全プラン（personal / starter / standard）/ `page.currentPlanCode` / `page.billingState` / `canManage=true`。
   - `ActiveFreePlan` の org（canceled サブスク行あり）で `page.currentPlanCode === 'personal'`（`plan_code` に旧 paid が残っていても free 側が正）。
   - **POST body 契約**: `POST /billing/checkout` が `{plan_code}` のみで成立する（attempt token を要求しない）。
   - member: 200 だが `canManage=false`。
   - current org 無しユーザー: 404 / 非所属: 404（既存 `BillingPageTest:87` と同型）。
4. `tests/js/pages/Billing/PlanCard.test.ts`
   - `isCurrent` で「現在のプラン」バッジ（headerBadges）が出る。
   - `canSwitch=false` で **`switchBlockedReason` が可視テキストとして描画**され、かつ **`disabled` 属性の button が存在しない**。CTA 押下で理由 Alert（`plan-switch-blocked`）が出る（禁止事項 #8 / DESIGN.md の機械保証）。
   - features に **「月 N 枚」表記が含まれない**（D28 の機械保証）。
5. `tests/js/pages/Billing/ticketCount.test.ts`
   - `parseTicketCount`: `'10'→10` / `'-5'→-5` / `'1e3'|'0x10'|'1.5'|'Infinity'|'-'|'1.'|''→null` / `10(number)→10`（防御的 `String(raw)`）。
6. `tests/js/pages/Billing/Plans.test.ts`
   - 「このプランへ変更」→ `ConfirmDialog` 表示 → 確認で `POST /billing/checkout` に **`{plan_code}` のみ**を送る。
   - `errors.plan_code` があるとき dialog は開いたままサーバ文言を描画する。
7. `tests/js/pages/Billing/Index.test.ts`
   - `billingState='active_free_plan'` で portal ボタンを描画せず「月額 無料（チケット代のみ）」を出す。`billingState='subscribed'` で portal ボタンと次回請求日を出す。
   - `plan-list` を持たず「プラン比較」リンク（`/billing/plans`）を出す。
   - per-bucket 残高（totalAvailable / monthlyRemaining / purchasedRemaining / `balance-next-expire`）を描画し、**残高由来の債務行が存在しない**。
   - `plan=null` で「まだプランに契約していません」を出す。

**既存テストの更新（削除しない）**

- `tests/Feature/Billing/BillingPageTest.php:17`「owner は /billing でプラン一覧・残高・管理フラグを見られる」→ プラン一覧の期待を `BillingPlansPageTest` へ**移設**し、本 test は `page.plan` / `page.billingState` / `page.balance`(per-bucket) / `page.quotas` / `canManageBilling` の期待へ更新。:38 / :50 / :60 / :93 / :106 は props 名・portal 到達条件の追随。
- `tests/Feature/Billing/PortalConfigurationTest.php`: 事前ガード導入後も Portal configuration の spec が変わらないこと（ガードは spec でなく到達条件の変更）。
- `tests/Feature/Billing/TicketCheckoutTest.php`: 画面 render 由来の安定 token を使う経路の期待を追加（既存 replay / stale ケースは維持）。
- `tests/Feature/Marketing/PricingPageTest.php`: `->component('Pricing')` → `->component('Guest/Pricing')`（配置移動が props・SEO を変えないことの回帰）。
- `tests/js/pages/Pricing.test.ts` → `tests/js/pages/Guest/Pricing.test.ts`: import path を移設し、`:62` の「月 100 枚のチケット付与」期待を **「月 N 枚」表記が描画されない**（D28）へ更新。三層構成（`personal-banner` / `pricing-plan-grid` / `pricing-enterprise-banner`）の描画ケースを追加。
- `tests/js/pages/PurchaseTickets.test.ts`: `:57` の `balance` fixture を `TicketBalanceShape` へ、per-bucket 3 値 + `balance-next-expire` の描画期待を追加。`:86`（範囲外でも disabled にしない）・`:102`（`count` + `attempt_token` を POST）は**契約として維持**。`formState='resume'|'completed'` の描画ケース（フォーム非描画・`boundCount` 表示・CTA 2 種）を追加。「購入したチケットに有効期限はありません」が purchased バケツの caption 位置に出ることを固定。
- `tests/js/components/molecules/PricingPlanCard.test.ts`: `headerBadges` を渡すと header 右へ描画され、渡さない場合は既存出力が不変（回帰）。

**arch テスト（変更せず pass）**: `page-shell-structure`（新設 `Billing/Plans.svelte` が PageContainer / PageHeader / PageContent を使う。`_helpers/PlanCard.svelte` は AppLayout を import しないため対象外）/ `ds-purity`（hex 直書き禁止。aigenba の `bg-primary/10` は `bg-primary-soft` へ写す）/ `atomic-import-graph`（`_helpers` は pages 層。逆参照なし）/ `lucide-scoped-import`（アイコンは `@lucide/svelte` のみ）。

#### リスク

| リスク | 緩和 |
|---|---|
| **Index からプラン一覧（`plan-list` / `checkout-{code}`）を撤去**すると既存 Feature / bug-hunt シナリオが参照喪失する | 撤去前に `grep -rn 'plan-list\|checkout-' tests/ devnotes/` で参照を洗い出し、期待を `BillingPlansPageTest` / `Billing/Plans.test.ts` へ**移設**（削除しない）。Index には `billing-plans-link` を残し導線を切らない。 |
| **`ticketAttemptToken` 安定化で正当な追加購入を握り潰す**（completed 直後に別枚数で買えない） | `?fresh=1`（`newPurchaseUrl`）を `resume` / `completed` の両状態から必ず露出する（aigenba verbatim）。窓は `purchase_resume_window_minutes`(30) で有限化。 |
| **resume の Stripe 直リンクが purchase gate を迂回** | `canManage=false` では常に `normal` + fresh token へ縮退し `resumeUrl` を props に載せない（aigenba :476-481 のコメントごと移植）。`initiated_by_user_id` スコープで cross-user 漏洩も封じる。Feature テストで固定。 |
| **per-bucket 残高が P5 未マージだと成立しない** | P8b は P5 の後段。`TicketBalanceDto` を DTO 境界でのみ参照し `TicketLedgerService` の計算には触れない（残高数式は P5 の単一 snapshot が唯一の出典）。 |
| **D28 の文言撤去で「チケットが減った」と誤解される** | 「月 N 枚」は D28 で**実態が 0 になる**ため、表記を残す方が誤情報になる。aigenba と同じく料金の説明をチケット料金表（購入制 + signup grant {N} 枚）へ集約し、`signup-grant-note` を残して初回無料枠の可視性を維持する。 |
| **`active_free_plan` 以外の未契約（`no_subscription` / `expired_checkout`）でも portal ボタンが出る**（aigenba の UI 条件が `!isFreePlan` のみ） | **aigenba の挙動をそのまま移植する**（原則 5: aigenba にある問題を AI-CUE 側で先回り修正しない）。サーバ側事前ガードが back + error flash で fail-closed に受け止め、500 には到達しない。`BillingPortalGuardTest` がこの安全網を固定する。aigenba 側で UI 条件が直れば取り込む。 |
| **Guest/Pricing 移動で SSR / e2e の component 名参照が壊れる** | route path・route 名・SEO メタは不変。`grep -rn "'Pricing'\|\"Pricing\"" app/ tests/ resources/` で参照を全置換し、既存 `Pricing.test.ts` / `PricingPageTest` を移設して回帰にする。 |
| **Index の props 一括変更（4 props → `page`）の破壊範囲** | 同一 PR で Feature / JS 両テストを更新する。DTO 化は禁止事項 #4 の遵守でもあり後戻りしない。 |
| **P8a（auto-recharge カード）と Index を同時に触る競合** | P8b は `auto-recharge-card` の差し込み位置と `?highlight=auto-recharge` anchor のみ用意し、カード実体は P8a 所管（マージ順は P8a → P8b）。 |
| **P9（feedback / 請求先）が Index を再度触る** | P8b は Index を `BillingDashboardDto` 1 props に整えるところで止め、P9 は同 DTO への additive な追加（`feedback` / `billingContact`）で完結させる。placeholder props を先置きしない。 |

---

### P9: サブスク checkout の冪等・着地 feedback + 請求先情報

前提（v2）: P1〜P8b がマージ済み。**`BillingCheckoutSession`（model + migration + Factory）・`CheckoutIntent`（`SubscriptionStart` / `SetupPaymentMethod`）・`CheckoutSessionStatus`（`Pending` / `Completed` / `Failed` / `Expired`）は P2 で導入済み**（`BillingAccess::state()` の `PendingCheckout` / `ExpiredCheckout` が読むため前倒し = D25 v2）。P2 の `state()` は **live pending を `created_at >= now()-1day` の in-memory 判定で見る**（`expires_at` 列は存在しない）。P8b までで `BillingDashboardDto` / `BillingPlansPageDto` / `Billing/Plans.svelte` / `BillingCustomerSynchronizer` / `SyncBillingCustomerDetails` / `StripeGatewayInterface` + `CashierStripeGateway` + `FakeStripeGateway` が揃っている。

**P9 の担当は 3 つだけ**: (a) `attempt_token` による冪等状態機械を `billing_checkout_sessions` の **writer として配線**する（P2 で行 0 件だったテーブルに初めて書き手が付く）、(b) 着地 feedback（`resolveBillingFeedback` + `Billing/Index` バナー）、(c) 請求先情報（`billing_contact_email` / `billing_contact_name`）。

**DoD**: サブスク checkout が二重 subscription 作成を構造的に起こせない（`UNIQUE(organization_id, intent, attempt_token)` + org-wide live pending dedup + Stripe idempotency key + INSERT race の re-read 収束）。`billing_contact_*` は **CipherSweet 暗号化で保存**され、平文 DB 非保存・平文 where 不 hit が Feature/Architecture テストで固定される。**金銭の付与経路には一切触らない**（D7 維持: 付与は `invoice.paid`、`plan_code` 同期は `customer.subscription.*`。本フェーズの追跡行は着地 feedback と冪等の真実源であって台帳の出典ではない）。**`EffectivePlan` は使わない**（判定源は `BillingAccess::state()` の `OnboardingBillingState`）。

**token 型名の分離（交渉不可）**: チケット決済の `ticketAttemptToken` / `ticket_checkout_sessions.attempt_token` / Stripe key `purchase:{token}` は **P8b までで確定済みの別テーブル・別 key 空間**。P9 が導入するのは `subscriptionAttemptToken`（props / TS 型名）/ `billing_checkout_sessions.attempt_token`（`intent=subscription_start` でスコープ）/ Stripe key `sub_start:{token}`（aigenba verbatim の名前空間）。両者を同一 DTO・同一列・同一 key 空間に混ぜない。

#### 変更箇所

| ファイル (AI-CUE) | 何をするか | 移植元 (aigenba) |
|---|---|---|
| `app/Services/Billing/SubscriptionService.php`（改修） | **`startCheckout()` を verbatim の冪等マシンへ差し替える**（`SubscriptionCheckoutService` を新設しない = aigenba は本 Service に置いている）。シグネチャ: `startCheckout(Organization $org, User $user, Plan $plan, string $successUrl, string $cancelUrl, string $attemptToken): CheckoutSessionDto`。`Cache::lock("billing:checkout:start:{$org->id}", 10)->block(5, …)`（**lock 名も verbatim**）。`assertCheckoutReady()` / `isReplayableCheckout()` / `replayCheckout()` / `isUniqueViolation()` を移植 | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:508-717,930-985` |
| `app/DataTransferObjects/Billing/CheckoutSessionDto.php`（新規） | **verbatim**（`stripeSessionId` / `url` / `intent` / `planCode` + `toArray()` + `@phpstan-type CheckoutSessionShape`）。v1 の `SubscriptionCheckoutRedirect` は発明のため作らない | `/tmp/aigenba/app/DataTransferObjects/Billing/CheckoutSessionDto.php` |
| `app/Services/Billing/Contracts/StripeGatewayInterface.php`（改修） | `createSubscriptionCheckout(Organization $org, string $stripePriceId, string $successUrl, string $cancelUrl, array $metadata, string $idempotencyKey): CreatedCheckoutSession` へ変更（戻り値 `ExternalBillingRedirect` → **既存 `CreatedCheckoutSession`** = session id の pin が webhook 照合に必須。新 DTO は作らない）。`expireCheckoutSession(string $stripeSessionId): string` を追加（**戻り値は AI-CUE の `TicketCheckoutGateway` と同型の string**。aigenba の `CheckoutSessionExpireResult` は AI-CUE に先例が無いため移植しない）。`createPortalSession` / `syncCustomerDetails` は据置 | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php:50,200` / AI-CUE `app/Services/Billing/TicketCheckoutGateway.php` |
| `app/Services/Billing/CashierStripeGateway.php`（改修） | `newSubscription('default',…)->checkout()` をやめ `$org->stripe()->checkout->sessions->create($payload, ['idempotency_key' => $key])` 直呼びへ（**Cashier の `checkout()` ヘルパは per-request idempotency key を公開しない** = `CashierTicketCheckoutGateway` と同一理由・同一コメントを持ち込む）。`buildSubscriptionSessionPayload()` を public pure メソッドで切り出す。`expireCheckoutSession()` を実装 | `CashierTicketCheckoutGateway::buildSessionPayload()` |
| `app/Services/Billing/Fakes/FakeStripeGateway.php`（改修） | 新シグネチャに追随。`CreatedCheckoutSession` を決定的に返し、**同一 `idempotencyKey` の再呼び出しで同一 sessionId を返す**（Stripe の idempotency 挙動を fake でも再現しないと冪等テストが本物にならない）。`expireCheckoutSession()` は `'expired'` を返す | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php` |
| `app/Exceptions/Billing/SubscriptionAttemptPlanMismatchException.php`（新規） | 同 token・別 plan の再送。Controller が `ValidationException::withMessages(['plan_code' => …])` = **422** へ変換（**aigenba 非 verbatim**。根拠は「主要な契約」§非 verbatim 点） | — |
| `app/Exceptions/Billing/StaleCheckoutAttemptException.php`（再利用） | **既存クラスをサブスク側でも使う**（非 replayable な同 token 行）。新設しない | `/tmp/aigenba/app/Exceptions/Billing/StaleCheckoutAttemptException.php` |
| `app/Exceptions/Billing/CheckoutInProgressException.php`（再利用） | 既存。lock timeout / expire 失敗 / `'complete'` 検出に使う | 同上 |
| `app/Http/Requests/Billing/BillingCheckoutRequest.php`（改修） | `subscription_attempt_token => ['required','ulid']` を追加（`Str::ulid()` は大文字 Crockford base32 のため lowercase regex 不可 = aigenba のコメントごと移植）。`ProhibitsProtectedKeys` は据置 | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:549-552` |
| `app/Enums/Billing/BillingFeedbackKind.php`（新規） | **verbatim 5 case**（`PurchaseReceived` / `PurchaseProcessing` / `PurchaseAlreadyReceived` / `CheckoutRetryRequired` / `PortalReturned`）。**v1 の `PurchasePaymentFailed` は発明のため作らない** | `/tmp/aigenba/app/Enums/Billing/BillingFeedbackKind.php` |
| `app/DataTransferObjects/Billing/BillingFeedbackDto.php`（新規） | **verbatim**（`private __construct` + `simple(kind, message)` + `toArray(): BillingFeedbackShape` + `@phpstan-type SimpleBillingFeedbackKind`） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingFeedbackDto.php` |
| `app/Http/Controllers/Billing/BillingController.php`（改修） | `index` に private `resolveBillingFeedback(Request, Organization): ?BillingFeedbackDto` を追加（**verbatim**）。`checkout` を新 `startCheckout()` へ配線（404 → 認可 → 開始の順）。`portal` の return URL を `route('billing.index', ['portal' => 1])` へ。`plans` に `subscriptionAttemptToken` を載せる。`updateBillingContact` を追加 | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:195,318-393,540-610` |
| `app/Models/Organization.php`（改修） | `checkoutSessions(): HasMany<BillingCheckoutSession>` を追加（feedback の org スコープ引きに必須。aigenba `$organization->checkoutSessions()` と同名）。`implements CipherSweetEncrypted` + `use UsesCipherSweet` + `configureCipherSweet()`。`routeNotificationForMail()` を `billing_contact_email` 正本 → owner email fallback へ。**両列とも `$fillable` 外** | `/tmp/aigenba/app/Models/Organization.php:119-138`（fallback 意味論のみ。`$fillable` 掲載は移植しない） |
| `database/migrations/2026_07_xx_xxxxxx_add_billing_contact_columns_to_organizations_table.php`（新規） | `billing_contact_email` / `billing_contact_name` を **`text()->nullable()`**（CipherSweet ciphertext のため `string(255)` を使わない。`inquiries` の先例と同一判断）。**blind index 列は作らない**（共有 `blind_indexes` morph テーブルを使う既存規約） | `/tmp/aigenba/database/migrations/2026_04_14_011301_add_cashier_columns_to_organizations_table.php:16-17`（**列型は非 verbatim**。aigenba は平文 `string`） |
| `app/DataTransferObjects/Billing/UpdateBillingContactData.php`（新規） | **verbatim**（`fromRequest()` で `EmailNormalizer::normalize()` + `Assert::stringNotEmpty()`、name は空文字を null へ畳む） | `/tmp/aigenba/app/DataTransferObjects/Billing/UpdateBillingContactData.php` |
| `app/Http/Requests/Billing/UpdateBillingContactRequest.php`（新規） | `billing_contact_email => ['required','email:rfc','max:255']` / `billing_contact_name => ['nullable','string','max:255']` + `protectedKeyMissingRules()`。**`array_replace` ではなく `array_merge`**（AI-CUE の trait docblock が指定する保護キー後勝ち merge）。namespace は current-org スコープに合わせ `App\Http\Requests\Billing` | `/tmp/aigenba/app/Http/Requests/Organizations/Billing/UpdateBillingContactRequest.php` |
| `app/Actions/Billing/UpdateBillingContactAction.php`（新規） | **verbatim**（`DB::transaction` 内で両列代入 → **`save()` 前に `isDirty('billing_contact_email')` 判定** → `save()` → email dirty 時のみ `BillingCustomerSynchronizer::dispatchFor()`。IV-2/3/5/6 の docblock ごと移植） | `/tmp/aigenba/app/Actions/Billing/UpdateBillingContactAction.php` |
| `app/Services/Billing/StripeWebhookProcessor.php`（改修） | `CheckoutSessionCompleted` arm に `settleSubscriptionCheckout()` を**追加**する。既存 `grantPurchasedTickets()` は **本体・purpose ガードとも無改変**（`metadata.purpose !== 'ticket_purchase'` は既に受理のみ = 相互排他が既に成立している） | `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:447-470,1425-1436` |
| `app/DataTransferObjects/Billing/BillingDashboardDto.php`（改修） | additive: `feedback: BillingFeedbackShape\|null` / `billingContact: BillingContactShape`（P8b が placeholder を先置きしない前提どおり、ここで初めて生える） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingDashboardDto.php` |
| `app/DataTransferObjects/Billing/BillingPlansPageDto.php`（改修） | additive: `subscriptionAttemptToken: string`（render ごとの ULID） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingPlansDto.php` |
| `resources/js/components/features/billing/BillingContactForm.svelte`（新規） | 請求先メール / 宛名の更新フォーム。`@lucide/svelte` の `Receipt`、DS token のみ | `/tmp/aigenba/resources/js/pages/Billing/_helpers/BillingContactForm.svelte` |
| `resources/js/pages/Billing/Index.svelte`（改修） | `page.feedback` バナー（`kind` で variant 決定・**raw query を UI が見ない**）と `BillingContactForm` を T071 primitive 配下に追加 | `/tmp/aigenba/resources/js/pages/Billing/Index.svelte` |
| `resources/js/pages/Billing/Plans.svelte`（改修） | POST body を `{plan_code}` → `{plan_code, subscription_attempt_token}` へ | `/tmp/aigenba/resources/js/pages/Billing/Plans.svelte:117-119` |
| `routes/web.php`（改修） | `PATCH /billing/contact` → `billing.contact.update`（課金ゲート allowlist 内・**route parameter なし** = current-org スコープ） | — |

**migration を追加しない点（v2 の重要な変更）**: `billing_checkout_sessions` は **P2 で作成済み**。P9 は列を足さない。特に **`expires_at` 列を追加しない**（v1 の「expires_at pin」は発明。aigenba にも P2 の `state()` にも存在せず、live 判定は `status=Pending` + `checkout_url` 非空 = `isReplayablePending()`、stale 判定は `state()` の `created_at >= now()-1day` が担う）。

**非スコープ（P9 で持ち込まない）**: `SignupFunding` / `CreditPurchase` intent（対象機能が無い = 原則 4）/ `seats`・`funding_choice`・`topup_count`・`applied_campaign_id`・`applied_trial_days`・`credit_count`・`pm_reuse_dispatched_at`（P2 が原則 4 で非移植と決定済み）/ `resolveAutoRechargeLanding`・`?setup_session_id` 着地（P8a は PM 流用 Job を持たない）/ `resolveOnboardingContinue`（`OnboardingReturnResolver` は P7 所管で `?session_id` 非依存に配線済み）/ `checkout.session.expired` の購読（`state()` の `created_at` 閾値で決定的に扱えるため Stripe 照会を増やさない）/ `billing_contact_email` の NOT NULL 化・backfill（fallback が生きている限り不要）。

#### 波及変更

- **TypeScript 型定義** `resources/js/types/billing.ts`:
  - 追加 `BillingFeedbackKind = 'purchase_received'|'purchase_processing'|'purchase_already_received'|'checkout_retry_required'|'portal_returned'`（**5 値**。PHP の `SimpleBillingFeedbackKind` と exact 対）/ `BillingFeedbackShape { readonly kind: BillingFeedbackKind; readonly message: string }` / `BillingContactShape { readonly email: string | null; readonly name: string | null; readonly fallbackEmail: string | null }`。
  - `BillingDashboardProps` に `feedback: BillingFeedbackShape | null` / `billingContact: BillingContactShape` を追加。`BillingPlansPageProps` に `subscriptionAttemptToken: string` を追加。P8b が追加した `BillingStateValue` を Index の判定源として再利用（**`EffectivePlanShape` は存在しない**）。
- **Inertia props**: `Billing/Index` の `page` shape 拡張（DTO `toArray()` 経由。`response()->json()` 直書きなし）/ `Billing/Plans` の `page` shape 拡張。新規ページなし。
- **DTO**: 新規 `CheckoutSessionDto` / `BillingFeedbackDto` / `UpdateBillingContactData` / `BillingContactDto`。改修 `BillingDashboardDto` / `BillingPlansPageDto`。既存 `CreatedCheckoutSession` をサブスク側でも再利用（新設しない）。`ExternalBillingRedirect` は `createPortalSession` の戻り値として据置。
- **Factory**: P2 の `BillingCheckoutSessionFactory` に **`forOrganization()` / `initiatedBy()` / `withAttempt(string $token, string $planCode)`** state を追加（P2 は `pending()` / `expired()` / `failed()` / `completed()` / `forOrganization()` まで）。`OrganizationFactory` に `withBillingContact(?string $email = null, ?string $name = null)` state を追加（テストデータ手組み禁止）。
- **config**: `config/cashier.php` の購読集合は `HandledStripeWebhookEvent` 由来の既存導出のまま（**case を増やさない** = `CheckoutSessionCompleted` は既存。`WebhookEventSubscriptionInvariantTest` は無変更で green）。
- **テストファイル（更新・削除しない）**: `tests/Feature/Billing/BillingPageTest.php`（Index props に `feedback` / `billingContact`）/ `tests/Feature/Billing/BillingPlansPageTest.php`（`subscriptionAttemptToken`）/ `tests/Feature/Billing/PortalConfigurationTest.php`（return URL の `?portal=1`）/ `tests/Feature/Billing/BillingCheckoutSessionModelTest.php`（P2 導入。writer 追加後も制約の期待不変）/ `tests/Feature/Billing/WebhookIdempotencyTest.php`・`WebhookEventSubscriptionInvariantTest.php`（期待不変）/ `tests/Feature/Billing/TicketPurchaseWebhookTest.php`（**無改変で green** = ticket 経路が無改変であることの回帰）/ `tests/Feature/Billing/BillingAccessStateTest.php`（P2 で `PendingCheckout` / `ExpiredCheckout` を Factory 直挿しで固定済み。P9 は **writer 経由でも同じ state に落ちる**ケースを追加）/ `tests/js/pages/Billing/Index.test.ts`・`Plans.test.ts`。
- **Architecture テストへの影響**: `MassAssignmentSafetyTest`（`billing_contact_*` は `$fillable` 外）/ `FormRequestProhibitedKeyTest`（新 FormRequest が `protectedKeyMissingRules()` を張る）/ `ManageRouteAuthGuardTest`（`billing.contact.update`）/ `OrganizationRouteParamWebOnlyInvariantTest`（route param 無しのため対象外）/ `BillingSyncDispatchInvariantTest`（P2 導入。`dispatchFor` の呼び出し元に `UpdateBillingContactAction` を追加）。

#### 主要な契約

**ルート**（課金ゲート allowlist 内・route parameter を持たない current-org スコープ。current org 不在 / 非所属は認可より前に 404）

```
GET   /billing            billing.index           BillingController@index      … 既存 (?session_id / ?portal / ?replayed / ?retry を解釈)
GET   /billing/plans      billing.plans           BillingController@plans      … 既存 (subscriptionAttemptToken を発行)
POST  /billing/checkout   billing.checkout        BillingController@checkout   … 既存 (body: {plan_code, subscription_attempt_token})
POST  /billing/portal     billing.portal          BillingController@portal     … 既存 (return URL に ?portal=1)
PATCH /billing/contact    billing.contact.update  BillingController@updateBillingContact  ← 新規 (manageBilling)
```

**DB（P9 は `billing_checkout_sessions` に列を足さない。P2 の定義をそのまま使う）**

```sql
-- billing_checkout_sessions (P2 で作成済み。P9 が初めての writer)
id, organization_id FK cascade, initiated_by_user_id FK users nullOnDelete,
intent varchar(32), plan_code varchar(32) null,
stripe_session_id varchar UNIQUE, idempotency_key varchar(128) UNIQUE,
attempt_token varchar null, checkout_url varchar(2048) null,
status varchar(16) default 'pending', completed_at timestamp null, timestamps
UNIQUE (organization_id, intent, attempt_token)  -- 名: billing_checkout_sessions_org_intent_attempt_unique
INDEX  (organization_id, intent, status) / INDEX (organization_id, intent, initiated_by_user_id, id)

-- organizations (additive。P9 が追加する唯一の migration)
billing_contact_email text null,  billing_contact_name text null   -- CipherSweet ciphertext
```

**冪等状態機械の契約（要件 1-9）**

| # | 契約 | 実現 |
|---|---|---|
| 1 | **`organization_id` + `subscription_attempt_token` の UNIQUE** | `UNIQUE(organization_id, intent, attempt_token)`（P2）に **`intent='subscription_start'` を pin** して成立させる。`intent` はサブスク token 空間とカード登録 token 空間（P8a の `SetupPaymentMethod`）を分ける軸であり、チケット token は**別テーブル**（`ticket_checkout_sessions`）のため混線しない |
| 2 | **`initiated_by_user_id` による actor scope** | 行作成時に `initiatedBy()->associate($user)` で**必ず非 null 記録**（監査 + 要件 7 の引き）。**live pending dedup は org-wide のまま**（要件 4）— subscription は org 単位の singleton であり、actor scope にすると同 org の 2 人が同時に live Checkout を持てて**二重 subscription を許す**。actor scope が効くのは **token の所有者判定（要件 7）のみ** |
| 3 | **`pending` / `completed` / `failed` / `expired`** | P2 の `CheckoutSessionStatus`（verbatim）。遷移は下表 |
| 4 | **同 token 再送は既存 Checkout URL へ収束** | `(org, intent, attempt_token)` の同 token 行が `isReplayableCheckout()`（`Completed` **または** `Pending` かつ `checkout_url` 非空）なら `replayCheckout()`: `Pending` → **保存済み `checkout_url`** / `Completed` → `url=null`（受付済み着地）。非 replayable → `StaleCheckoutAttemptException`。**新規 Checkout を作らない**（aigenba verbatim） |
| 5 | **Stripe idempotency key 対応** | Stripe へ渡す key は **`'sub_start:'.$attemptToken`**（aigenba verbatim の名前空間。ticket 側 `purchase:{token}` と分離）。DB `idempotency_key` 列には**同値を保存**し UNIQUE を張る（Stripe 側 key と自 DB 行が 1:1。Stripe 作成成功 → DB 保存前の crash も、同 token 再試行が Stripe 側 idempotency で同一 session に収束しその時点で行が入る） |
| 6 | **plan code 不一致の token 再利用は 422** | 同 token 行の `plan_code !== $plan->code` → `SubscriptionAttemptPlanMismatchException` → Controller が `ValidationException` = **422**（`assertInvalid(['plan_code'])`）。行は増えず Stripe 呼び出しも増えない |
| 7 | **他 org・他 user の token は 404** | `attemptTokenIsForeign(string $token, Organization $org, User $user): bool` = `intent=subscription_start` かつ同 `attempt_token` の行が**存在し、かつ (org, initiated_by_user_id) が一致しない**とき true。Controller が **`Gate` より前に 404**（403 にしない = 存在オラクル封じ）。token は render ごとの ULID = 未知 token は「行なし」として通常の新規発行へ落ちる |
| 8 | **success / cancel webhook との競合と再送** | `settleSubscriptionCheckout()` は `Pending` 行のみ遷移させる（`Completed` は終局 = 再送 no-op）。cancel（ユーザー離脱）は Stripe から `completed` が来ない → 行は `Pending` のまま → `state()` が `created_at > 1day` で `ExpiredCheckout` と読む（P2 verbatim。DB 書込なし） |
| 9 | **tenant キーを payload から受け取らない Request 契約** | `BillingCheckoutRequest` / `UpdateBillingContactRequest` が `ProhibitsProtectedKeys`。`organization_id` / `initiated_by_user_id` / `plan_id` は `missing` = 存在するだけで **422** |

```php
// App\Services\Billing\SubscriptionService（aigenba verbatim。新 Service を作らない）
/**
 * @throws SubscriptionAttemptPlanMismatchException|StaleCheckoutAttemptException|CheckoutInProgressException|StripePriceNotSyncedException
 */
public function startCheckout(
    Organization $org, User $user, Plan $plan,
    string $successUrl, string $cancelUrl, string $attemptToken,
): CheckoutSessionDto;

/** 要件 7: (org, user) スコープ外に同 token 行が在るか。true なら Controller が認可より前に 404 */
public function attemptTokenIsForeign(string $attemptToken, Organization $org, User $user): bool;

final readonly class CheckoutSessionDto {   // verbatim
    public function __construct(
        public string $stripeSessionId, public ?string $url,
        public string $intent, public ?string $planCode,
    ) {}
}
```

`startCheckout()` の手順（`Cache::lock("billing:checkout:start:{$org->id}", 10)->block(5, …)` 内。`LockTimeoutException` は fail-closed で `CheckoutInProgressException('直前の操作が進行中です。数秒お待ちください。')` = ロックなし実行へフォールバックしない）:

| # | 段 | 挙動（aigenba verbatim） |
|---|---|---|
| 0 | 事前 assert | `Assert::stringNotEmpty($attemptToken, '契約手続きトークンが不正です')` / `assertCheckoutReady($org)`（`stripeEmail()` の非空 + 形式）/ `assertPriceSynced($basePrice)` / `assertStripeBillablePlan($plan)` |
| 1 | 既存 subscription guard | `$org->subscription('default')` が `valid()` なら `'既に有効なサブスクリプションがあります。プラン変更をご利用ください。'`（`Assert::true`） |
| 2 | **同 token 行**（`org` + `intent` + `attempt_token`） | `plan_code !== $plan->code` → `SubscriptionAttemptPlanMismatchException`（**要件 6**）<br>`isReplayableCheckout()` → `replayCheckout()`（**要件 4**: `Pending` → 既存 `checkout_url` / `Completed` → `url=null`）<br>それ以外 → `StaleCheckoutAttemptException('契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。')` |
| 3 | **同 plan の live pending dedup**（`org` + `intent` + `plan_code` + `status=Pending`。**org-wide**） | `CheckoutSessionDto(url: null, …)` を返す → Controller が `back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。')`（**別 token でも live session は 1 本**） |
| 4 | **別 plan の live pending を expire** | `gateway->expireCheckoutSession()` が throw → `CheckoutInProgressException('前回の決済セッションの整理に失敗しました。 数分後に再試行してください。')`（local を上書きせず停止）/ `'complete'` → `CheckoutInProgressException('直前の決済が処理中です。数分お待ちください。')` / それ以外 → 行を `Expired` にして続行 |
| 5 | Stripe 作成 → DB 記録 | `gateway->createSubscriptionCheckout(…, metadata: ['purpose' => 'subscription_start', 'org_ref' => (string) $org->id, 'plan_code' => $plan->code], idempotencyKey: 'sub_start:'.$attemptToken)` → `DB::transaction` で行 INSERT（`intent` / `plan_code` / `stripe_session_id` / `idempotency_key` / `attempt_token` / `checkout_url` / `status=Pending` / `initiated_by_user_id`） |
| 6 | `UniqueConstraintViolationException` の re-read 収束 | `isUniqueViolation()`（SQLSTATE `23000`/`23505` + index 名 `billing_checkout_sessions_org_intent_attempt_unique` / SQLite は構成列名で一致）以外は rethrow。該当時は `(org, intent, attempt_token)` を再読込 → replayable なら `replayCheckout()` / でなければ `StaleCheckoutAttemptException`（**500 にしない**） |

**状態遷移（要件 3 / 8。`Completed` は終局）**

```
Pending ──(checkout.session.completed / mode=subscription / payment_status=paid|no_payment_required)──▶ Completed
Pending ──(checkout.session.completed / payment_status=unpaid)───────────────────────────────────────▶ Failed
Pending ──(別 plan での明示 expire)──────────────────────────────────────────────────────────────────▶ Expired
Failed  ──(同 session の completed 再送)─────────────────────────────────────────────────────────────▶ Completed
Completed ─────────────────────────────────────────────────────────────────────────────────────────▶ (終局。再送は no-op = 冪等)
```

cancel / 離脱は**遷移を持たない**（Stripe から completed が来ない = `Pending` のまま）。`BillingAccess::state()` が `created_at >= now()-1day` を境に `PendingCheckout` / `ExpiredCheckout` と読む（**P2 verbatim。read 経路で DB 書込をしない**）。`Expired` 行の遅延 completed も上表どおり `Completed` へ収束する（金銭の真実は Stripe が終局）。

**Controller の実行順（要件 7 = セキュリティ不変条件 #2「不整合は認可より前に 404」）**

```php
public function checkout(BillingCheckoutRequest $request, SubscriptionService $subscriptions): SymfonyResponse|RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $user = $request->user();  Assert::isInstanceOf($user, User::class);
    $attemptToken = $request->validated('subscription_attempt_token');  Assert::string($attemptToken);

    // (1) 他 org / 他 user の token は 404 (403 にしない = 存在オラクル封じ)。Gate より前。
    abort_if($subscriptions->attemptTokenIsForeign($attemptToken, $organization, $user), 404);
    // (2) 認可
    Gate::authorize('manageBilling', $organization);
    // (3) plan 解決 → 冪等開始
    $planCode = $request->validated('plan_code');  Assert::string($planCode);
    $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

    try {
        $result = $subscriptions->startCheckout(
            $organization, $user, $plan,
            route('billing.index').'?session_id={CHECKOUT_SESSION_ID}',
            route('billing.plans'),
            $attemptToken,
        );
    } catch (SubscriptionAttemptPlanMismatchException $e) {
        throw ValidationException::withMessages(['plan_code' => $e->getMessage()]);   // 422
    } catch (StaleCheckoutAttemptException) {
        return redirect()->route('billing.index', ['retry' => 1]);                     // → checkout_retry_required
    } catch (CheckoutInProgressException|StripePriceNotSyncedException $e) {
        return back()->with('error', $e->getMessage());
    }

    if ($result->url === null) {
        return $this->isAttemptCompleted($organization, $result->stripeSessionId)
            ? redirect()->route('billing.index', ['replayed' => 1])                    // → purchase_already_received
            : back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。');
    }

    return Inertia::location($result->url);
}
```

**禁止事項 #7**（`redirect()->intended()`）は使わない。**禁止事項 #8**: `Billing/Plans` の申込ボタンは token / plan の状態で disabled にせず、押下時に上記のエラー・422 を表示する。

**着地 feedback（`resolveBillingFeedback`。verbatim。UI は raw query を見ない）**

| query | 条件 | kind / 文言 |
|---|---|---|
| `?portal` | **`session('error')` が文字列なら `null`**（成功偽装の抑止。aigenba F-2-03 verbatim） | `portal_returned` /「お支払い管理画面から戻りました。」 |
| `?session_id=` | `$organization->checkoutSessions()->where('stripe_session_id', …)` で **org スコープ**（未知 / 他 org は `null` = 偽 success 排除）。`intent !== subscription_start` も `null`（fail-closed） | `Completed` → `purchase_received` /「お支払いを受け付けました。プランへの反映には数分かかる場合があります。」<br>`Pending` → `purchase_processing` /「お支払いを確認しています。プラン反映までしばらくお待ちください。」<br>`Failed` / `Expired` → **`null`**（verbatim） |
| `?replayed` | — | `purchase_already_received` /「この内容のお支払いは既に受け付け済みです。」 |
| `?retry` | — | `checkout_retry_required` /「お手続きの有効期限が切れました。画面を再読み込みして再試行してください。」 |

**aigenba からの非 verbatim 点と根拠（4 点のみ。他は verbatim）**

| 点 | aigenba | AI-CUE (P9) | 根拠 |
|---|---|---|---|
| **同 token・別 plan** | `replayCheckout()` で**保存済み session の plan** の Checkout URL へ収束（planCode は保存値。Codex impl-review R1 Warning でこの形に確定） | **422**（`SubscriptionAttemptPlanMismatchException`） | `Billing/Plans` は 1 render = 1 token のため「Starter を押して戻り Standard を押す」が同 token・別 plan として実在する。verbatim だと**押した plan と違う plan の Checkout に着地**する。AI-CUE の先例（`TicketCheckoutService`: 同 token・別 count は replay せず stale）とも整合。**ユーザー指示（P9 要件 6）による明示決定** |
| **`initiated_by_user_id` の actor scope** | subscription 経路は org スコープ（actor scope は `TicketService` = T905 R1/R2 Critical で採用済み） | **token 所有者判定（要件 7 の 404）にのみ actor scope を適用**。dedup は org-wide のまま | aigenba 自身が同一の replay 機構に対し T905 で下した結論（cross-user replay 防止）を、P2 が移植済みの `initiated_by_user_id` 列に適用する。**dedup を actor scope にはしない**（subscription の org singleton 性を壊すため） |
| **`idempotency_key` 列の値** | `sprintf('sub_start:%d:%s:%d:%d', org, priceId, seatOverflow, floor5min(now))`（T680 で dedup 用途からは外れた**遺物**。aigenba 自身が「旧実装は idempotency_key 一致でのみ dedup していた」と注記） | **Stripe へ渡した key と同値**（`'sub_start:'.$attemptToken`） | 5 分バケット key は同 org・同 price の別 token が同バケットに入ると UNIQUE 衝突し、`isUniqueViolation()`（attempt_token 違反のみ replay）に拾われず **500** になる死角がある。attempt_token と 1:1 の key にすると衝突クラスが消え、列の意味が「Stripe に送った key」に一致する（要件 5） |
| **`PurchasePaymentFailed`** | — | **作らない**（v1 の発明を撤回。5 case verbatim） | 原則 1。`Failed` 着地の無言性は aigenba にある既知の性質であり、**AI-CUE 側で先回り修正しない**（原則 5） |

**webhook（金銭に触れない境界）**

```php
// StripeWebhookProcessor::settleSubscriptionCheckout(array $payload): void
// (1) purpose ガード: metadata.purpose !== 'subscription_start' → 受理のみ / mode !== 'subscription' → 受理のみ
//     (既存 grantPurchasedTickets の 'ticket_purchase' + mode=payment ガードは無改変。相互に排他)
// (2) 真実源は自 DB 行。行不在 → throw = retryable failure (crash 先着 webhook は再試行で収束)
// (3) tenant キー不信: payload の customer / metadata.org_ref は照合のみ (不一致は throw)。org 解決には使わない
// (4) payment_status: in_array($s, ['paid','no_payment_required'], true) → Completed / 'unpaid' → Failed
// (5) Pending 以外は触らない (Completed 終局 = 再送 no-op。aigenba markLocalCheckoutCompleted verbatim)
// チケット・プランの付与も plan_code 同期も**ここでは一切行わない** (D7: invoice.paid / customer.subscription.* が真実源)。
```

**PII（不変条件 #6。email だけでなく name も閉じる）**

```php
// App\Models\Organization
class Organization extends Model implements CipherSweetEncrypted, /* 既存 */
{
    use Billable, HasFactory, RoutesNotifications, SoftDeletes, UsesCipherSweet;

    /** @var list<string> billing_contact_* は含めない (UpdateBillingContactAction が明示代入) */
    protected $fillable = ['name', 'slug'];

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        // 両列とも nullable のため addOptionalTextField (addField は null で fieldNotOptional 例外 = Inquiry の先例)
        $encryptedRow
            ->addOptionalTextField('billing_contact_email')
            ->addOptionalTextField('billing_contact_name')
            // 検索契約: 請求調査 (Stripe Dashboard の請求先メール → AI-CUE 組織の逆引き = 返金・
            // 二重課金の一次対応で唯一の特定経路) のため email のみ blind index 化する。
            // Lowercase transformer で大文字小文字差を吸収 (値全体ハッシュ = 完全一致のみ)。
            ->addBlindIndex('billing_contact_email', new BlindIndex('organization_billing_contact_email_index', [new Lowercase]));
        // billing_contact_name は blind index を張らない (等値検索の要求が無い = 検索が必要な項目だけ whereBlind)。
    }

    /** 請求通知の宛先: billing_contact_email 正本 → owner email fallback (aigenba IV-1/IV-N1) */
    public function routeNotificationForMail(Notification $notification): ?string;

    /** @return HasMany<BillingCheckoutSession, $this> */
    public function checkoutSessions(): HasMany;
}
```

- **検索契約**: `billing_contact_email` の検索は **`Organization::whereBlind('billing_contact_email', 'organization_billing_contact_email_index', $value)` のみ**。平文 `where('billing_contact_email', …)` は hit しない。保存値は `EmailNormalizer::normalize()` 済みのため検索入力も**同一正規化を通す**（`inquiries` と同じ規約。`users.email` の raw 保存規約とはここで意図的に異なる — `UpdateBillingContactData` が正規化を保証する）。
- **`billing_contact_name` の検索は契約として存在しない**（blind index 行を作らない = 平文検索も blind 検索もできない）。
- **一意制約は張らない**（複数組織が同一請求先メールを持つのは正当）。`blind_indexes` の `UNIQUE(indexable_type, indexable_id, name)` は 1 レコード 1 行の担保であり値の一意性ではない。
- **cast**: `casts()` に `billing_contact_*` を**追加しない**（CipherSweet が row-level で暗号化/復号する。`encrypted` cast を重ねると二重暗号化になる。`User::two_factor_secret` の既存注記と同じ判断）。
- **soft delete**: `Organization` は `SoftDeletes` のため blind index 行は残る（hard delete しない = `Inquiry` の「query builder 一括 delete 禁止」問題は発生しない）。
- **更新経路**: `PATCH /billing/contact` → `Gate::authorize('manageBilling', $organization)` + **current-org scope**（`ResolvesCurrentOrganization`。route parameter を持たないため cross-org 指定が構造的に不能）→ `UpdateBillingContactAction`。

```php
// App\Http\Controllers\Billing\BillingController
public function updateBillingContact(UpdateBillingContactRequest $request, UpdateBillingContactAction $action): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    Gate::authorize('manageBilling', $organization);
    $action->execute($organization, UpdateBillingContactData::fromRequest($request));

    return back()->with('info', '請求先情報を更新しました。');
}
```

**DTO 形状（PHP `@phpstan-type` と `resources/js/types/billing.ts` を exact 対で保守）**

```
BillingFeedbackShape  = { kind: 'purchase_received'|'purchase_processing'|'purchase_already_received'
                                |'checkout_retry_required'|'portal_returned',
                          message: string }
BillingContactShape   = { email: string|null, name: string|null, fallbackEmail: string|null }  // fallbackEmail = owner email (未設定時の実宛先を UI に明示)
BillingDashboardShape = { …P8b の全項目 (billingState / plan / balance / quota), feedback: BillingFeedbackShape|null,
                          billingContact: BillingContactShape }
BillingPlansPageShape = { plans: list<PricingPlanShape>, billingState: BillingStateValue, currentPlanCode: string|null,
                          canManage: bool, subscriptionAttemptToken: string }
```

**UI**: `Billing/Index.svelte` は `templates/PageContainer` / `molecules/PageHeaderSection` / `templates/PageContent`（T071 primitive）配下に feedback バナーと `BillingContactForm` を置く。DS token のみ（hex 直書き禁止）。アイコンは `@lucide/svelte`（`CircleCheck` / `Clock` / `Receipt`）。**判定源は `page.billingState`（`OnboardingBillingState` の値）**。`EffectivePlan` は存在しない。

#### PHPStan 適合チェック

- `BillingCheckoutSession::$status` / `$intent` は **P2 verbatim の plain string 列 + `statusEnum()` / `intentEnum()`**（enum cast ではない）。比較は `$row->status === CheckoutSessionStatus::Pending->value` の**文字列比較**で書く（P2 の `state()` と同形。cast 前提で `=== CheckoutSessionStatus::Pending` と書くと `alwaysFalse`）。
- `Cache::lock()->block()` は `mixed` を返すため、`TicketCheckoutService` と同じく `Assert::isInstanceOf($result, CheckoutSessionDto::class)` で絞る（`@var` コメントでの黙らせをしない）。
- `attemptTokenIsForeign()` は `->exists()` を返す `bool`。`where(fn (Builder $q) => …)` の closure 引数に `@param Builder<BillingCheckoutSession>` を付す。
- `BillingFeedbackDto::toArray()` は `@phpstan-type SimpleBillingFeedbackKind` + `@return BillingFeedbackShape`。`$this->kind->value` は `string` に広がるため、aigenba と同じく `/** @var SimpleBillingFeedbackKind $kindValue */` で literal union へ narrow（型の widen ではなく enum → literal の再表明）。
- `resolveBillingFeedback()` の `$request->query('session_id')` は `mixed` → `is_string($x) && $x !== ''` で narrow してから使う（`?->` で握り潰さない）。`$request->session()->get('error')` も `is_string()` で判定（verbatim）。
- `Organization::routeNotificationForMail(): ?string` — `billing_contact_email` は `?string`。`EmailNormalizer::normalize(string): string` は非 null 引数を要求するため、`is_string() && trim() !== ''` で narrow してから渡す（aigenba の `normalize(?string)` シグネチャへ寄せない = AI-CUE の既存 `EmailNormalizer` を改変しない）。
- `UpdateBillingContactData::fromRequest()` は `$request->string(…)->toString()` + `Assert::stringNotEmpty()`、name は `$request->input()` の `mixed` を `is_string() && trim() !== ''` で narrow（verbatim）。
- `StripeGatewayInterface::createSubscriptionCheckout()` の `array $metadata` は `@param array<string, string>`。`CashierStripeGateway::buildSubscriptionSessionPayload()` は戻り値を `@return array{mode: 'subscription', customer: string, line_items: …, subscription_data: array{metadata: array<string, string>}, success_url: string, cancel_url: string}` で固定（`CashierTicketCheckoutGateway::buildSessionPayload()` と同様式）。
- `isUniqueViolation(QueryException $e)`: `$e->getCode()` は `mixed`（`Throwable::getCode()` の宣言が緩い）→ `in_array($e->getCode(), ['23000','23505'], true)` の前に string 化しない（aigenba verbatim。strict 比較で型不一致は false に落ちる）。**INSERT は `UniqueConstraintViolationException`（Laravel 11+ が `QueryException` を specialize）で catch し、driver 差の判定は `isUniqueViolation()` に委ねる**。
- webhook 側の `data_get()` の `mixed` は既存 `stringAt()` helper で narrow。`payment_status` は `in_array($status, ['paid','no_payment_required'], true)` で判定（Stripe 値集合は enum 化しない = payload 由来の外部語彙）。
- 型を緩めた回避・baseline 化は行わない（禁止事項 2）。

#### テスト計画

**テストファースト**。`RefreshDatabase` グローバル + `--parallel`（個別 `DatabaseTransactions` 禁止）。テストデータは Factory のみ。

新規 `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php`（**要件 1-7**）:
1. 同一 `subscription_attempt_token` + 同一 plan の 2 連投で **`billing_checkout_sessions` が 1 行**、2 回目は**既存 `checkout_url` へ収束**し `FakeStripeGateway` の作成呼び出しが **1 回**（要件 1 / 4）。
2. 同一 token + **別 plan_code** → **422**（`assertInvalid(['plan_code'])`）。行は増えず Stripe 呼び出しも増えない（要件 6）。
3. `idempotency_key === 'sub_start:'.$attempt_token`、かつ同 key の再呼び出しで fake が**同一 sessionId** を返す（要件 5）。ticket 側の `purchase:{token}` と**衝突しない**（key 空間分離）。
4. **他 org の token** で POST → **404**（`Gate` 到達前。`manageBilling` を持つ owner でも 404）。**同 org の他 user の token** → **404**（要件 7 / 2）。いずれも**行が作られない**（silent fallthrough の回帰防止）。
5. `completed()` 行の token 再送 → `billing.index?replayed=1` へ redirect、Stripe 呼び出し 0（要件 4）。
6. `expired()` / `failed()` 行の token 再送 → `billing.index?retry=1`（`StaleCheckoutAttemptException` 経路）。
7. **別 token・同 plan の live pending** → `back()->with('warning')`、**新規行なし・Stripe 呼び出しなし**（org-wide dedup = 二重 subscription の封じ）。**同 org の別 user が別 token で申し込んでも同じく 1 本に収束する**（actor scope を dedup に持ち込まないことの固定 = 要件 2）。
8. **別 token・別 plan の live pending**: `expireCheckoutSession` が `'complete'` → `CheckoutInProgressException` → `back()->with('error')`、**新規行なし**。gateway が throw → 同じく停止し local 行は `Pending` のまま（上書きしない）。`'expired'` → 旧行が `Expired` になり新規発行が続行。
9. `UniqueConstraintViolationException` 注入（並行 race 模擬）→ **500 にならず** replay / stale へ収束。**attempt_token 以外の unique 違反は rethrow**（`isUniqueViolation()` の識別子判定）。
10. 既に `valid()` な subscription を持つ org → `'既に有効なサブスクリプションがあります。…'` で停止（行なし）。
11. `initiated_by_user_id` が**必ず非 null** で記録される（要件 2 の監査行）。

新規 `tests/Feature/Billing/SubscriptionCheckoutWebhookRaceTest.php`（**要件 8**）:
12. `checkout.session.completed`（purpose=subscription_start / mode=subscription / payment_status=paid）→ 行 `Completed` + `completed_at` 設定。**チケット付与も `plan_code` 書き換えも起きない**（`ticket_ledger_entries` 0 件 / `organizations.plan_code` 不変 = D7 境界の回帰）。
13. 同一 event の **再送**（event_id 違いを含む）→ 冪等（行は `Completed` のまま、二重処理なし）。
14. **`Expired` 行への遅延 completed** → `Completed` へ遷移する（決済成立を取りこぼさない）。
15. `payment_status=unpaid` → `Failed`。その後同 session の `paid` 再送 → `Completed`。
16. **cancel 相当**（ユーザー離脱 = completed が来ない）→ 行は `Pending` のまま。`created_at` を 2 日前にすると `BillingAccess::state()` が **`ExpiredCheckout`** を返し（P2 契約）、**新 token で新規 Checkout が作れる**。`state()` 実行で**行が書き換わらない**（read 経路 no-write）。
17. 行不在の completed → throw = retryable failure（既存 `handle()` の catch で `failed` 記録。**silent 付与しない**）。
18. `customer` / `metadata.org_ref` 不一致 → throw（tenant キー不信）。
19. **purpose ディスパッチの排他**: `purpose=ticket_purchase` の payload は `settleSubscriptionCheckout` に入らず既存 `grantPurchasedTickets` が従来どおり動く（`TicketPurchaseWebhookTest` が**無改変で green**）。

新規 `tests/Feature/Billing/BillingFeedbackTest.php`:
20. `?session_id=` が自 org の `Completed` 行 → `page.feedback.kind === 'purchase_received'`。`Pending` → `purchase_processing`。**`Failed` / `Expired` → `feedback === null`**（verbatim）。
21. **他 org / 未知の `session_id`** → `page.feedback === null`（偽 success 排除）。`intent=setup_payment_method` の行 → `null`（fail-closed）。
22. `?portal` + `session('error')` あり → `null`（成功偽装の抑止）。error 無し → `portal_returned`。
23. `?replayed` → `purchase_already_received` / `?retry` → `checkout_retry_required`。

新規 `tests/Feature/Billing/BillingContactPiiTest.php`（**不変条件 #6**）:
24. `PATCH /billing/contact` 後、**`DB::table('organizations')` の生値が `billing_contact_email` / `billing_contact_name` の平文と一致しない**（両方）。model 経由の読み出しは平文に復号される。
25. **平文 where が hit しない**: `Organization::query()->where('billing_contact_email', $plain)->exists()` が false。`whereBlind('billing_contact_email', 'organization_billing_contact_email_index', $plain)` が該当 org を引く。
26. **`billing_contact_name` の blind index 行が存在しない**（`blind_indexes` に name 系 index が 0 件 = 検索契約の固定）。
27. 大文字混じり入力で保存 → 正規化後の小文字で `whereBlind` が hit（`EmailNormalizer` 経路の固定）。

新規 `tests/Feature/Billing/UpdateBillingContactTest.php`:
28. **email 変更時のみ** `SyncBillingCustomerDetails` job が dispatch される（`Queue::fake()`）。**name のみ変更では dispatch されない**（IV-5 / IV-6）。
29. `stripe_id === null` の org では job が dispatch されない（`BillingCustomerSynchronizer` の no-op 契約）。transaction rollback で発火しない（`afterCommit`）。
30. **認可**: member（非 owner/admin）は 403。未ログインは redirect。**current-org scope**: org 切替後の PATCH が切替後 org のみを更新する。
31. **payload 契約**（要件 9 / 不変条件 #1）: `organization_id` / `initiated_by_user_id` / `plan_id` を body に混ぜると **422**（`ProhibitsProtectedKeys`）。`billing_contact_email` 欠落 → 422。
32. `routeNotificationForMail()` が `billing_contact_email` 正本 → 未設定時に owner email へ fallback。

新規 `tests/Feature/Architecture/BillingContactEncryptionInvariantTest.php`:
33. `Organization` が `CipherSweetEncrypted` を実装し、`configureCipherSweet()` に `billing_contact_email` / `billing_contact_name` の**両方**が登録されている（列を足して暗号化を忘れる回帰の構造的封じ）。
34. `organizations` の `billing_contact_*` 列型が `text`（`string(255)` への差し戻しで ciphertext が切れる回帰の封じ）。
35. `billing_contact_*` が `$fillable` に無い。

更新 `tests/Feature/Billing/BillingCheckoutRequestTest.php` 相当:
36. `subscription_attempt_token` 欠落 / 非 ULID → 422。

JS（Vitest）:
37. 新規 `tests/js/pages/Billing/BillingContactForm.test.ts` — 未入力でも **submit ボタンが disabled にならない**（禁止事項 #8）。押下時にサーバ 422 の `errors.billing_contact_email` が表示される。
38. 更新 `tests/js/pages/Billing/Index.test.ts` — `feedback` の **5 kind** が対応バナーを描画し、`feedback: null` で何も描画しない。**raw query（`session_id` 等）を参照しない**。
39. 更新 `tests/js/pages/Billing/Plans.test.ts` — POST body に `subscription_attempt_token` が載る。ボタンは常に enabled。422 が `plan_code` エラーとして表示される。
40. 影響（無変更で green）: `tests/js/architecture/{page-shell-structure,ds-purity,atomic-import-graph,lucide-scoped-import}.test.ts`。

#### リスク

| リスク | 緩和 |
|---|---|
| **`CashierStripeGateway` が `newSubscription()->checkout()` を捨てることで Cashier の webhook が `subscriptions` 行を作れなくなる**（Cashier は `subscription_data.metadata.{name,type}` を見て行を作る。落とすと**課金成立なのに subscription 行が無い** = `state()` が `NoSubscription` に落ち P4 後に締め出し） | `buildSubscriptionSessionPayload()` を public pure メソッドにし、**`subscription_data.metadata.name='default'` / `type='default'` を含むことを gateway ユニットテストの invariant として固定**（`CashierTicketCheckoutGateway::buildSessionPayload()` の promo/tax invariant と同じ様式）。加えてテスト 12 で「completed webhook 後に `customer.subscription.created` が来ると `subscriptions` 行が作られる」ことを確認する。**この invariant テストが payload 変更の唯一の入口** |
| **`checkout.session.completed` への arm 追加がチケット付与を壊す**（P5/T007 の金銭経路） | `grantPurchasedTickets()` は **purpose ガードごと無改変**（既に `!== 'ticket_purchase'` で受理のみ = 相互排他が成立済み）。`TicketPurchaseWebhookTest` / `WebhookIdempotencyTest` を**無変更で green** に保つことを DoD にする（変更が要るなら設計が誤り） |
| **同 token・別 plan の 422 が aigenba からの逸脱**（原則 1 に対する例外） | 逸脱は**この 1 点に限定**し、根拠（1 render = 1 token 構造 + 押した plan と違う Checkout への着地 + AI-CUE の ticket 先例）を Service の docblock に明記する。**aigenba 側へ報告し、先方が replay 継続を選ぶなら verbatim へ戻す**（原則 5 の運用）。テスト 2 がこの分岐の唯一の契約 |
| **`idempotency_key` を attempt_token 由来に変えたことで aigenba と差分が出る** | aigenba 側で当該列は T680 以降 dedup に使われていない遺物であり、**意味論の後退はない**（むしろ 5 分バケット衝突による 500 の死角が消える）。テスト 3 で「列値 == Stripe へ渡した key」を固定し、差分を 1 箇所に閉じる |
| **`Organization` への CipherSweet 導入が既存の org 検索・Filament を壊す** | 暗号化するのは新規 additive 2 列のみ。`name` / `slug` は平文のまま（暗号化すると `slug` の route 解決・`unique` 制約・既存 `OrganizationFactory` が全滅する）。既存 org 行は `billing_contact_*` が null のため `addOptionalTextField` で素通し = backfill 不要 |
| **`billing_contact_email` を Stripe へ同期することで PII が外部へ出る** | 元々 Stripe customer は課金主体として email を保持しており（現行 `syncStripeCustomerDetails()` が owner email を送っている）、送信先・送信内容は不変。**`billing_contact_name` は Stripe へ送らない**（aigenba IV-6 verbatim）ため PII 露出面は増えない。CipherSweet は保管時（自 DB）の保護であり、この境界は変わらない |
| **feedback バナーが「成功」を偽装する**（webhook 未達で行が `Pending` のまま「受け付けました」と出る） | `session_id` は**自 org の DB 行と照合できたときのみ** feedback を出し、行の `status` を文言の唯一の根拠にする（`Pending` は「確認しています」= 確定表現を使わない）。任意 query（`?replayed` / `?retry`）は状態を主張しない中立文言のみに割り当てる |
| **`Failed` 着地が無言**（aigenba verbatim のため「何も起きていない画面」に着地する） | **aigenba にある既知の性質として意図的に継承する**（原則 5: 先回り修正しない = v1 で `PurchasePaymentFailed` を発明して parity を壊した失敗の再発防止）。ユーザーの出口は `Billing/Plans` からの新規 token 発行（1 クリック）で常に存在し、恒久的に詰む経路は無いことをテスト 6 / 16 で固定する。**aigenba へ報告し、先方が文言を足したら取り込む** |
| **live pending dedup の `expireCheckoutSession` 失敗で checkout が詰む** | fail-closed（新規作成せずエラー着地）は二重 live session を作らないための **aigenba verbatim の意図的挙動**。出口は (a) 同 token 再送 → 元の `checkout_url` へ収束、(b) `created_at` 経過後に `state()` が `ExpiredCheckout` を返し新 token で再開、の 2 本。テスト 8 / 16 で固定 |
| **P2 が writer なしで導入した `billing_checkout_sessions` に、P9 の writer が `state()` の想定と食い違う行を書く** | P9 は列を足さず、`status` / `checkout_url` / `created_at` の意味論を P2 の `state()` 分岐表（`PendingCheckout` = live pending / stale・expired・failed = `ExpiredCheckout`）に合わせる。テスト 16 で **writer 経由で作った行が `state()` の期待どおりに読まれる**ことを end-to-end で固定する（P2 は Factory 直挿しでしか検証していない） |
