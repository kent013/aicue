Round 14 の Critical 2 / Warning 1 を全て対応した（3 点とも P9 周辺の整合）。

1) **P9 に T1004 を実装**（P9 再生成）: P8a から移譲された PM 流用一式を実装契約として本文へ追加した
   （`ReuseSubscriptionPaymentMethodJob` / `applyReusedPaymentMethod` / `resolveSubscriptionPaymentMethod` /
   `hasRecentAutoRechargeFundedSignup` / `billing_checkout_sessions.{funding_choice, pm_reuse_dispatched_at}` の **additive 列** /
   `settingsFor.setupPending` の条件 / 着地 flash 分岐 / **`consent_version` を `'v2'` へ改定**（v1 同意は
   `reconsentRequiredFor` で自動失効 = fail-closed））。**非スコープからは削除**。適格性は**先行 fail-closed**。
2) **writer 時期の整合**: P9 の前提を「**最初の writer は P8a**（`intent=setup_payment_method`）／**P9 が書くのは
   `intent=subscription_start` の行**。冪等状態機械・dedup・feedback・sweeper は **P8a の setup 行と同居する前提**」へ。
   **P2 のリスク行**（「P2 では行 0 件（writer 不在）」）も writer 時期（P8a → P9）と sweeper 所管（P9）の事実へ更新した。
3) **stale 境界の `<=` 残存**（私の直し漏れ）: `expireStaleCheckouts()` を **`created_at < staleThresholdAt()`** へ統一し、
   変更箇所表にも「境界は排他: live 判定 `>=` の補集合」を明記。**機械確認で旧 `<=` は 0 件**。

機械確認: v1 の発明（EffectivePlan / NoPlan / isDeclared / debt / is_active=false / PlanCode 3 case）の生きた参照 0、
「〜でよいか」「openQuestion」「要確認」0、旧 `<=` 境界 0。

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
| **P2** | サブスク層 + **判定モデル**（`OnboardingBillingState`(5 状態) + `BillingAccess::state()` を verbatim 移植 / `SubscriptionService::deriveEntitlement()` / `SubscriptionSnapshot` / `BillingCustomerSynchronizer` / `BillingPermissionService` / **`BillingCheckoutSession` テーブル**（state() が読むため P9 から前倒し）） | `app/Services/Billing/*`, `app/Enums/Billing/OnboardingBillingState.php`, `app/Models/Billing/BillingCheckoutSession.php` + migration | Critical | **挙動不変ではない**（判定モデルの置換。**cohort C（trial 終了 + PM 無し）が遮断へ / D（past_due + PM 有り）が許可へ反転**。既存行の `has_payment_method=true` backfill により**デプロイ時点の cohort C は空**）|
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

### P2 サブスク層 + 判定モデル: `OnboardingBillingState` / `BillingAccess::state()` の verbatim 移植と Gateway 系置換

前提: P1 で `App\Enums\PlanCode`（5 case・`requiresStripeCheckout()`）/ `PersonalPlanService`（`FREE_PLAN_CODE='personal'`）/ `organizations.{free_plan_code, free_plan_activated_at, personal_declared_at, personal_declared_by_user_id, signup_tickets_granted_at}` + partial unique index / `PlanPriceService` / `plans.is_active` が入っている。

**DoD**: `BillingAccess::state()` と `SubscriptionService::deriveEntitlement()` が aigenba verbatim で入り、`hasActiveAccess()` が `state()->grantsAccess()` + **移行 OR 1 行**（`$org->plan_code === null`。P4 で削除）になる。migration は additive のみ（`billing_checkout_sessions` 新規 + `subscriptions.has_payment_method` 追加 + backfill）。route 変更ゼロ・Inertia props 変更ゼロ・TypeScript 型変更ゼロ。

**DoD は「挙動不変」を主張しない**（Round 13 Critical を受けて撤回）。P2 は判定モデルそのものの置換であり、**`hasActiveAccess()` の結論が変わる cohort が 2 つある**（下表 C / D）。`state()` は現行に対応物が無い新 API のため、`state()` 側は「同値」概念自体が成立しない。

#### P2 導入で結論が変わる cohort（全列挙）

現行 `BillingAccess::hasActiveAccess()`（`/workspace/app/Services/Billing/BillingAccess.php:36-51`）= 「`plan_code === null` → 許可 / 非 null → `subscription('default')` が存在し `stripe_status ∈ GRANTING_STATUSES(['active','trialing'])`」。
P2 = `state()->grantsAccess() || $org->plan_code === null`。移行 OR が `plan_code === null` を丸ごと保存するため、**変化は `plan_code` 非 null 側にのみ発生する**。

| # | cohort（`plan_code` 非 null） | 現行 | P2 | 変化 |
|---|---|---|---|---|
| A | `active`/`trialing`・`trial_ends_at` が null または未来 | 許可 | `Active`（または `UpgradeRecovery`）→ `Subscribed` = 許可 | なし |
| B | `active`/`trialing`・`trial_ends_at <= now`・`has_payment_method=true` | 許可 | `Subscribed` = 許可 | なし |
| **C** | **`active`/`trialing`・`trial_ends_at <= now`・`has_payment_method=false`** | **許可**（status だけを見るため） | **`denied(TrialEndedWithoutPaymentMethod)` → `ExpiredCheckout` = 遮断** | **P2 で遮断へ反転** |
| **D** | **`past_due`・（`trial_ends_at` が null/未来 または `has_payment_method=true`）** | **遮断**（`past_due ∉ GRANTING_STATUSES`） | **`PastDue`→`grantsAccess()=true`→`Subscribed` = 許可** | **P2 で許可へ反転** |
| E | `past_due`・`trial_ends_at <= now`・`has_payment_method=false` | 遮断 | `denied(TrialEndedWithoutPaymentMethod)` → 遮断 | なし |
| F | `paused` | 遮断 | `Paused` → `denied(Paused)` → `ExpiredCheckout` = 遮断 | なし |
| G | `canceled` / `unpaid` / `incomplete` / `incomplete_expired` | 遮断 | `Inactive` → `denied(NoActiveSubscription)` → `ExpiredCheckout` = 遮断 | なし |
| H | subscription 行なし | 遮断 | `NoSubscription` = 遮断 | なし |
| I | `plan_code === null`（sub 行の有無・status を問わず） | 許可 | state の結論に依らず**移行 OR で許可** | なし |

**根拠**: `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:126-155`（`deriveEntitlement`）/ `/tmp/aigenba/app/Enums/Billing/SubscriptionState.php:49-98`（`fromSubscription` / `grantsAccess`）/ `/tmp/aigenba/app/Services/Billing/BillingAccess.php:31-93`（`state`）。
**cohort C は「P4 分類2 の反転目的」ではなく P2 で起きる**。P4 の判定変更は移行 OR 1 行の削除（= cohort I の反転）**だけ**であり、C / D は P2 の成果物として DoD・テスト・分類表に載せる。

**cohort C の実データ露出と backfill の効果**:
- `subscriptions.has_payment_method` の既定値は **aigenba verbatim で `false`**（`/tmp/aigenba/database/migrations/2026_06_25_090100_add_signup_trial_columns_to_subscriptions.php`）。
- **既存行は backfill で `true`** にする → **P2 デプロイ時点の cohort C は空**（既存の有償 org は 1 件も締め出されない）。根拠: AI-CUE の subscription 生成経路は `CashierSubscriptionCheckoutGateway::createSubscriptionCheckout()`（`newSubscription('default',…)->checkout()` = mode=subscription）のみで PM 収集が必須 → 既存行の事実値は `true`。aigenba の default `false` は「trial 中カード無し signup」経路が存在する前提の値で、その経路を持たない AI-CUE の既存行には当てはまらない。`recordPaymentMethodSnapshot()` は monotonic（`true→false` に戻さない。`/tmp/aigenba/app/Services/Billing/SubscriptionService.php:390-393`）なので backfill 値は以後保存される。
- **P2 以降に作られる行**は default `false` から始まる。`trial_ends_at` を set する app コードは AI-CUE に存在しない（`grep -rn "trial_ends_at" app/` はヒットなし）ため、`trial_ends_at` が入るのは Cashier `WebhookController.php:74-75,161-165` が Stripe payload の `trial_end` を写す場合のみ = **Stripe 側（Price / Dashboard）で trial を設定した契約に限る**。この場合 trial 中は `trial_ends_at` が未来なので cohort A（許可）で、trial 終了時に Stripe が発火する `customer.subscription.updated` が `recordPaymentMethodSnapshot()` を通して `has_payment_method=true` を確定させる。**webhook 到達までの窓で cohort C になるのは aigenba が意図した「webhook の paused 化前でも先回り遮断」そのもの**（`SubscriptionService.php:138` のコメント）であり、先回り修正しない（原則 1・5）。

**行の materialize 順序（P2 の契約として固定する）**: AI-CUE の `StripeWebhookProcessor` は `Event::listen(WebhookReceived::class, …)`（`/workspace/app/Providers/AppServiceProvider.php:188`）で、Cashier は `WebhookReceived::dispatch()` を**ハンドラ実行前**に発火する（`vendor/laravel/cashier/src/Http/Controllers/WebhookController.php:45-49`）。よって `customer.subscription.created` の時点では行が未作成で、`recordPaymentMethodSnapshot()` は **行不在の早期 return（verbatim）** で no-op になり、最初の権威 PM 書込は最初の `customer.subscription.updated` に載る。**aigenba の `applySubscriptionSnapshot` 末尾の `Subscription::create($attrs)` は移植しない**: aigenba は Cashier の `WebhookController` を使わず自前 `StripeWebhookController` が唯一の writer である（`/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php`）のに対し、AI-CUE は Cashier のハンドラを使う。listener 側で先に行を作ると Cashier 側の `! $user->subscriptions->contains('stripe_id', $data['id'])` ガード（`WebhookController.php:73`）が false になり **`subscription_items` の生成（同 94-101）が永久に skip される**。移植すると既存契約が壊れる = 原則 4（AI-CUE に対象が存在しない aigenba 機能は移植しない）の適用。

#### 変更箇所

| ファイル（AI-CUE） | 何をするか | 移植元（aigenba） |
|---|---|---|
| `app/Enums/Billing/OnboardingBillingState.php`（新規） | **verbatim**。`NoSubscription` / `PendingCheckout` / `ExpiredCheckout` / `Subscribed` / `ActiveFreePlan` の 5 case + `grantsAccess() = Subscribed \|\| ActiveFreePlan`。docblock も移植 | `/tmp/aigenba/app/Enums/Billing/OnboardingBillingState.php` |
| `app/Enums/CheckoutSessionStatus.php`（新規） | **verbatim**（`Pending` / `Completed` / `Failed` / `Expired`）。名前空間も verbatim（P1 の `app/Enums/PlanCode.php` と同じ配置） | `/tmp/aigenba/app/Enums/CheckoutSessionStatus.php` |
| `app/Enums/CheckoutIntent.php`（新規） | `SubscriptionStart='subscription_start'` / `SetupPaymentMethod='setup_payment_method'` の 2 case。`CreditPurchase` は AI-CUE では既存の別テーブル `app/Models/Billing/TicketCheckoutSession.php` が担い、`SignupFunding` は campaign / trial 機構（`signup_campaigns`）が無いため移植しない（原則 4） | `/tmp/aigenba/app/Enums/CheckoutIntent.php` |
| `database/migrations/2026_07_17_000200_create_billing_checkout_sessions_table.php`（新規） | aigenba の 6 本（create + unit_amount + attempt_token + signup_funding + initiated_by + pm_reuse）を **create 1 本に畳んで移植**。列: `id` / `organization_id`(FK cascade) / `initiated_by_user_id`(FK users nullOnDelete) / `intent`(32) / `plan_code`(32 nullable) / `stripe_session_id`(unique) / `idempotency_key`(128 unique) / `attempt_token`(nullable) / `checkout_url`(2048 nullable) / `status`(16 default `'pending'`) / `completed_at` / `timestamps`。index: `['organization_id','intent','status']` + unique `['organization_id','intent','attempt_token']`（名 `billing_checkout_sessions_org_intent_attempt_unique`）。**`seats`（席概念なし）/ `credit_count`・`unit_amount`（ticket 側テーブルが担う）/ `funding_choice`・`topup_count`・`applied_campaign_id`・`applied_trial_days`（campaign・trial 機構なし）/ `pm_reuse_dispatched_at`（`ReuseSubscriptionPaymentMethodJob` を移植しない）は列ごと非移植**（原則 4。必要になった P8a/P9 で additive 追加） | `/tmp/aigenba/database/migrations/2026_04_14_011321_create_billing_checkout_sessions_table.php` ほか 5 本 |
| `app/Models/Billing/BillingCheckoutSession.php`（新規） | **verbatim**（移植列に限定）。`$fillable` / `$casts` / `intentEnum()` / `statusEnum()` / `isReplayablePending()` / `organization()` + `@property` docblock | `/tmp/aigenba/app/Models/Billing/BillingCheckoutSession.php` |
| `database/factories/Billing/BillingCheckoutSessionFactory.php`（新規） | `definition()`（`intent=subscription_start` / `stripe_session_id='cs_'.Str::random(24)` / `idempotency_key='checkout:'.Str::uuid()` / `status=pending`）+ `withAttemptToken()` / `initiatedBy()` / `completed()` / `setupPaymentMethod()`。**`creditPurchase()` / `signupFunding()` は非移植列を触るため落とす**。`state()` の分岐 4/5 を固定するため `expired()` / `failed()` / `stale()`（`created_at = now()->subDays(2)`）を足す（aigenba の同 factory に無い分の追加は **新モデルには Factory を作る / テストデータは Factory で生成**という AGENTS.md コーディングルール由来） | `/tmp/aigenba/database/factories/Billing/BillingCheckoutSessionFactory.php` |
| `app/Enums/Billing/SubscriptionState.php`（新規） | `Active` / `UpgradeRecovery` / `PastDue` / `Paused` / `Inactive` の 5 case + `fromSubscription()` + `grantsAccess()`。**`ScheduledForUpgrade` は非移植**（入力列 `subscriptions.pending_plan_code` が AI-CUE に無い = 原則 4）。`upgrade_recovery_required` 列も無いため当該分岐は落とし、`stripe_schedule_id !== null && schedule_setup_status === ScheduleSetupStatus::Created` の `UpgradeRecovery` 分岐のみ移植（両列は AI-CUE に実在。`2026_06_11_091200_create_subscriptions_table.php`）。評価順（paused → past_due → 非 active/trialing → recovery）は verbatim。`isTerminated` / `isTerminalStatus` / `TERMINATED_STRIPE_STATUSES` は P2 に呼び出し元が無い（AI-CUE の終了契機は `customer.subscription.deleted` のみ）ため移植しない | `/tmp/aigenba/app/Enums/Billing/SubscriptionState.php` |
| `app/Enums/Billing/EntitlementDeniedReason.php`（新規） | **verbatim 3 case**（`NoActiveSubscription` / `TrialEndedWithoutPaymentMethod` / `Paused`）+ docblock | `/tmp/aigenba/app/Enums/Billing/EntitlementDeniedReason.php` |
| `app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php`（新規） | **verbatim**（`entitled` / `state` / `reason` + `granted()` / `denied()` / `toArray()` + `@phpstan-type EntitlementShape`） | `/tmp/aigenba/app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php` |
| `database/migrations/2026_07_17_000210_add_has_payment_method_to_subscriptions.php`（新規） | `subscriptions.has_payment_method`(boolean NOT NULL **default false**, after `trial_ends_at`) を追加。**`deriveEntitlement` verbatim の入力**。同 aigenba migration の他 4 列（`trial_redeemed_at` / `applied_campaign_id` / `applied_trial_days` / `signup_initial_tickets_granted_at`）は campaign / trial / signup-funding 機構が無いため非移植（原則 4） | `/tmp/aigenba/database/migrations/2026_06_25_090100_add_signup_trial_columns_to_subscriptions.php` |
| `database/migrations/2026_07_17_000220_backfill_has_payment_method_on_subscriptions.php`（新規） | **列追加と分離した data migration**（P1 の `backfill_signup_tickets_granted_at` と同じ構造）。既存全 `subscriptions` 行を `has_payment_method = true` へ。`where('has_payment_method', false)` ガードで冪等、`down()` は意図的 no-op。**この backfill が cohort C を P2 デプロイ時点で空にする**（上記「backfill の効果」） | 構造は `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php` |
| `app/Models/Billing/Subscription.php` | `@property bool $has_payment_method` を docblock へ、`casts()` に `'has_payment_method' => 'boolean'` を追加。`$guarded = ['id','organization_id']` は不変 | `/tmp/aigenba/app/Models/Billing/Subscription.php` |
| `app/Services/Billing/SubscriptionSnapshot.php`（新規） | 値オブジェクト。`stripeId` / `status` / `basePriceId` / `baseQuantity` / `currentPeriodEnd` / `trialEndsAt` / `endsAt`。**`currentPeriodStart` は `subscriptions.current_period_start` 列が AI-CUE に無い**ため持たず、period 巻き戻し guard（`SubscriptionService.php:216-236`）も移植しない（列が無い = 移植対象が存在しない。原則 4）。`seatItemQuantity` も席概念が無いため持たない。schedule 状態を含めない契約（T666 C2）の docblock は移植 | `/tmp/aigenba/app/Services/Billing/SubscriptionSnapshot.php` |
| `app/Services/Billing/SubscriptionService.php`（新規） | サブスク層の中枢。`deriveEntitlement()`（**verbatim**）/ `applySubscriptionSnapshot()`（下記 adaptation）/ `recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentMethod)`（`recordFundingSnapshot` の PM 単独 subset。`DB::transaction` + `lockForUpdate()->find()` + 行不在の早期 return + monotonic ガード `if ($hasPaymentMethod && ! $fresh->has_payment_method)` は **verbatim**。trial_redeemed / campaign 節は列が無いため落とす）/ `assertStripeBillablePlan()`（**verbatim**）/ `assertPriceSynced()`（**verbatim**。`app()->environment('production')` 分岐込み）/ `startCheckout()` / `createPortalSession()` / `resolvePlanCodeFromPriceId()`（**verbatim**）。Stripe I/O は Gateway 経由のみ。**`getStatus()` / `BillingStatusDto` は呼び出し側 UI が P8b 所管のため P2 では作らない**（dead code を作らない）。schedule lifecycle / seat / signup funding / `changePlan` / `upgradeNow` / `isMutableState` は非スコープ | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:126-155,204-357,359-420` |
| `app/Services/Billing/BillingAccess.php`（改修） | `state()` を **verbatim 移植**（`subscription('default')` → `deriveEntitlement($sub)->entitled` なら `Subscribed` / `free_plan_code === PersonalPlanService::FREE_PLAN_CODE` なら `ActiveFreePlan` / `$sub instanceof Subscription` なら `ExpiredCheckout` / live pending なら `PendingCheckout` / stale pending・expired・failed があれば `ExpiredCheckout` / それ以外 `NoSubscription`。**read 経路で DB 書込をしない**契約・in-memory stale 判定も verbatim）。`hasActiveAccess()` は `state()->grantsAccess()` + **移行 OR 1 行**。`GRANTING_STATUSES` 定数を撤去。ctor は `SubscriptionService` 注入（verbatim）。閾値は `staleThresholdAt()`（下記）へ切り出す | `/tmp/aigenba/app/Services/Billing/BillingAccess.php` |
| 同上（stale 境界の単一出典） | 確定事項「stale 境界は排他」を機械化するため `public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable { return $now->subDay(); }` を `BillingAccess` に置く（値 `subDay()` は aigenba `BillingAccess.php:58` verbatim）。**live = `created_at >= staleThresholdAt($now)` / stale = `created_at < staleThresholdAt($now)`**。`state()` 内は verbatim の `$row->created_at->lessThan($threshold)` = stale がこの定義と一致する。P9 の sweeper は `where('created_at','<',BillingAccess::staleThresholdAt(now()))` で同一出典を読む（`ReconcileSubscriptionSchedules.php:70,76` の `<=` 形は schedule 用の別閾値で、checkout stale とは無関係のため触らない） | Round 13 Critical 2 / 確定事項 |
| `app/Services/Billing/Contracts/StripeGatewayInterface.php`（新規。`app/Services/Billing/SubscriptionCheckoutGateway.php` を置換・削除） | 命名と名前空間のみ aigenba 形へ。**メソッドは 3 本に限定**（`createSubscriptionCheckout` / `createPortalSession` / `syncCustomerDetails`）。戻り値は AI-CUE の `ExternalBillingRedirect` を維持。**aigenba の 30+ メソッド単一 interface へは寄せず、AI-CUE の狭い gateway + チケット系 Gateway 分割の境界と Fake の規約を維持**（AI-CUE の Gateway 規約） | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php`（命名のみ） |
| `app/Services/Billing/CashierStripeGateway.php`（`CashierSubscriptionCheckoutGateway.php` を rename） | 実装本体は現行のまま（`newSubscription('default',…)->checkout()` / `billingPortalUrl(…, PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')))`）。`portalRedirect` → `createPortalSession` へ改名、`syncCustomerDetails()`（`$org->syncStripeCustomerDetails()`）を追加 | `/tmp/aigenba/app/Services/Billing/CashierStripeGateway.php` |
| `app/Services/Billing/Fakes/FakeStripeGateway.php`（`Fakes/FakeSubscriptionCheckoutGateway.php` を rename） | interface 変更へ追随。`FakeExternalUrl::neutralReturn` の中立帰還 URL 契約は不変。`syncCustomerDetails()` は **no-op**（fake 環境が実 Stripe を叩かない規約の維持） | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php:204,211` |
| `app/Services/Billing/BillingCustomerSynchronizer.php`（新規） | **verbatim**（`stripe_id === null` は no-op / `SyncBillingCustomerDetails::dispatch($org)->afterCommit()` / 「必ず `DB::transaction` の内側から呼ぶ」契約 docblock 込み） | `/tmp/aigenba/app/Services/Billing/BillingCustomerSynchronizer.php` |
| `app/Jobs/Billing/SyncBillingCustomerDetails.php`（新規） | `handle(StripeGatewayInterface $gateway)` → `$gateway->syncCustomerDetails($org)`。Cashier 標準 job を使わない理由（billable を trait 型で受けるため PHPStan level 10 で不一致）を移植元コメントごと持ち込む | `/tmp/aigenba/app/Jobs/Billing/SyncBillingCustomerDetails.php` |
| `app/Actions/Organizations/RenameOrganizationAction.php`（新規）+ `app/Http/Controllers/Organizations/OrganizationController.php:98-108`（改修） | Controller の update 内部を Action に抽出し、`DB::transaction` 内で `isDirty('name')` のときだけ `BillingCustomerSynchronizer::dispatchFor()`。**配線は rename 経路のみ**（aigenba の `UpdateBillingContactAction` は請求先列・更新 UI が AI-CUE に無い = P9 / laratrust team rename 経路も無い） | `/tmp/aigenba/app/Actions/Organizations/RenameOrganizationAction.php` |
| `app/Services/Billing/BillingPermissionService.php`（新規） | `grant` / `revoke` / `hasDirectPermission` / `getDirectManageBillingMap` + `ensureTeamId`（`Assert::integer($org->laratrust_team_id)`）/ `ensureMembership`（`DomainException`）を移植。permission 名は AI-CUE 規約（kebab）で `public const PERMISSION_MANAGE_BILLING = 'manage-billing'`（AI-CUE に `App\Enums\BillingPermission` は無く、同型先例 `app/Services/ApiKey/ApiKeyPermissionService.php:29` が const 方式）。**`canEdit` / `canEditWithKnownRoles` は移植しない**（`App\Enums\OrganizationRole` に `level()` が無く、階層マトリクスは付与 UI 専用。**本フェーズは service + Policy の OR 参照のみ**） | `/tmp/aigenba/app/Services/Billing/BillingPermissionService.php` |
| `database/seeders/PermissionSeeder.php`（改修） | `permissions()` に `['name' => BillingPermissionService::PERMISSION_MANAGE_BILLING, 'display_name' => '請求・プラン管理']` を追加（`ApiKeyPermissionService::PERMISSION_MANAGE_API_KEYS` の隣。L43。flat 付与モデルのため `RolePermissionSeeder` には登録しない） | — |
| `app/Policies/OrganizationPolicy.php:37 manageBilling`（改修） | `manageApiKeys`（同ファイル L48-60）と同型に: role null → false / `canManage()` → true / それ以外は `BillingPermissionService::hasDirectPermission()` を **OR 参照**。付与 route / UI は P2 に含めない = 直接付与行 0 件 = 認可の結論は現行と同一 | `/tmp/aigenba/app/Services/Billing/BillingPermissionService.php` の Policy 参照形 |
| `app/Http/Controllers/Billing/BillingController.php`（改修） | Gateway 直注入をやめ `SubscriptionService` へ委譲（`checkout` → `startCheckout()` / `portal` → `createPortalSession()`）。**`index` の props は一切変えない**（`currentPlanCode` を維持 = `getStatus()`/`BillingStatusDto` は P8b）。`startCheckout()` が投げる `StripePriceNotSyncedException` を catch し **現行と同一文言**の `back()->with('error', '選択したプランは現在お申し込みいただけません。')` を返す | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php`（Service 委譲の層構成） |
| `app/Exceptions/Billing/StripePriceNotSyncedException.php`（新規） | **verbatim**（`userMessage()`）。Controller が flash に使う（500 にしない） | `/tmp/aigenba/app/Exceptions/Billing/StripePriceNotSyncedException.php` |
| `app/Services/Billing/StripeWebhookProcessor.php`（改修。L176-329） | `syncPlanCode` / `clearPlanCode` / `syncSubscriptionPeriod` の**書込ロジックを `SubscriptionService::applySubscriptionSnapshot()` へ移設**。Processor の責務は payload → `SubscriptionSnapshot` の写像 + 組織解決 + `subscriptionHasPaymentMethod($object)`（`default_payment_method` / `default_source` の有無。`StripeWebhookController.php:336-340` verbatim）→ `recordPaymentMethodSnapshot()` 呼び出しに縮む。**終了契機は現行どおり `customer.subscription.deleted` のみ**（`$terminated=true`）。**行の作成は Cashier `WebhookController` の責務のまま**（上記「行の materialize 順序」）。反映条件（active/trialing のみ plan_code 同期・未知 Price は受理のみ・invoice / ticket 系分岐）は不変。冪等マシン（`stripe_webhook_events` + `claim()`）は無改変（不変条件 #7） | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:204-357`, `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:240-340` |
| `app/Providers/AppServiceProvider.php:22-26,110` / `app/Providers/FakeExternalsServiceProvider.php:10-13,80`（改修） | bind を `Contracts\StripeGatewayInterface → CashierStripeGateway` / fake は `FakeStripeGateway` へ更新 | `/tmp/aigenba/app/Providers/AppServiceProvider.php:103` |

**`applySubscriptionSnapshot()` の adaptation（列の所在差の吸収。意味論は現行同値）**: aigenba は `subscriptions.plan_code` に書くが AI-CUE の権威は `organizations.plan_code`。単一 transaction 内で (a) `resolvePlanCodeFromPriceId($snap->basePriceId)` が解決でき **かつ** `status ∈ {active,trialing}` のときのみ `organizations.plan_code` を同期（未知 Price は受理のみ = 現行 `syncPlanCode` と同値）、(b) `subscriptions` 行が存在すれば `lockForUpdate()` の上で `stripe_status` / `stripe_price` / `quantity` / `trial_ends_at` / `ends_at` / `current_period_end` を更新（行不在なら period 更新のみ skip = 現行同値）、(c) `$terminated === true` のとき `organizations.plan_code = null`（現行 `clearPlanCode` と同値）+ `stripe_schedule_id = null` / `schedule_setup_status = ScheduleSetupStatus::None`（aigenba の終了時 schedule クリアのうち AI-CUE に実在する 2 列のみ）。seat drift / schedule out-of-band drift / period 巻き戻し guard は対象列（`additional_seats` / `pending_plan_code` / `current_period_start`）が無いため移植しない。

#### 波及変更

- **TypeScript 型定義**: **なし**。`resources/js/types/billing.ts` / `resources/js/types/dashboard.ts`（`BillingSummary.has_billing_access`）とも形状不変。`OnboardingBillingState` は Service / middleware 内部の判定にのみ使い props に載せない（aigenba と同じ）。
- **DTO / JsonResource**: 新規 = `SubscriptionEntitlementDto`（`@phpstan-type EntitlementShape`）/ `SubscriptionSnapshot`（値オブジェクト）。既存 `ExternalBillingRedirect` は Gateway 戻り値契約として据置。`BillingSummaryData` / `PurchaseTicketsPageDto`（`ticketAttemptToken` を含むチケット決済の冪等性契約）は**一切触らない**。JsonResource の新設なし。
- **Inertia props**: **なし**（`Billing/Index` の `currentPlanCode` / `Dashboard` とも不変）。
- **Factory / テストヘルパ**: `database/factories/Billing/BillingCheckoutSessionFactory.php`（新規）。`database/factories/OrganizationFactory.php` に `activatedPersonal(User $declarer)` / `grandfatheredFree()`（declarer-less）state を追加（P1 で未追加なら P2 で追加）。`tests/Pest.php:167 createFakeSubscription()` に `bool $hasPaymentMethod = true` / `?CarbonImmutable $trialEndsAt = null` 引数を追加（既存呼び出しは既定値で cohort A / B に落ち、結論不変）+ docblock L163-166 を新判定へ更新。
- **テストファイル（更新。削除しない）**: `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`（cohort C / D）/ `tests/Feature/DashboardTest.php:423-437`（cohort D の `has_billing_access`）/ `tests/Feature/Billing/BillingPageTest.php`・`tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（型名 rename）/ `tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php`・`tests/Feature/Billing/WebhookIdempotencyTest.php`・`tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`・`tests/Feature/Billing/SendBillingRemindersTest.php`・`tests/Feature/Database/BughuntBillingSeederTest.php`（**無改変で green**）/ `tests/Feature/Billing/PortalConfigurationTest.php`（期待不変 + 1 ケース追加）/ `tests/Architecture/MassAssignmentSafetyTest.php`（新モデル `BillingCheckoutSession` を検査対象へ追加）。

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
enum EntitlementDeniedReason: string {         // verbatim
    case NoActiveSubscription = 'no_active_subscription';
    case TrialEndedWithoutPaymentMethod = 'trial_ended_without_payment_method';
    case Paused = 'paused';
}

final class BillingAccess {
    public function __construct(private readonly SubscriptionService $subscriptions) {}
    public function hasActiveAccess(Organization $org): bool
    {
        if ($this->state($org)->grantsAccess()) {
            return true;
        }
        // 移行 OR（P4 で削除する 1 行）: 現行の意図的な free 許可 = plan_code null を通す。
        // P4 の grandfathering backfill（free_plan_code='personal'）が ActiveFreePlan を
        // 成立させ、本行を消すことがゲート反転そのものになる。
        return $org->plan_code === null;
    }
    public function state(Organization $org): OnboardingBillingState;   // verbatim。plan_code を見ない
    public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable; // = $now->subDay()
}

final class SubscriptionService {
    public function __construct(private readonly StripeGatewayInterface $gateway) {}
    public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto;   // verbatim（唯一の判定経路）
    public function applySubscriptionSnapshot(Organization $org, SubscriptionSnapshot $snap, bool $terminated = false): void;
    public function recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentMethod): void; // monotonic・行不在は no-op
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
| 1 | `$sub instanceof Subscription && deriveEntitlement($sub)->entitled` | `Subscribed` | 到達（cohort A / B / **D**） |
| 2 | `$org->free_plan_code === PersonalPlanService::FREE_PLAN_CODE` | `ActiveFreePlan` | **不到達**（writer は P3/P4） |
| 3 | `$sub instanceof Subscription` | `ExpiredCheckout` | 到達（cohort **C** / E / F / G） |
| 4 | live pending な `BillingCheckoutSession`（`created_at >= BillingAccess::staleThresholdAt(now())`） | `PendingCheckout` | **不到達**（writer は P9。行 0 件） |
| 5 | stale pending（`created_at < staleThresholdAt(now())`。in-memory 判定・DB は書かない）または expired / failed 行あり | `ExpiredCheckout` | **不到達**（同上） |
| 6 | それ以外 | `NoSubscription` | 到達（cohort H） |

**DB 列 / index**: `billing_checkout_sessions`（上記 create）/ `subscriptions.has_payment_method`(bool NOT NULL default false) + 既存行 true の backfill。`permissions` に `manage-billing` 行を seed。**ルート変更なし**（`/billing`・`/billing/checkout`・`/billing/portal`）。

#### PHPStan 適合チェック

- `Organization::subscription('default')` は Cashier 由来で `Subscription|null`（`AppServiceProvider.php:185` の `Cashier::useSubscriptionModel(App\Models\Billing\Subscription::class)` で差替済）。`state()` / webhook 経路とも **`$sub instanceof Subscription` で narrow** してから `deriveEntitlement()` に渡す（aigenba `BillingAccess.php:34` と同型）。`?->` で握り潰さない。
- `SubscriptionState::fromSubscription()` の `$sub->schedule_setup_status` は `ScheduleSetupStatus` へ enum cast 済み（`Subscription::casts()`）のため **instance 比較**（`=== ScheduleSetupStatus::Created`）。文字列比較にすると `alwaysFalse` になる。
- `has_payment_method` は `casts()` の `'boolean'` + `@property bool $has_payment_method` で `bool` を保証し、`! $sub->has_payment_method` が `mixed` にならないようにする（型 widen での回避・baseline 化はしない = 禁止事項 2）。
- `$sub->trial_ends_at` は Cashier 側 cast で `Carbon|null`。`deriveEntitlement` は verbatim どおり `!== null` で narrow → `CarbonImmutable::instance($sub->trial_ends_at)` に渡す。
- `BillingCheckoutSession::$created_at` は `Carbon|null`。stale 判定は `$row->created_at !== null && $row->created_at->lessThan($threshold)` で null を明示分岐（verbatim）。`get(['id','created_at'])` の戻りは `@var Collection<int, BillingCheckoutSession>` を docblock で明示。
- `SubscriptionEntitlementDto::toArray()` は `@phpstan-type EntitlementShape` + `@return EntitlementShape` で固定。`SubscriptionSnapshot` の日時は webhook payload の `data_get`（`mixed`）を既存 `stringAt()` + 新設 `epochAt(): ?CarbonImmutable` helper で `?CarbonImmutable` へ narrow してから ctor に渡す。
- `getDirectManageBillingMap()` は `@param list<int>` / `@return array<int, bool>`。`DB::table('permission_user')->pluck('user_id')` の `mixed` は `Assert::integerish()` 後に cast（`ApiKeyPermissionService::getDirectMap` と同一実装）。`ensureTeamId()` は `Assert::integer($org->laratrust_team_id)`（不変条件 #5: `laratrust_team_id` を常に明示）。
- config 読みは `config('cashier.portal_configuration_id')` / `config()->string('quota.fallback_plan')` の既存 typed accessor 経由を維持。`assertPriceSynced()` の `app()->environment('production')` 分岐も verbatim。

#### テスト計画

**先に red を作るテスト**

1. `tests/Unit/Billing/OnboardingBillingStateTest.php` — 5 case の `value` と `grantsAccess()` マトリクス（`Subscribed` / `ActiveFreePlan` のみ true）。enum 不在で red。
2. `tests/Feature/Billing/BillingAccessStateTest.php` — **分岐順 6 段を Factory から固定**:
   - cohort A（active / trialing・trial null）→ `Subscribed` + `hasActiveAccess()=true`
   - cohort B（active・`trial_ends_at` 過去・PM 有）→ `Subscribed` + true
   - **cohort C（active / trialing・`trial_ends_at` 過去・PM 無）→ `ExpiredCheckout` + false**（reason `TrialEndedWithoutPaymentMethod`。**P2 で結論が反転する側の固定**）
   - **cohort D（past_due・PM 有）→ `Subscribed` + true**
   - cohort E（past_due・trial 過去・PM 無）→ `ExpiredCheckout` + false
   - cohort F / G（paused / canceled / unpaid / incomplete / incomplete_expired）→ `ExpiredCheckout` + false（**`plan_code` 非 null / null の両方で同じ `state()`** = state が plan_code を見ないことの証明。`plan_code=null` は移行 OR で `hasActiveAccess()=true`）
   - cohort H（sub 行なし・checkout session なし）→ `NoSubscription`
   - `free_plan_code='personal'`（declarer 有無の両方）→ `ActiveFreePlan` + true
   - `BillingCheckoutSession`: `created_at = staleThresholdAt(now())` **ちょうど → live = `PendingCheckout`**（排他境界）/ `staleThresholdAt(now())->subSecond()` → `ExpiredCheckout` / expired・failed → `ExpiredCheckout`、かつ **`state()` 実行で DB 行が書き換わらない**（`updated_at` / `status` 不変 = read 経路 no-write 契約）
3. `tests/Feature/Billing/SubscriptionEntitlementTest.php` — `deriveEntitlement()` の `entitled` / `state` / `reason` マトリクス（status × `has_payment_method` × `trial_ends_at`）。`UpgradeRecovery`（`stripe_schedule_id` + `schedule_setup_status=Created`）が `entitled=true` = cohort A 同値であること。
4. `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` — webhook payload → `SubscriptionSnapshot` → `applySubscriptionSnapshot()` で `organizations.plan_code` / `subscriptions.current_period_end` が現行と同一に落ちる。`deleted`（`terminated=true`）で `plan_code=null` + schedule 2 列クリア。未知 Price は無変更。非 active/trialing status は plan_code 無変更。**`customer.subscription.created` では行が未作成のため `recordPaymentMethodSnapshot()` が no-op になり、直後の Cashier ハンドラが `subscriptions` + `subscription_items` を作る**こと（listener が行を先取りしない = items が生成される回帰防止）。**最初の `customer.subscription.updated` で `has_payment_method=true` が確定**すること。monotonic（true → false に戻らない）。
5. `tests/Feature/Billing/HasPaymentMethodBackfillMigrationTest.php` — **cohort C の移行安全性**: 列追加前に作った subscription 行（`trial_ends_at` 過去を含む）が backfill 後に `has_payment_method=true` になり、`hasActiveAccess()` が **true のまま**であること。backfill の冪等（2 回流して差分なし）。
6. `tests/Architecture/BillingEntitlementSingleSourceTest.php` — (a) `app/` 配下で `SubscriptionState::grantsAccess()` を直接参照するのは `SubscriptionService::deriveEntitlement()` のみ、(b) `subscription('default')` の直参照は `BillingAccess` / `SubscriptionService` / `StripeWebhookProcessor` のみ、(c) `organizations.plan_code` / `free_plan_code` を読むのは allowlist（`BillingAccess` の移行 OR / `StripeWebhookProcessor` / `QuotaService` / `Organization` model / `PersonalPlanService` / Filament 表示）のみ。
7. `tests/Architecture/BillingSyncDispatchInvariantTest.php` — `SyncBillingCustomerDetails::dispatch` の呼び出し元は `BillingCustomerSynchronizer` のみ（aigenba IV-2）。

**既存テストの更新（削除しない）**

- `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`:
  - 「有償契約 + 支払い不健全は billing へ redirect + 理由 flash」の dataset から `past_due` を外し `['canceled','incomplete','unpaid','paused']` へ（cohort D）。
  - 「有償契約 + 支払い不健全の JSON は 402」/「billing ページは遮断対象の組織でも到達できる」の status を `past_due` → `canceled` へ（402 文言の固定は不変）。
  - 「BillingAccess: plan_code null は常に許可、非 null は active/trialing のみ許可」を **cohort 表 A–I の dataset へ置換**し、テスト名を「plan_code null は移行 OR で許可（P4 で削除）/ 非 null は `deriveEntitlement` 判定」へ。`'past_due' => true`（cohort D）。
  - **追加ケース**: cohort C（`active` + `trial_ends_at` 過去 + PM 無）→ 遮断 / cohort E（`past_due` + 同条件）→ 遮断。
- `tests/Feature/DashboardTest.php:423`: cohort D の `has_billing_access` 期待を **false → true** に更新し、CTA 遷移先 200（redirect loop なし）の不変条件は `canceled` シナリオを追加して保持。
- `tests/Feature/Billing/BillingPageTest.php` / `tests/Feature/Providers/FakeExternalsServiceProviderTest.php:35`: `SubscriptionCheckoutGateway` / `FakeSubscriptionCheckoutGateway` の参照を `Contracts\StripeGatewayInterface` / `FakeStripeGateway` へ。**props の期待（`currentPlanCode`）と中立帰還 URL の期待は不変**。
- `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` / `WebhookIdempotencyTest.php` / `WebhookEventSubscriptionInvariantTest.php`: **無改変で green**（cohort I と冪等マシンが無変更であることの証明）。
- `tests/Feature/Billing/PortalConfigurationTest.php`: 期待不変 + 「Service 委譲後も `PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id'))` が Gateway に渡る」を 1 ケース追加。

**新規（機能追加分）**

- `tests/Feature/Billing/BillingPermissionServiceTest.php`: grant/revoke → `hasDirectPermission` の反映 / 非メンバーは `DomainException`（grant）・false（has）/ `getDirectManageBillingMap` が 1 クエリ（N+1 なし）。**Policy 回帰**: 直接付与ゼロなら `manageBilling` の結論は現行（owner/admin のみ）と同一 / 直接付与された member は `/billing/checkout` が 403 にならない / 非メンバーは付与行が残存しても false。
- `tests/Feature/Organizations/OrganizationRenameStripeSyncTest.php`: `Queue::fake()` で (a) name 変更時のみ `SyncBillingCustomerDetails` が dispatch、(b) 同名 save では dispatch なし、(c) `stripe_id === null` は no-op、(d) transaction rollback 時に発火しない（`afterCommit`）。
- `tests/Unit/Billing/FakeStripeGatewayTest.php`: `syncCustomerDetails()` が no-op（実 Stripe を叩かない）+ checkout / portal の中立帰還 URL 契約（既存 `FakeTicketCheckoutGatewayTest` と同型）。
- `tests/Feature/Billing/BillingCheckoutSessionModelTest.php`: `statusEnum()` / `intentEnum()` / `isReplayablePending()` と unique 制約（`stripe_session_id` / `idempotency_key` / `(organization_id,intent,attempt_token)`。NULL token は重複許容）。

#### リスク

| リスク | 緩和 |
|---|---|
| **cohort C（trial 終了 + PM 無し）が P2 で遮断へ反転する**（Round 13 Critical） | DoD から「挙動不変」を撤回し、cohort 表・テスト（`BillingAccessStateTest` / `RequireActiveSubscriptionMiddlewareTest` / `SubscriptionEntitlementTest`）に明示固定。**既存行は backfill で `has_payment_method=true` = デプロイ時点の該当 org は 0 件**（`HasPaymentMethodBackfillMigrationTest` が固定）。P2 以降の新規行が該当し得るのは Stripe 側 trial 設定時のみ（AI-CUE に trial 発行コードなし）で、その遮断は aigenba の「webhook の paused 化前でも先回り遮断」（`SubscriptionService.php:138`）そのもの = 原則 1・5 により先回り修正しない |
| **cohort D（past_due 許可）で未収金 org が利用継続する** | 原則 1・3 による意図的 parity（aigenba の dunning 継続方針）。**PM 無し past_due は cohort E として遮断**され、`invoice.payment_failed` 通知（既存 `BillingNotificationDispatcher`）は不変。aigenba 側で方針が変われば取り込む |
| **`has_payment_method` の初回書込が `created` イベントに載らない**（Cashier が `WebhookReceived` を行作成前に発火） | 契約として明文化 + `SubscriptionSnapshotSyncTest` で「created は no-op / 最初の updated で true 確定 / `subscription_items` が生成される」を固定。**aigenba の `Subscription::create($attrs)` を移植すると Cashier の `contains` ガードで items 生成が skip される**ため移植しない（原則 4） |
| **移行 OR（`plan_code === null`）の消し忘れで P4 のゲート反転が効かない** | `hasActiveAccess()` の docblock に「P4 で削除」と削除条件（grandfathering backfill 完了）を明記し、`BillingAccessStateTest` に cohort I（`NoSubscription` + `plan_code=null` → **P2 は true**）を明示ケースとして置く。P4 はこの 1 行削除 + 期待反転の diff だけで済むことをテスト差分で確認する |
| **`state()` が `plan_code` を見ないことの回帰**（将来の再発明） | `BillingEntitlementSingleSourceTest` で `plan_code` 読み出し allowlist を構造的に固定（`BillingAccess` は移行 OR の 1 箇所のみ許可し、P4 で allowlist からも外す） |
| **stale 境界の重複で live 行が expire される**（Round 13 Critical 2） | 閾値を `BillingAccess::staleThresholdAt()` に単一出典化し、**live = `>=` / stale = `<`** の排他で統一。境界ちょうど（`created_at == staleThresholdAt(now())`）が `PendingCheckout` になることを `BillingAccessStateTest` で固定。P9 の sweeper は同 helper を `<` で読む |
| **`state()` の checkout session クエリが gate 経路（多数の GET）で毎回走る** | verbatim どおり sub / free_plan_code を持つ org は分岐 1・2 で早期 return し、クエリ到達は sub 行なしの org のみ。**P2 時点では `billing_checkout_sessions` は writer 不在で 0 件**（**最初の writer は P8a**（`intent=setup_payment_method`）、**`subscription_start` 行の writer は P9**）。read 経路で **DB 書込をしない**契約もテストで固定（stale expire は sweeper の責務 = **P9 所管の `expireStaleCheckouts()`**） |
| `StripeWebhookProcessor` からの書込移設で webhook の順序逆転耐性・冪等が退行 | 既存 `WebhookEventSubscriptionInvariantTest` / `WebhookIdempotencyTest` を**無改変で維持**（不変条件 #7）。反映条件（active/trialing のみ・未知 Price は受理のみ・行不在時は period 更新 skip・終了契機は deleted のみ）をそのまま持ち込み、`SubscriptionSnapshotSyncTest` で列単位に固定 |
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

`現行` = P2 前（`plan_code IS NULL` → 許可 / 非 null → `stripe_status ∈ {active,trialing}`）、
`P4 直前` = **P2 適用済み**（`state()->grantsAccess() || plan_code === null`）、
`P4 後` = `state()->grantsAccess()`。`sub` = `subscription('default')`（= **最新の `type='default'` 行**）。

| # | entitlement snapshot | 現行（P2 前） | **P4 直前（= P2 後）** | state()（backfill 前） | **P4 後** | 処置 / 変更の帰属 |
|---|---|---|---|---|---|---|
| 1 | `plan_code` 非 null + `entitled`（`active`/`trialing`） | 許可 | 許可 | `Subscribed` | 許可 | **何もしない**。P4 変更なし |
| 2 | `plan_code` 非 null + `active`/`trialing` + **trial 終了 + PM 無** | **許可** | **遮断** | `ExpiredCheckout` | 遮断 | **何もしない**。**結論変更は P2 の所管**（`deriveEntitlement` 導入時点。Round 13 Critical #1）。**P4 変更なし**。本番該当は 0 件見込み（trial 発行経路が `app/` に無い + P2 が `has_payment_method=true` を backfill 済み） |
| 3 | `plan_code` 非 null + **`past_due` + PM 有** | **遮断** | **許可** | `Subscribed` | 許可 | **何もしない**。**結論変更（緩和）は P2 の所管**（dunning 中も利用継続 = aigenba verbatim）。**P4 変更なし** |
| 4 | `plan_code` 非 null + `past_due` + trial 終了 + PM 無 | 遮断 | 遮断 | `ExpiredCheckout` | 遮断 | **何もしない**。P4 変更なし |
| 5 | `plan_code` 非 null + `paused` / `canceled` / `unpaid` / `incomplete` / `incomplete_expired` | 遮断 | 遮断 | `ExpiredCheckout` | 遮断 | **何もしない**（今日遮断中の org に free entitlement を与えない）。P4 変更なし |
| 6 | `plan_code` 非 null + sub 行なし（webhook 順序逆転の壊れ状態） | 遮断 | 遮断 | `NoSubscription` | 遮断 | **何もしない**（fail-closed 維持）。P4 変更なし |
| 7 | `plan_code` null + `free_plan_code` null + sub 行なし + checkout session 行なし | 許可（OR） | 許可（OR） | `NoSubscription` | **遮断** | **grandfather** → `ActiveFreePlan` で許可を保存 |
| 8 | `plan_code` null + `free_plan_code` null + sub 行なし + live pending checkout session | 許可（OR） | 許可（OR） | `PendingCheckout` | **遮断** | **grandfather** |
| 9 | `plan_code` null + `free_plan_code` null + sub 行なし + stale pending / expired / failed session のみ | 許可（OR） | 許可（OR） | `ExpiredCheckout` | **遮断** | **grandfather** |
| 10 | `plan_code` null + `free_plan_code` null + sub あり + **`¬entitled`**（`canceled`/`unpaid`/`incomplete`/`paused` = paid→free 経路 / **`active`・`trialing`・`past_due` + trial 終了 + PM 無**） | 許可（OR） | 許可（OR） | `ExpiredCheckout` | **遮断** | **grandfather**（`state()` の契約: free entitlement は「行の不在」ではなく「entitlement の不在」で判定するため、過去行が残っていてよい） |
| 11 | `plan_code` null + sub あり + **`entitled`**（`active`/`trialing` / **`past_due` + PM 有**。webhook 同期ラグ / price → plan 解決不能） | 許可（OR） | 許可（`Subscribed`。OR にも該当） | `Subscribed` | 許可 | **何もしない**（entitled subscription と free entitlement を併存させない invariant）。P4 変更なし |
| 12 | `free_plan_code='personal'`（declarer 有無を問わず。P3〜P4 間に自発 activate した org / migration 再実行） | 許可 | 許可 | `ActiveFreePlan` | 許可 | **何もしない**（`whereNull('free_plan_code')` ガード = 冪等）。P4 変更なし |
| 13 | signup grant 履歴（`ticket_ledger_entries.idempotency_key LIKE 'signup_grant:%'`）/ `signup_tickets_granted_at` の有無 | — | — | — | — | **分類に影響しない**（backfill は grant を発火せず marker にも触れないため、未付与 org は将来の `activate()` / paid 成立時に 1 回だけ付与される） |

- **grandfather 行 = 7・8・9・10 = `{ plan_code IS NULL ∧ free_plan_code IS NULL ∧ ¬entitled }`**。母集団ガード（分類 1-6 を `plan_code IS NULL` で、分類 12 を `free_plan_code IS NULL` で除外）と PHP の `¬entitled` 判定（分類 11 を除外）が、**上の集合定義と 1 対 1 で対応する**。
- **帰結（P4 の中心的主張。訂正後）**:
  1. **`plan_code` 非 null の org（分類 1-6）は P4 で 1 件も結論が変わらない**（移行 OR が適用されないため）。分類 2・3 の変更は **P2 の所管**であり P4 の成果ではない。
  2. **`plan_code IS NULL` の org（分類 7-12）は P4 で 1 件もアクセスを失わない**（7-10 は backfill が `ActiveFreePlan` にし、11-12 は元から `grantsAccess()=true`）。
  3. ⇒ **P4 デプロイ時点で結論が変わる既存 org は 0 件**。**P4 の正味の変更は「backfill 後に新規発生する未契約 org が遮断されるようになること」**に閉じる。
- **D22: 上記同値を migration テストで双方向に機械検証する**（下記テスト計画）。

**middleware（gate 分岐は aigenba verbatim / org 解決は AI-CUE の current-org 規約を維持）**

```php
private const string NO_PLAN_MESSAGE = 'ご利用にはプランの選択が必要です。';
private const string BLOCKED_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。'; // 現行文言のまま（D15）

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

    $request->session()->reflash();                      // 直前 hop の flash 延命（招待受諾等）。aigenba L89 と同じ

    return redirect()->route(
        Gate::forUser($user)->allows('manageBilling', $organization)
            ? 'onboarding.checkout'                      // route parameter なしの current-org スコープ
            : 'onboarding.billing-required'
    );
}
```

- 認可 ability は既存の `manageBilling`（`app/Policies/OrganizationPolicy.php:37`。P2 で `BillingPermissionService` の直接付与を OR 参照済み）を使う（ability を増やさない）。aigenba の `'manage-billing'` からの改名は P3 の adaptation と同一。
- `onboarding.{checkout,activate-personal,billing-required}` は **gate group 外**（`routes/web.php:349` の `require-active-subscription` group に入れない = 構造的 allowlist）。入れると遮断 → 遮断の無限ループ = 詰みになる。
- 402 文言は state で 2 分岐。**D15 が守る既存契約（有償契約 + 支払い不健全 = 分類 2・4・5 = `ExpiredCheckout`）は `BLOCKED_MESSAGE` のまま不変**。`NO_PLAN_MESSAGE` は「未契約」という**新しい遮断事由（`NoSubscription` / `PendingCheckout`）にのみ**追加される。
- **DB 列 / index の追加は無い**（すべて P1・P2）。P4 は既存列への UPDATE と `plans` の 1 行削除のみ。partial unique index `organizations_personal_free_declarer_unique` は `WHERE free_plan_code='personal' AND personal_declared_by_user_id IS NOT NULL` のため、**declarer NULL の backfill 行は対象外 = 衝突しない**。
- backfill migration は `'personal'` リテラルを直書きする（migration をアプリ定数に依存させない流儀）。ドリフトは invariant テストで固定する。**entitlement 判定だけは例外的に `SubscriptionService::deriveEntitlement()` を呼ぶ** — 述語を写した瞬間に P2 契約とのドリフトが復活する（= Round 13 Critical の再発）ため、**P2 と同一定義を共有することが移行の正しさの条件**だから。

**free 撤去（D11）の実変更一式**

| 対象 | 変更 |
|---|---|
| `plans` テーブル | `remove_free_plan_row` migration が事前検証（`organizations.plan_code='free'` の参照行 / `free` の `plan_prices` が残存すれば throw = fail-closed）→ 削除 → 残余 0 件検証 |
| `PlanSeeder` | `free` 行の投入（L42-50）を削除（後継 = `personal`） |
| `config/quota.php` | `fallback_plan: 'free' → 'personal'` / `plans` から `'free'` キーを削除（`personal` limits は P1 で投入済み・旧 free と同値 = 実効 limits 不変） |
| `/pricing`・`/billing` の一覧 | P1 の `plans.is_active` フィルタ下で `personal` / `starter` / `standard` が公開（`is_active=true`）。free 行の消滅で料金表の先頭は `personal` になる |
| rollback | 運用手順（下記）。**migration の `down()` は config を戻さない**（リポジトリ内 config を migration が書き換えられない） |

**デプロイ順序（DoD）**

1. 列 + partial unique index + `has_payment_method` backfill は **P1 / P2 で適用済み**。
2. `php artisan migrate` が `backfill_grandfathered_free_plan_code` → `remove_free_plan_row` の順に完了し、それぞれ末尾の**残余 0 件検証**を通る。
3. その後にゲートコード（`BillingAccess` の移行 OR 撤去 + middleware）のリリースが活性化する。

migration が throw した場合はデプロイが中断し、**旧リリース（ゲート未反転）が生き続ける** — これが「backfill 失敗ならゲートを反転しない」の実現機構。**手順 2 が完了した時点で分類 7-10 の集合は空**になるため、手順 3 の活性化で結論が変わる既存 org は 0 件になる。

**rollback 手順（運用手順として分離）**

1. **コード / config を revert**（`hasActiveAccess()` の移行 OR 復帰 / middleware / `config/quota.php` の `fallback_plan='free'` + `plans.free` キー復活）→ 締め出しが即座に解消する。
2. 必要なら **`remove_free_plan_row` の `down()`** を実行し `plans` の `free` 行を復元する（1 と 2 の間は `fallback_plan='free'` が config の limits だけを引く = 実害なし）。
3. **grandfather backfill は revert しない**（`down()` は no-op）。旧コードの移行 OR は `plan_code` のみを見るため、`free_plan_code` が入った行は無害に無視される。

#### PHPStan 適合チェック

- `BillingAccess::hasActiveAccess(): bool` は `state()`（`OnboardingBillingState`）→ `grantsAccess(): bool` をそのまま返す。行削除のみのため新たな型注釈は不要。`GRANTING_STATUSES`（`private const array`）は P2 で撤去済み。抽象型の widen・baseline は使わない（禁止事項 2）。
- `RequireActiveSubscription::handle()`: `$request->route('organization')` は `mixed` → 既存 `resolveOrganization(): ?Organization` の `instanceof` narrowing を維持。`$user` の `instanceof User` narrowing も維持。`@param Closure(Request): Response $next` docblock を維持し全経路が `Response` を返すことを型で保証（`redirect()->route()` は `RedirectResponse` ⊂ `Response`、`abort()` は `never`）。
- 402 文言の分岐は `$state === OnboardingBillingState::ExpiredCheckout ? … : …`。**`grantsAccess()` の早期 return 後**に置くため enum case 比較が `alwaysFalse` / `alwaysTrue` にならない（`match` を使わないことで網羅性 error も出さない）。
- `Gate::forUser($user)->allows('manageBilling', $organization)` は `bool`、`route(string): string`。
- backfill migration: `Organization::query()->…->with('subscriptions')->chunkById(500, Closure)` の callback は `@param Collection<int, Organization> $orgs` を明示。`$org->subscription('default')` は `?Subscription`（Cashier `ManagesSubscriptions::subscription()` の宣言。`AppServiceProvider.php:185` の `Cashier::useSubscriptionModel()` で `App\Models\Billing\Subscription` に差替済み）→ `instanceof Subscription` narrowing 後に `deriveEntitlement()` へ渡す。`$targets` は `@var list<int>` を宣言。UPDATE は `DB::table()->whereIn()->update(array): int`、残余は `->count(): int`、違反時は `RuntimeException` を直接 throw。
- `remove_free_plan_row` migration は `DB::table()` クエリビルダのみ（Eloquent モデル・アプリ定数に依存しない）。
- `config()->string('quota.fallback_plan')` の typed accessor を維持（`QuotaService` のコードは無改変）。
- `tests/Pest.php` の `createOrganizationWithOwner(): array{Organization, User}` は戻り値型不変。追加引数は `bool` 既定値付き。

#### テスト計画

**先に red で書く（F-07 回帰。新規 `/workspace/tests/Feature/Billing/GateInversionF07RegressionTest.php`）**

- **(a) 既存 `plan_code IS NULL` 組織が移行後も業務ルートに到達する**: `createOrganizationWithOwner()`（既定 = backfill 相当の declarer-less grandfathered）で org を作り、`/projects` が `assertOk()` + `assertInertia(component 'Projects/Index')`、`POST /projects` でプロジェクト作成に到達、`/app` に到達。**declarer NULL でも `ActiveFreePlan` で通る**ことを固定。
- **(b) 新規登録者が遮断されても詰まない**（= P4 の正味の変更点）: `createOrganizationWithOwner(grandfatherFreePlan: false)` の owner → `/projects` が `onboarding.checkout` へ redirect、着地 200 → `POST onboarding.activate-personal` → 再度 `/projects` が `assertOk()`（= 導線が閉じている）。`manageBilling` 非保持 member は `onboarding.billing-required` へ redirect し着地 200。
- **(c) 遮断時に理由が画面に出る**（H1「説明なしリダイレクト」の再発検知）: 遮断 redirect を follow した着地が **`billing.index` でないこと**を明示 assert し、Inertia component が `Onboarding/Checkout` / `Onboarding/BillingRequired` であること、理由提示の素材が props に載っていること（Checkout: `pageData.plans` 非空 + `pageData.personalEligibility` 非 null / BillingRequired: `pageData.ownerEmail`・`pageData.contactUrl`）。JSON は 402 + `message` が state 別の確定文言と一致。
- **(d) 無限ループ不在**: `onboarding.*` / `billing.*` / `purchase-tickets` / `notifications` が gate group 外である構造的 allowlist の検証（遮断 redirect 先を再度叩いて 302 が返らないこと）。
- **(e) P4 の変更が `plan_code IS NULL` に閉じている**（Round 13 Critical #1 の回帰）: 分類 1-6 の fixture について、**移行 OR の有無で `hasActiveAccess()` の結論が一致する**ことを assert（`plan_code` 非 null 側は P4 で 1 ビットも動かない = 分類 2・3 が P4 の帰属でないことの機械的証明）。

**backfill migration テスト（新規 `/workspace/tests/Feature/Billing/GrandfatherFreePlanBackfillTest.php`）**

- **D22（必須 DoD）**: 分類表 13 行を Factory で組み、各 fixture に「分類 #」と `expectGrandfather: bool` を**手で宣言**した dataset を持たせる（= 期待値の出所は分類表であって実装ではない = 検証がトートロジーにならない）。**expected 集合** = `expectGrandfather=true` の org ID 集合、**actual 集合** = migration `up()` 実行前後の差分（`free_plan_code='personal'` かつ declarer NULL になった org ID）。`expected \ actual === []` **かつ** `actual \ expected === []` の**双方向完全一致**をアサート（片側包含では締め出しも誤救済も検出できない）。
- **entitlement 境界の網羅**（Round 13 Critical #1 の回帰）: 分類 10 / 11 を `deriveEntitlement` の合成軸で個別 fixture 化する — **`past_due` + PM 有 → 救われない**（分類 11。`Subscribed` と free の併存を作らない）/ **`past_due` + PM 無 + trial 終了 → 救われる**（分類 10）/ **`active` + trial 終了 + PM 無 → 救われる**（分類 10。`stripe_status` だけを見る述語なら漏れる行）/ `trialing` + trial 未終了 + PM 無 → **救われない**（分類 11）/ `paused` → 救われる（分類 10）。
- **`subscription('default')` の最新行セマンティクス**: 同一 org に `type='default'` の行を 2 本（古い `active` + 新しい `canceled`、および逆順）作り、**Cashier が返す最新行の entitlement のみで分類される**ことを固定（`EXISTS` 述語なら誤る 2 ケース）。
- 分類 2・3・4・5・6 が**救われない**（`plan_code` 非 null の org に free entitlement を与えない）。
- **P4 デプロイ時点で結論が変わる既存 org が 0 件**（本フェーズの中心的主張）: 分類 1-12 の全 fixture について、**backfill 適用 + 移行 OR 撤去の後の `hasActiveAccess()` が、P4 直前（P2 適用済み・移行 OR 有り）の結論と全件一致**すること。
- **grant が 1 枚も発火しない**（`ticket_ledger_entries` 件数不変 + `signup_tickets_granted_at` 不変）。
- 2 回実行して結果不変（冪等。`whereNull('free_plan_code')` ガード）。
- declarer-less 行が partial unique index に衝突せず、**同一 user が複数 org を持っていても全件救われる**。backfill 後に当該 owner が別 org で `activate()` しても index 違反にならない。
- 残余が 0 でないときに `RuntimeException`（= デプロイ中断 = ゲート非活性）。

**free 撤去テスト（新規 `/workspace/tests/Feature/Billing/RemoveFreePlanRowMigrationTest.php`）**

- `organizations.plan_code='free'` の参照行が残る状態 / `free` の `plan_prices` が残る状態で **fail-closed（throw）**し、`plans` の `free` 行が消えないこと。
- 参照ゼロなら削除され、`plans.code='free'` の残余が 0 件。
- `down()` で `free` 行が復元される（config は無改変であることを assert）。

**arch / invariant テスト（新規 `/workspace/tests/Architecture/BackfillPlanCodeLiteralInvariantTest.php`）**

- backfill migration が直書きする `'personal'` リテラルが `PersonalPlanService::FREE_PLAN_CODE` と一致（ドリフト検知）。
- backfill migration の**ソースに `stripe_status` / `'active'` / `'trialing'` / `'past_due'` / `has_payment_method` が現れないこと** = entitlement 述語の写しを持たず `deriveEntitlement()` に委譲していることの機械的固定（Round 13 Critical #1 の恒久防止）。
- `config('quota.fallback_plan')` が `PersonalPlanService::FREE_PLAN_CODE` と一致し、対応する limits が `config('quota.plans')` に存在すること（`QuotaService.php:33` の `?? []` による「未知キー = 無制限」silent 退行の防止）。
- `app/` 内で `plan_code` を entitlement 判定に使う参照が存在しないこと（`BillingAccess` / `RequireActiveSubscription` に `plan_code` が現れない = verbatim の固定。P2 の `BillingEntitlementSingleSourceTest` の allowlist から `BillingAccess` の移行 OR を外す）。

**既存テストの更新（削除しない）**

- `/workspace/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`（**削除しない。`plan_code IS NULL` 系のみ更新**）
  - 冒頭 docblock（L19）の gate 方針を反転後へ。
  - 「Free（未契約）組織は業務 route に到達できる（F-07 再現）」3 本（L30 / L37 / L45）→ 「**未契約組織（`free_plan_code` NULL）は `onboarding.checkout` へ遮断される**」+「**grandfathered / activated な free 組織（`ActiveFreePlan`）は到達できる**」へ期待更新。
  - 「有償契約 + 支払い不健全は billing へ redirect + 理由 flash」(L62) → **遮断先を `onboarding.checkout` へ（flash なし）**。**dataset（P2 で `['canceled','incomplete','unpaid','paused']` へ更新済み）は P4 では変更しない**（`past_due` の移動は P2 の所管）。
  - 「有償契約 + subscription 行なしは fail-closed」(L71) → 遮断先を `onboarding.checkout` へ更新（結論は不変）。
  - 「有償契約 + 支払い不健全の JSON は 402 + message 固定」(L79) は**文言も含めて維持**（D15）。加えて「**未契約の JSON は 402 + `NO_PLAN_MESSAGE`**」を 1 本追加。
  - `BillingAccess` 単体マトリクス (L109-131) のうち **`plan_code` 非 null 行（L122 の status マトリクス。P2 で `deriveEntitlement` ベースへ更新済み）は 1 つも変更しない**。変更するのは `plan_code` null 行のみ: `plan_code` null + sub なし + `free_plan_code` null → **`NoSubscription` = false**（L114 の `true` から反転）/ `free_plan_code='personal'`（declarer 有無を問わず）→ **`ActiveFreePlan` = true** を追加 / `plan_code` null + entitled sub（L119 の同期ラグ org）→ **`Subscribed` = true**（**期待値そのものは不変**。分類 11）。
  - 「free プランは Stripe Price を持たない」(L99) → 対象を `config('quota.fallback_plan')`（= `personal`）へ読み替えて**維持**。
  - 「billing ページは遮断対象でも到達できる」(L90) / 「route bound organization が…redirect」(L139) / 「非メンバーが binder を通過しても 404」(L154) は**維持**（遮断先の期待のみ更新）。
- `/workspace/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`（**削除しない。期待更新**）: 「seeder の Free 組織が**素通り**する」→「seeder が `PersonalPlanService::activate()` 済みのため全ロール（owner/admin/member）が `/projects` に到達する」へ。`seededFreePlan()`（L21-26。current base Price を持たない Plan）の解決先は free 消滅により `personal` になる。`expect($organization->plan_code)->toBeNull()`（L33）は**維持**し、`free_plan_code === 'personal'` と declarer 非 null を追加（F-C3 の不変条件を残したまま反転後の事実を固定）。L49 の有償組織テストは**無改変**。
- `/workspace/tests/Feature/Billing/BillingAccessStateTest.php`（P2 成果物）: 移行 OR 撤去に伴い `plan_code` null + `free_plan_code` null の `hasActiveAccess()` 期待を `true` → `false` へ。**`state()` 自体の期待は 1 つも変えない**（= 反転が 1 点に閉じている証明）。`tests/Unit/Billing/OnboardingBillingStateTest.php`（enum マトリクス）は**無改変**。
- `/workspace/tests/Feature/Billing/QuotaTest.php:15`: 「`plan_code` 未設定の組織には `fallback_plan`（free）の既定 limits が効く」→ `personal` 表記へ。**limits の期待値（`max_projects=1` / `max_members=3` / `max_storage_bytes=1GiB`）は 1 つも変えない**（実効 limits 不変の証明）。
- `/workspace/tests/Feature/Billing/BillingPageTest.php:26` / `/workspace/tests/Feature/Marketing/PricingPageTest.php:35` / `/workspace/tests/js/pages/Pricing.test.ts` / `/workspace/tests/Feature/Billing/PlanSeederPriceInvariantTest.php:23` / `/workspace/tests/Feature/Database/BughuntBillingSeederTest.php:68` / `/workspace/tests/Feature/Database/ManualTestSeederTest.php:30`: 「波及変更」のとおり `free` 参照を実在プランへ更新。
- `/workspace/tests/Pest.php`: helper 既定の変更（docblock 含む）。

**新規（UI 文言の固定）**

- `/workspace/tests/js/pages/OnboardingBillingRequired.test.ts`: `Onboarding/BillingRequired` が「なぜ操作を続けられないか」の説明コピーと owner 連絡導線をレンダすること（props 追加はしない。**ボタンの disabled 化はしない** = 禁止事項 #8。T071 primitive / DS token / `@lucide/svelte` 準拠）。

**共通**: テストデータは Factory 生成（手組み禁止）/ `RefreshDatabase` グローバル + `--parallel` 前提を維持（個別 `DatabaseTransactions` を追加しない）。

#### リスク

| リスク | 緩和 |
|---|---|
| **P4 の変更範囲を誤認して分類 2・3 を P4 の成果として扱う**（Round 13 Critical #1 の再発） | 移行 OR は `plan_code IS NULL` の org にしか適用されない = **P4 の結論変更は構造的に `plan_code IS NULL` へ閉じる**。分類表に「現行（P2 前）」「P4 直前（= P2 後）」の 2 列を持たせて帰属を明示し、`GateInversionF07RegressionTest` (e) が「分類 1-6 は OR の有無で結論不変」を機械的に固定する。P4 の DoD は分類 2・3 を主張しない。 |
| **backfill 集合が entitlement と不一致 → 誤救済 / 締め出し** | 述語の写しを持たず、**P2 の `SubscriptionService::deriveEntitlement()` を PHP で呼んで対象 ID を確定**する（`state()` の 1-2 行目と同一式）。分類表も同定義に整列（`past_due` + PM 有 = 分類 11 = 救わない / `active`・`trialing`・`past_due` + trial 終了 + PM 無 = 分類 10 = 救う）。**D22 の双方向 ID 集合一致**を分類表由来の手宣言 dataset で機械検証し、arch テストが migration ソースへの `stripe_status` 等の再出現を禁止して恒久防止する。 |
| **`EXISTS` 述語と `subscription('default')` の乖離**（`type='default'` 複数行の org） | Cashier は `orderBy('created_at','desc')` の**先頭 1 行のみ**を返す（`ManagesSubscriptions.php:155,165`）。migration は同じ accessor を使い、テストが 2 行構成（新旧の順序両方）で固定する。 |
| **既存ユーザー締め出し（F-07 再発）** = backfill 漏れ or デプロイ順序逆転 | 集合定義が「P4 直前に許可 ∧ OR 削除後に遮断」と定義上一致するため、**`plan_code IS NULL` の org は 1 件もアクセスを失わない**（分類 7-12 が全て許可）。migration 末尾の残余 0 件検証が throw → デプロイ中断（ゲート非活性）。`GrandfatherFreePlanBackfillTest` が「分類 1-12 全件で P4 前後の結論一致」を固定。 |
| **P4 の正味の変更（新規未契約 org の遮断）で新規登録者が詰む** | これは**反転の目的そのもの**であり、条件 A（P3 の導線実在）とセットでのみ成立する。`GateInversionF07RegressionTest` (b) が「遮断 → checkout 着地 200 → activate → 業務 route 200」の閉路を固定し、(d) が gate group 外 allowlist で無限ループ不在を固定する。 |
| **106 テストファイルの一斉 red** | `createOrganizationWithOwner` の既定を **backfill 相当（declarer-less grandfathered）**に変更して吸収。`activate()` を呼ばないため **signup grant が発火せず残高期待が壊れない**、かつ declarer partial unique index に触れないため 1 user 複数 org のテストも壊れない。`Organization::factory()` 直呼び 6 ファイルのみ `grandfatheredFree()` を手当。 |
| **migration がアプリコード（`SubscriptionService`）に依存する**（将来のシグネチャ変更で replay 不能） | 述語を写す代替のほうが有害（Critical の再発源）であり、**判定の単一ソース共有が移行の正しさの条件**。one-shot の data migration であること・`'personal'` リテラルは直書きのままであること・`deriveEntitlement(Subscription): SubscriptionEntitlementDto` は P2 の verbatim 契約（aigenba 側でも安定）であることで受容する。ドリフトは arch テストと `GrandfatherFreePlanBackfillTest` が CI で検知する。 |
| **支払い不健全の paid org が遮断先の checkout から Personal(free) へ自主降格する** | **aigenba も同挙動**であり独自ガードを足さない（原則 2・5）。`PersonalPlanService::eligibility()` の `HasEntitledSubscription` は `¬entitled` では発火しないが、降格が成立すれば `state()` は `ActiveFreePlan` を返す（`plan_code` を見ないため）= aigenba と同一の結論。分岐を発明しない。 |
| **`error` flash 廃止で遮断理由が失われる** | 理由は着地ページが持つ（aigenba 方式）。F-07 テスト (c) と `OnboardingBillingRequired.test.ts` が固定。`reflash()` は招待受諾等の直前 flash 延命のため維持（aigenba L89 と同じ）。 |
| **JSON 402 の文言変更で既存 XHR クライアントが後退** | D15 が守る経路（有償契約 + 支払い不健全 = `ExpiredCheckout`）の文言は現行と同一のまま維持。`NO_PLAN_MESSAGE` は新しい遮断事由（`NoSubscription` / `PendingCheckout`）にのみ追加。未契約 org が checkout 中断で `ExpiredCheckout` に落ちた場合に支払い文言が出るが、backfill 後の該当は **P3 以降の新規 org に限られ**、ブラウザ経路は着地ページが理由を持つため実害はない。 |
| **`fallback_plan` 切替で quota が silent に緩む**（`QuotaService.php:33` は未知キーを `?? []` = 無制限に倒す） | `personal` limits は P1 で投入済み・旧 free と同値。`QuotaTest` の limits 期待値を 1 つも変えないことで実効不変を証明し、`BackfillPlanCodeLiteralInvariantTest` が `fallback_plan` ⊆ `quota.plans` を CI で固定する。 |
| **`free` Plan 行の削除が参照行を壊す** | migration が `organizations.plan_code='free'` / 関連 `plan_prices` を事前検証して **fail-closed**（黙って消さない）。free は Stripe Price を持たないため `plan_code` に載る経路が構造的に無く（`PlanSeeder` docblock L21-27）、本番の残存は 0 件が期待値。残存が出たらデプロイを止めて調査する。 |
| **grandfathered org が declarer-less のまま滞留**（濫用防止が既存 org に効かない） | 概念設計で受容済み（自然収束しない旨を明記）。P4 は主張を広げない。 |
| **遮断先ページ（P3）の欠落・rename** | P4 のテストが route 名 3 本を直接叩くため欠落は red で検知。P4 単独マージ不可の依存として DoD に明記。 |
| **backfill の長時間ロック / N+1**（大量 org） | 母集団を `plan_code IS NULL ∧ free_plan_code IS NULL` に絞り、`with('subscriptions')` + `chunkById(500)` で走査、UPDATE は 500 件単位の `whereIn`。additive な UPDATE のみで index 再構築を伴わず、`whereNull('free_plan_code')` ガードで再実行安全。 |

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

### P7 新規登録経路（`?plan=` handoff + verify ソフトゲート継続）

料金表 → `/register?plan={code}` → 登録 → `verification.notice` → `onboarding.checkout` の「プラン意図」を aigenba と同一構造（2 キー session + 書き込み規約 2 種）で一貫保持する。前提: P1（`App\Enums\PlanCode` **5 case**）/ P3（`onboarding.{checkout,activate-personal,billing-required}` = **route parameter なし**の current-org スコープ）/ P4（ゲート反転・`RequireActiveSubscription` verbatim 化）/ P6（`CreateNewUser` からの signup grant 撤去）がマージ済み。

#### 変更箇所

**新規（aigenba verbatim 移植）**

| AI-CUE（新規） | 移植元 aigenba | 内容 |
|---|---|---|
| `app/Services/Onboarding/IntendedPlanResolver.php` | `app/Services/Onboarding/IntendedPlanResolver.php` | `PENDING_KEY='onboarding.intended_plan.pending'` / `orgKey()="onboarding.intended_plan.org.{$organization->id}"` / `normalizeRaw()`（`is_string` → `strtolower(trim())` → `PlanCode::tryFrom` → **`$code === PlanCode::Enterprise` なら null**。**Enterprise 除外分岐も verbatim 移植する**）/ pending 系 = **常に書き換え**（key 不在・null・空文字・改ざん → `forgetPending`）/ org-scoped 系 = **不在は no-op**（リロード耐性）/ `promotePendingToOrganization()`（pending は必ず forget で消費）。**docblock 込みで verbatim** |
| `app/Services/Onboarding/OnboardingReturnResolver.php` | 同名 | `orgKey()="onboarding.return_to.org.{$organization->id}"` / `normalizePath()` の多段 open-redirect 防御（制御文字・raw + `rawurldecode` の二重判定・scheme / protocol-relative・バックスラッシュ・`parse_url` の scheme/host/user/pass/port・先頭 `/` 必須・query 保持 / fragment drop）/ put は不正値 no-op / peek は再正規化。**verbatim** |
| `app/Support/Auth/EmailVerificationContinuation.php` | 同名 | session キー `verify_continue_organization_id` に **組織 ID のみ**保持。`resolveUrl()` は `is_int()` → `$user->organizations()->whereKey($organizationId)->first()` の membership 確認を通してから route を再構築（URL 直保持しない = ルート変更・値汚染・IDOR 耐性）。**AI-CUE では引数なしの `route('onboarding.checkout')` を生成**（D21: route parameter なし）。寿命 = `remember`（登録時）→ `forget`（verify 完了時）。※ AI-CUE に `app/Support/Auth/` は無いため新設 |
| `app/Http/Responses/Fortify/VerifyEmailResponse.php` | `app/Responses/Fortify/VerifyEmailResponse.php` | continuation の **forget 側ライフサイクル**。`resolveUrl` → `forget` → `continueUrl !== null` なら `redirect()->to($continueUrl)`、null なら **Fortify 既定と同値**（`redirect()->intended(config('fortify.home').'?verified=1')`。`fortify.home = '/dashboard'`）。aigenba の flash 再設計（`VerifyEmailController::ATTR_ALREADY_VERIFIED` / `auth.verify_*`）は **AI-CUE に `VerifyEmailController` が存在しない**ため移植しない（原則 4） |
| `resources/js/types/Auth.ts` | `resources/js/types/Auth.ts:1,9-15` | `export type PlanCode = 'personal' \| 'starter' \| 'standard' \| 'business' \| 'enterprise';`（**PHP の `PlanCode` 5 case と exact 対**）+ `RegisterPageProps { intendedPlan: PlanCode \| null; socialProviders: string[]; invitationEmail: string \| null }`。aigenba の `consentVersion` は AI-CUE の SSO 同意が query `terms_accepted=1` 方式のため含めない。**`PLAN_LABELS` は移植しない**（プラン表示名の真実源は `PlanSeeder.name` = サーバ確定。フロントに二重台帳を作らない = P3 で確立済みの規約） |

**改修**

- `app/Actions/Fortify/CreateNewUser.php`: `IntendedPlanResolver` を DI し、`->validate()` 通過後・`DB::transaction` 前に `rememberPendingFromForm($input)` を 1 行呼ぶ（移植元 `CreateNewUser.php:85-90`）。**`intended_plan` は validation rules に足さない**（aigenba の明示規約: 無効値でも登録は通す / 422 で止めない）。既存の招待 token 解決・`MatchesInvitationEmail`・`UniqueEncryptedEmail` には触らない。**signup grant は P6 で撤去済み。P7 で復活させない**（付与契機は P1 `PersonalPlanService::activate()` / P6 paid webhook の管轄）。aigenba の `starter_migration_acknowledged`（`CreateNewUser.php:76`）は **AI-CUE の Starter に「30 日後 Standard 自動移行」が存在しない**ため移植しない（原則 4）。
- `app/Http/Responses/Fortify/RegisterResponse.php`: 移植元 `app/Responses/Fortify/RegisterResponse.php:37-72`。ただし **AI-CUE は個人組織生成が `CreateNewUser` の tx 内で完結済み**のため `provisionPersonalOrganization` 呼び出しは持ち込まず、**分岐だけ**を移植する。
  - **招待経由分岐**（aigenba `InvitationContinuation::pull` 相当）: AI-CUE は招待受諾を `CreateNewUser::create()` の tx 内（`membership->acceptInvitationIfValid()`）で行い成立時は個人組織を作らないため、判定は `$user->organizations()->where('is_personal', true)->first()`。**null（= 招待組織へ参加）なら `forgetPending()` して現行どおり `verification.notice` へ**（continuation を張らない）。
  - 通常分岐: `promotePendingToOrganization($personalOrg)` → `EmailVerificationContinuation::remember($request->session(), $personalOrg->id)` → `verification.notice`。既存の `wantsJson() → 201` 後方互換は**維持**し、session 副作用を先に実行してから返す。
- `app/Providers/FortifyServiceProvider.php`
  - `configureViews()` の `registerView`（:182-203）: 既存 `socialProviders` / `invitationEmail` / `Cache-Control: no-store` を保ったまま `'intendedPlan' => IntendedPlanResolver::normalizeRaw($request->query('plan'))?->value` を追加（移植元 :141-157）。正規化は resolver 一本化（Provider 側で分岐を書かない）。
  - `verifyEmailView`（:219）: `Inertia::render('Auth/VerifyEmail', ['continueUrl' => EmailVerificationContinuation::resolveUrl($user instanceof User ? $user : null, $request->session())])`（移植元 :173-184）。`status` は AI-CUE の `VerifyEmail.svelte` が持たないため追加しない。
  - `register()`: `$this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class)` を追加（既存 :83 の `RegisterResponse` と同型）。
- `resources/js/pages/Auth/Register.svelte`: `intendedPlan?: PlanCode | null` prop を受け、`useForm` に `intended_plan: intendedPlan` を含めて**常に送信**（null も送る = resolver の `array_key_exists` 規約で stale pending を消す。移植元 :110-111）。`ssoHref`（:61-64）に `&plan={intendedPlan}` を伝播（intendedPlan が null なら付けない）。
- `resources/js/pages/Auth/VerifyEmail.svelte`: `continueUrl?: string | null` を受け、**非 null のときのみ**二次 CTA「あとで認証する（プラン選択へ進む）」= `router.visit(continueUrl)` を出す（移植元 :45-49、`testId="verify-email-continue"`）。既存の再送信・ログアウトは不変。
- `resources/js/pages/Pricing.svelte:124`: `<Button href="/register" fullWidth>このプランで始める</Button>` → `` href={`/register?plan=${encodeURIComponent(plan.code)}`} ``（移植元 `Guest/Pricing.svelte:164,189`）。`page.isAuthenticated` 分岐（`/billing`）はそのまま。nav の `/register`（:82）は plan なし = pending forget（fresh state）で aigenba 規約どおりのため変更しない。
- **D16: `resources/js/pages/Welcome.svelte` の `/register` 直リンク 3 箇所を `/pricing` へ**（P7 所管。P8b ではない）
  - `:137` guest nav「無料で始める」/ `:160` hero `testId="hero-register"`「無料で始める」/ `:358` `landing-pricing-cta` 内「無料で始める」の `href` を **`/pricing`** にし、`inertia` 属性を付ける（既存 :360 の `/pricing` Button と同じ SPA 遷移作法）。**文言（「無料で始める」）は変更しない**（Personal(free) が実在するため事実。P6 の文言変更と非衝突）。
- **P3 / P4 導線への結線**（クラスを置くだけでは handoff が閉じないため P7 で結線する。移植元の呼び出し位置に対応）
  - `app/Http/Controllers/Onboarding/OnboardingController::show`: `$request->has('plan')` なら `rememberForOrganizationFromQuery($request, $organization)` → canonical URL（`route('onboarding.checkout')`）へ **303**。不在なら `peekForOrganization($organization)` を `pageData.intendedPlanCode` に載せて preselect（移植元 `Onboarding/OnboardingController.php:68-81`）。`?choose` / `preselectFunding` は funding 2 択が AI-CUE に無い（P8a 非移植）ため持ち込まない。
  - `app/Http/Controllers/Onboarding/ActivatePersonalController::__invoke`: 成功後に `$continue = $returnResolver->peekForOrganization($organization); $returnResolver->forgetForOrganization($organization);` → `redirect()->to($continue ?? route('dashboard'))`（移植元 `Onboarding/ActivatePersonalController.php:63-65`。P3 の `dashboard` 固定を差し替え）。
  - `app/Http/Middleware/RequireActiveSubscription.php`（P4 で verbatim 化済み）: 遮断時、**`$canManage && GET/HEAD && ! $request->expectsJson()`** のときだけ `returnResolver->rememberForOrganization($org, '/'.ltrim($request->path(), '/'))`（移植元 `Http/Middleware/RequireActiveSubscription.php:74-81`。既存の `reflash()` は維持）。
  - `app/Http/Controllers/Billing/BillingController::checkout`（:85-95）: `$gateway->createSubscriptionCheckout()` が URL を返した直後・`Inertia::location()` の**前**に `intendedPlanResolver->forgetForOrganization($organization)`（= 契約開始で意図を消費。移植元 `Billing/BillingController.php:605-608`）。`back()->with('error', …)`（price 不在）経路では **forget しない**（意図を維持して再試行できる = aigenba の in-progress 分岐と同方針）。
  - `app/Http/Controllers/Billing/BillingController::index`: `state($organization)->grantsAccess()` **かつ** `returnResolver->peekForOrganization($organization) !== null` のときだけ `forgetForOrganization()` して `'continueUrl'` prop を載せる（1 回限り = リロードで CTA が残らない）。`Billing/Index.svelte` は非 null のとき「元の画面に戻る」CTA を出す。aigenba `resolveOnboardingContinue`（`Billing/BillingController.php:285-297`）の `?session_id` + `CheckoutSessionStatus::Completed` 判定を、**P2 で移植済みの `BillingAccess::state()->grantsAccess()`（`?session_id` 非依存・同一意味論「契約成立着地でのみ提示」）**に写したもの。`?session_id` 依存の feedback は P9 所管で、P7 は触らない。
- **SSO 経路**（移植元 `Auth/SsoController.php:113,149,363`。AI-CUE は POST ではなく GET のため `rememberPendingFromQuery` を使う = aigenba に実在する同族 API で、新規メソッドは発明しない）
  - `app/Http/Controllers/Auth/SocialAuthController::redirect`（:43 の register 分岐直後）: `$intent === 'register'` のとき `rememberPendingFromQuery($request)` / `$intent === 'login'` のとき `forgetPending()`。`link` / `step-up` は触らない。
  - 同 `callback`（:117 `$service->register()` 直後・`redirect()->route('dashboard')` の前）: 個人組織（`$user->organizations()->where('is_personal', true)->first()`）を解決し `promotePendingToOrganization($personalOrg)`。**redirect 先（`dashboard`）は P7 では変えない**。register 拒否分岐（:103,:112 の `withErrors`）では `forgetPending()`（stale を残さない）。

#### 波及変更

- **TypeScript 型定義**
  - `resources/js/types/Auth.ts`（**新規**）: `PlanCode`（**5 case**）/ `RegisterPageProps`。
  - `resources/js/types/onboarding.ts`（P3 産出）: `OnboardingCheckoutShape` に **additive** で `intendedPlanCode: string | null`。
  - `resources/js/types/billing.ts`: `Billing/Index` の props に **additive** で `continueUrl: string | null`。`PurchaseTicketsPageDto` の `ticketAttemptToken` には**一切触らない**（subscription 用 `subscriptionAttemptToken` は P9 の別型）。
  - `VerifyEmail.svelte` の Props に `continueUrl?: string | null`（ページ内 interface。既存が inline 定義のため d.ts 追加不要）。
  - `resources/js/types/marketing.ts` は **変更なし**（`PricingPlanShape.code` を既に持つ）。
- **DTO / JsonResource**: 新規なし。`OnboardingCheckoutDto`（P3）に `intendedPlanCode: ?string` を additive 追加（`@phpstan-type OnboardingCheckoutShape` も同時更新）。`PricingPageDto` / `LandingPageDto` は無改変。`response()->json()` 直書きなし。
- **Inertia props（追加分のみ）**: `Auth/Register` = `+ intendedPlan: string|null` / `Auth/VerifyEmail` = `+ continueUrl: string|null` / `Onboarding/Checkout` = `+ pageData.intendedPlanCode` / `Billing/Index` = `+ continueUrl: string|null`。
- **DI / bind**: `FortifyServiceProvider::register()` に `VerifyEmailResponseContract` singleton。`CreateNewUser` / `RegisterResponse` / `SocialAuthController` / `OnboardingController` / `ActivatePersonalController` / `BillingController` / `RequireActiveSubscription` へ resolver を constructor 注入（自動解決。binding 追加なし）。
- **DB / migration / route**: **なし**（session キーと prop のみ。route は P3 が定義済み）。
- **テストファイル（新規）**: `tests/Unit/Services/Onboarding/IntendedPlanResolverTest.php` / `tests/Unit/Services/Onboarding/OnboardingReturnResolverTest.php` / `tests/Unit/Support/Auth/EmailVerificationContinuationTest.php` / `tests/Feature/Auth/RegisterPlanHandoffTest.php` / `tests/Feature/Auth/RegisterVerifyFlowTest.php` / `tests/Feature/Onboarding/OnboardingCheckoutPlanHandoffTest.php`（移植元: aigenba `tests/Unit/Services/Onboarding/{IntendedPlanResolverTest,OnboardingReturnResolverTest}.php` / `tests/Feature/Auth/{RegisterPlanHandoffTest,RegisterVerifyFlowTest}.php` / `tests/Feature/Onboarding/{OnboardingCheckoutPlanHandoffTest,RegisterRedirectsToCheckoutTest}.php`）。
- **テストファイル（更新・削除しない）**: `tests/Feature/Auth/RegistrationTest.php` / `RegistrationInvitationPrefillTest.php` / `FortifyResponseTest.php` / `EmailVerificationGateTest.php` / `SocialAuthTest.php` / `tests/Feature/Marketing/PricingPageTest.php` / `tests/js/pages/{Welcome,Pricing}.test.ts` / `tests/js/pages/OnboardingCheckout.test.ts`。

#### 主要な契約

```php
final class IntendedPlanResolver {
    public const PENDING_KEY = 'onboarding.intended_plan.pending';
    public function __construct(private readonly Session $session) {}
    public static function orgKey(Organization $organization): string;   // onboarding.intended_plan.org.{id}
    public static function normalizeRaw(mixed $value): ?PlanCode;        // 非string/無効/Enterprise → null（verbatim）
    public function rememberPendingFromQuery(Request $request): void;    // 'plan' 不在 → forget
    public function rememberPendingFromForm(array $input): void;         // 'intended_plan' key 不在 → forget
    public function peekPending(): ?PlanCode;  public function forgetPending(): void;
    public function rememberForOrganizationFromQuery(Request $r, Organization $o): void; // 不在 → no-op
    public function peekForOrganization(Organization $o): ?PlanCode;
    public function forgetForOrganization(Organization $o): void;
    public function promotePendingToOrganization(Organization $o): void; // pending は必ず forget で消費
}
final class OnboardingReturnResolver {
    public function __construct(private readonly Session $session) {}
    public static function orgKey(Organization $o): string;              // onboarding.return_to.org.{id}
    public static function normalizePath(mixed $value): ?string;         // same-origin 内部 path のみ（query 保持 / fragment drop）
    public function rememberForOrganization(Organization $o, string $path): void; // 不正値 no-op
    public function peekForOrganization(Organization $o): ?string;
    public function forgetForOrganization(Organization $o): void;
}
final class EmailVerificationContinuation {
    private const string SESSION_KEY = 'verify_continue_organization_id';
    public static function remember(Session $s, int $organizationId): void;
    public static function resolveUrl(?User $u, Session $s): ?string;    // membership 確認 → route('onboarding.checkout')（引数なし）
    public static function forget(Session $s): void;
}
```

- `RegisterResponse::toResponse($request): JsonResponse|RedirectResponse`（既存シグネチャ維持）。DI に `IntendedPlanResolver` を追加。
- `VerifyEmailResponse::toResponse($request): RedirectResponse` / `VerifyEmailResponseContract` に singleton bind。
- **`PlanCode` は verbatim 5 case**（`Personal` / `Starter` / `Standard` / `Business` / `Enterprise`。P1 産出）。`normalizeRaw` は `tryFrom` に加えて **`$code === PlanCode::Enterprise` → null** を持つ（Enterprise はセルフサーブ契約フローに乗らない = お問い合わせ営業導線）。TS 側は `export type PlanCode = 'personal' | 'starter' | 'standard' | 'business' | 'enterprise';`。
- URL 契約: **`/register?plan={PlanCode::value}`**。未知値・改ざん・配列は `normalizeRaw` が null 化 → `intendedPlan` prop は null。`?plan=enterprise` は **有効な enum 値だが normalizeRaw が明示的に除外**して null（未知値扱いではない）。
- session キー（真実源。DB 変更・route 追加なし）: `onboarding.intended_plan.pending` / `onboarding.intended_plan.org.{id}` / `onboarding.return_to.org.{id}` / `verify_continue_organization_id`。
- 依存 route（P3 が定義。**引数なし・current-org スコープ**）: `onboarding.checkout` / `onboarding.activate-personal` / `onboarding.billing-required`。continuation は **組織 ID を session 保持**し、参照時に membership を確認してから引数なしの `route('onboarding.checkout')` を生成する（URL を session に直保持しない）。
- **招待経由との排他契約**: 招待受諾成立（= `is_personal` の個人組織が存在しない）→ `forgetPending()` / continuation を張らない / `verification.notice` へ（現行どおり）。招待成立時は個人組織が無いため `promotePendingToOrganization` の対象自体が存在しない。

#### PHPStan 適合チェック（level 10）

- `normalizeRaw(mixed): ?PlanCode` — `is_string()` で mixed を絞ってから `PlanCode::tryFrom(strtolower(trim($value)))`。**`$code === PlanCode::Enterprise` は 5 case のうちの 1 case との比較であり `identical.alwaysFalse` は発生しない**（v1 の 3 case 縮小は撤回済み）。`baseline` / 型 widen での回避は行わない（禁止事項 #2）。
- `session->get()` の戻り値 mixed は `is_string($raw) ? self::normalizeRaw($raw) : null`（aigenba と同形）で narrowing。`normalizePath(mixed)` も同じく `is_string()` ガード先頭。
- `EmailVerificationContinuation::resolveUrl` — `is_int($organizationId)` で mixed session 値を絞り、`?User` の null 分岐を明示。`$user->organizations()` は `BelongsToMany<Organization, User>` generics が `User` モデル側に既存（`OrganizationProvisioningService` が同型で level 10 通過済み）。
- `RegisterResponse` — `$request->user()` は mixed のため `Assert::isInstanceOf($user, User::class)`（Webmozart は `CreateNewUser` で既に使用）で narrow。`->where('is_personal', true)->first()` は `?Organization` として解決し、null 分岐（招待経由）を明示する（`?->` で握り潰さない）。
- `verifyEmailView` クロージャ — `$request->user()` を `$user instanceof User ? $user : null` で絞ってから渡す（移植元 :180 と同形）。`registerView` は既存の `SymfonyResponse` 戻り型（`Cache-Control` 操作のため `->toResponse($request)` 済み）を維持する。
- `OnboardingReturnResolver::normalizePath` — `parse_url()` は `array<string,int|string>|false` のため `=== false` を先に弾き、`$parsed['path'] ?? '/'` を `string` として扱う（`preg_match(...) === 1` で int 戻り値を明示比較）。
- `Session` を constructor 注入する resolver を singleton の `RegisterResponse` が保持する点: `session.store` の Store は per-request に `setId/start` で再初期化される同一インスタンスのため安全（aigenba も singleton bind）。
- 戻り値は全て具象型（`RedirectResponse` / `JsonResponse|RedirectResponse` / `?string` / `?PlanCode`）。`response()->json()` 直書きなし。

#### テスト計画

**先に red を作る（新規）**

1. `tests/Unit/Services/Onboarding/IntendedPlanResolverTest.php` — pending 規約（key 不在 → forget / 有効（personal・starter・standard・business）→ put / **`enterprise` → forget**（verbatim の除外）/ 無効文字列・配列・null・空文字・前後空白 + 大文字（`' Starter '` → `starter`）→ 規約どおり）、org-scoped 規約（不在 → **no-op = 既存値が残る** / 無効 → forget）、`promotePendingToOrganization`（pending は必ず消費 / pending 無しなら org key を触らない）、`orgKey` 形状。
2. `tests/Unit/Services/Onboarding/OnboardingReturnResolverTest.php` — open-redirect データセット（`https://evil`, `//evil`, `/\evil`, `%2F%2Fevil`, `javascript:...`, `user:pass@host`, `:8080` 付き, `%0d%0a` 混入, 相対 `foo`）の reject と `/path?a=1#frag` → `/path?a=1`（query 保持 / fragment drop）。put の不正値 no-op（既存 return_to を壊さない）、peek の再正規化（session 改ざん値 → null）。
3. `tests/Unit/Support/Auth/EmailVerificationContinuationTest.php` — remember → `resolveUrl` が `route('onboarding.checkout')`（**引数なし**）、**他組織 id を session に注入しても null**（membership 確認 = IDOR 防御。不変条件 #2）、非 int / null user → null、forget 後は null。
4. `tests/Feature/Auth/RegisterPlanHandoffTest.php` — `POST /register`（`intended_plan=starter`）で pending が forget され org key に `starter` が promote される / **`enterprise`**・`foo`・key 欠落は promote されない（org key 不在）。Factory + `whereBlind('email','email_index',…)` で移植。
5. `tests/Feature/Auth/RegisterVerifyFlowTest.php` — 登録 → `verification.notice` の `continueUrl` prop が非 null（Inertia assert）→ verify 完了で continuation が forget され `onboarding.checkout` へ着地 / **continuation 無しは `'/dashboard?verified=1'` 着地**（Fortify 既定と同値 = 非退行）。
6. `tests/Feature/Onboarding/OnboardingCheckoutPlanHandoffTest.php` — 登録 → `GET /onboarding/checkout?plan=standard` が canonical URL へ **303** → 再 GET で `pageData.intendedPlanCode === 'standard'` / **plan なしリロードで preselect が消えない**（org-scoped no-op 規約）/ `?plan=enterprise` は preselect されない。
7. `GET /register?plan=personal|starter|standard|business|enterprise|<不正>` の `intendedPlan` prop（Inertia assert。enterprise・不正値は null）。招待経由（`invitationEmail` あり）の `Cache-Control: no-store` 非退行を同ファイルで維持。
8. **招待競合**（最重要）: 招待 token 保持 + `?plan=starter` で登録 → 招待組織へ参加 / **個人組織を作らない** / **pending は forget**（org key が一切作られない）/ continuation を張らない（`verification.notice` に `continueUrl === null`）/ 既存の招待受諾着地が不変。
9. **return_to の往復**: gate（`RequireActiveSubscription`）が manage-billing 保持者の GET を遮断 → return_to に元 path が積まれる / **POST・XHR（`expectsJson`）・非 manage-billing では積まれない** / `POST /onboarding/activate-personal` 成功で元 path へ復帰し return_to は消費される（2 回目は `dashboard`）/ 有料経路は `billing.index` の `continueUrl` prop（`grantsAccess()` 成立時のみ・1 回限り）。

**既存の更新（削除しない）**

- `tests/Feature/Auth/RegistrationTest.php` — **signup grant の期待は「登録時は未付与」のまま維持**（P6 で `CreateNewUser` から撤去済み。**登録時付与の期待を復活させない**）。`verification.notice` / `current_organization_id` の期待は維持し、**session キー（`onboarding.intended_plan.org.{id}` / `verify_continue_organization_id`）の期待を追加**する。
- `tests/Feature/Auth/RegistrationInvitationPrefillTest.php` — 招待経由で pending が消費されない / continuation が張られないことを追加（既存の prefill・非付与の期待は維持）。
- `tests/Feature/Auth/FortifyResponseTest.php` — `VerifyEmailResponseContract` bind 追加後の verify 着地。
- `tests/Feature/Auth/EmailVerificationGateTest.php` — continuation 無し時に既定着地が変わらない非退行（`assertRedirect` 群は不変）。
- `tests/Feature/Auth/SocialAuthTest.php` — SSO register の `?plan=` → pending → 個人組織へ promote / `intent=login` は forget / register 拒否分岐は forget。
- `tests/Feature/Marketing/PricingPageTest.php` / `tests/js/pages/Pricing.test.ts:101` — CTA href 期待を `/register?plan={code}` へ更新。
- `tests/js/pages/Welcome.test.ts`（**D16**） — `hero-register` / nav「無料で始める」/ `landing-pricing-cta` 内「無料で始める」の **`href` が `/pricing`** であること（3 箇所とも href を明示 assert し、`/register` 直リンクが LP に 1 本も無いことを固定）。既存の文言 assert（L43 の signup grant 文言 = P6 で更新済み）・モバイルパネル・法的リンク順序の期待は不変。
- `tests/js/pages/OnboardingCheckout.test.ts` — `intendedPlanCode` があれば preselect / null なら `defaultPlanCode`（無ければ先頭）という P3 の決定的挙動を維持。
- UI: `Register.svelte` / `VerifyEmail.svelte` は既存 `AuthLayout` 配下で primitive 構成を変えず、**新規 hex・新規 lucide import を入れない**ため page-shell-structure / ds-purity / atomic-import-graph / lucide-scoped-import は allowlist 追加なしで green（**disabled でブロックしない**規約も不変。禁止事項 #8）。

#### リスク

| リスク | 緩和 |
|---|---|
| **stale pending の誤 promote**（中断した OAuth 等で残った pending が次の plan 無し登録に promote される） | pending 規約「常に書き換え（key 不在は forget）」を `CreateNewUser` / `SocialAuthController::redirect` の**両入口**で守る。`Register.svelte` が `intended_plan: null` を**必ず送る**ことが前提のため、送信漏れをテスト 4 で検知 |
| **招待経由との競合**（最重要）。招待受諾が `CreateNewUser` tx 内で完結する AI-CUE では `RegisterResponse` が「個人組織の有無」で分岐するため、招待受諾が将来 personal org も作るよう変わると誤って continuation を張る | テスト 8 を回帰網に固定（pending forget / continuation 非設置 / 個人組織非生成の 3 点を同時に assert） |
| **open-redirect** | `OnboardingReturnResolver` を verbatim 移植し独自簡略化しない（peek 側の再正規化を落とすと session 汚染で外部遷移し得る）。テスト 2 のデータセットで固定 |
| **Enterprise の扱いを取り違える**（v1 で `PlanCode` を 3 case に縮小し `normalizeRaw` の除外分岐を削除した = **バグ発生源**） | **5 case + Enterprise 除外分岐を verbatim**。TS も 5 case。テスト 1・4・6・7 で「enterprise は enum として有効だが intent としては採用されない」を明示的に固定 |
| **P3 / P4 未マージでの前倒し** | `EmailVerificationContinuation::resolveUrl` が未定義 route を引くと `RouteNotFoundException` で verify 画面が 500。P3・P4 マージ済みを DoD にし、route 名（`onboarding.checkout`・引数なし）を P3 の実装と一致させる |
| **verify 着地の後退** | `VerifyEmailResponseContract` bind 追加は既存 verify 完了フローを置換する。continuation 無し時に Fortify 既定（`fortify.home` + `?verified=1`）と**同値**であることをテスト 5 と `EmailVerificationGateTest` で固定 |
| **`billing.index` の `continueUrl` が誤発火**（契約前・非該当 org で復帰 CTA が出る） | 条件を `state()->grantsAccess()`（P2 verbatim）に限定し、peek 成功時に必ず forget（1 回限り）。`?session_id` 依存の feedback は P9 所管で二重化しない |
| **PII キャッシュ** | `registerView` に prop を足しても `Cache-Control: no-store` の条件（`invitationEmail !== null && !== ''`）を変えない。`?plan=` は PII でないためキャッシュ抑止対象にしない |
| **rollback** | 本フェーズは additive（session キー + prop + CTA href）。コード revert のみで復帰可（DB 変更・migration・route 追加なし）。残留 session キーは旧コードが無視する |

---

### P8a: 裏チャージ = オートリチャージ（opt-in・既定 off）

残高が閾値を割ったら Stripe invoice で自動補充する。AI-CUE には実装・語彙が **0 件**（audit `ticket-charge-1` / `billing-subscription-2`）。aigenba の `AutoRechargeService`（1290 行 / 43 メソッド）を中核に **verbatim 移植**する。決済実行を伴うため、冪等キーと並行制御を契約として固定する。

**前提フェーズ**: P2（`BillingCheckoutSession` / `CheckoutIntent`（`SetupPaymentMethod` 済み）/ `Contracts\StripeGatewayInterface` / `BillingPermissionService`）、P3（`Onboarding/Checkout.svelte` / `ActivatePersonalController` / `ActivatePersonalRequest`）、P5（`availableTrueBalance`）、P7（`OnboardingReturnResolver` / `?plan=` handoff）。

**DoD**: **既定 off の opt-in**。設定行が無い org の挙動は完全不変（`reserve` の低残高通知も含む）。migration は additive のみ（新テーブル 2 + 列 1）。**値は aigenba 既定値のまま**（`default_threshold=5` / `default_max=50` / `max_count=1000` / `max_failures=3`）。**D20 の監視 DoD（後述）を満たすこと**。

#### 未決事項の決定（Round 13 Warning の解消。3 件とも本文へ昇格）

| ID | 論点 | 決定 | 根拠（実ファイル / 条項） |
|---|---|---|---|
| **D29** | signup-funding 事前同意層 | **移植する**（原則 1）。AI-CUE には宿主が実在するため原則 4 は適用できない（`ActivatePersonalController` / `Onboarding/Checkout.svelte` = P3 産出、`BillingCheckoutSession` = P2 産出）。AGENTS.md 抵触も無い（UI の disabled 回避のみ既決 = D4）。**所管を 2 つに確定**: **(i) P8a = free（personal）経路の全部** — `SignupFundingChoice`（**verbatim 3 case**）/ `ActivatePersonalRequest.{funding_choice, consent_version}` / `ActivatePersonalController` の `AutoRecharge` 分岐・`Tickets` 分岐・`setupAttemptToken` / `recordPreConsent` / `startSetupCheckout` / `applySetupCompletion` / `autoEnableEligible` / `isAutoEnablePending` / `pendingAutoEnable` / `hasRecentCompletedSetup`。**(ii) P9 = T1004 のサブスク決済カード流用** — `ReuseSubscriptionPaymentMethodJob` / `applyReusedPaymentMethod` / `resolveSubscriptionPaymentMethod` / `hasRecentAutoRechargeFundedSignup` / `billing_checkout_sessions.{funding_choice, pm_reuse_dispatched_at}` / 着地 flash の分岐 | P3 本文が既に `funding_choice` / `consent_version` / `startSetupCheckout` / `setupAttemptToken` を「**P8a**」へ明示委譲済み（設計本文 P3 変更箇所表）。(ii) の唯一の入力は **subscription checkout の `BillingCheckoutSession` 行（intent + funding_choice + attempt token）**で、その writer は **D25 により P9 所管**（P2 本文「`BillingCheckoutSession` の writer も P2 では存在しない（行 0 件）… writer は P9」）。P8a 時点では入力行を作る経路が AI-CUE に存在しない = **原則 4 の時点適用**であり、呼び出し元の無い `applyReusedPaymentMethod` を P8a に置くのは **P2 の「dead code を作らない」規約**（`getStatus()` 非移植と同一）に反する。**新 intent は不要**: AI-CUE の契約 checkout は `CheckoutIntent::SubscriptionStart`（`SignupFunding` は P2 が原則 4 で非移植）であり、P9 は `funding_choice` 列を additive に足すだけで T1004 が成立する |
| **D29-b** | `consent_version` の既定 | **P8a = `'v1'`／P9（T1004 配線）と同時に `'v2'` へ上げる**。値の発明ではなく **aigenba が定義した版の意味に機械的に従った結果** | `/tmp/aigenba/config/billing.php:39-46` が版の定義そのものを明記: 「改定履歴: **v1 = T1003 初版（カード登録経路のみ）** / **v2 = T1004 有償契約でサブスク決済カードをオートリチャージへ流用することを明示**」「提示条件の実質（…**カードの取得手段**）を変える変更では**必ず version を上げること**」。P8a が実装するのは T1003 = カード登録経路のみ ⇒ **v1 が aigenba の版管理規約に照らした正しい版**。P9 で流用を配線した瞬間に v2 へ上げると、`reconsentRequiredFor` 経由で既存同意が自動失効し再同意が要る = **aigenba の版管理契約そのもの**（fail-closed）。逆に P8a で v2 を置くと「未実装の副作用への同意」を記録することになり、版の定義に反する |
| **D30** | `ticket_purchases` 正本化 | **移植しない**（parity 逸脱の「承認待ち」ではなく**決定済み**）。`grantAutoRecharge` は **ledger インライン 1 本書き**。両建てが無いため片肺検証（`ledgerInserted !== $purchaseInserted` の `RuntimeException`）も**構造的に不要** | **ユーザー決定 F3**（設計本文 §ユーザー決定「チケット会計 = 残高会計の**精緻化**。**台帳の置換ではない**」。再検討しない前提）。AI-CUE の「購入の返金逆引き正本」は `ticket_ledger_entries` のインライン列（`payment_intent_id` + `purchase_amount`）として**既に存在する**（`TicketLedgerService.php:152-215 clawbackPurchasedByPaymentIntent` が PI で引く）ため、`ticket_purchases` は「AI-CUE に対象が存在しない機能」ではなく**同一機能の別構造**であり、両建て化は F3 が禁じた台帳の置換に当たる。`stripe_invoice_id` 列 1 本の additive 追加で invoice アンカーの返金逆引きが成立する。audit `ticket-charge-4`（「単独での先行導入はしない」）とも整合。**`AutoRechargeService` は `TicketPurchase` を一切参照しない**（aigenba でも参照は `TicketService::grantAutoRecharge` のみ）ため、移植範囲に穴は空かない |
| **D31** | Gateway 粒度 | **AI-CUE の狭い gateway + Fake 規約を維持**（P8a は `Contracts\AutoRechargeGatewayInterface`（**8 メソッド**）を新設）。aigenba の単一 `StripeGatewayInterface`（30+ メソッド）/ `CashierStripeGateway`（41KB）へは寄せない | **P2 v2 本文で既に確定済み**（`Contracts\StripeGatewayInterface` は 3 メソッドに限定 / 「aigenba の 30+ メソッド単一 interface へは寄せず、AI-CUE の狭い gateway + チケット系 Gateway 分割の境界を維持」）。**既存規約 = AI-CUE 側の構造**であり、単一巨大 interface へ寄せると **gateway 単位の Fake bind 契約が壊れる**（`app/Providers/FakeExternalsServiceProvider.php:79-80` の `TicketCheckoutGateway → FakeTicketCheckoutGateway` / `SubscriptionCheckoutGateway → FakeSubscriptionCheckoutGateway` と、それを検査する `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`）。P8a は同規約に沿って **3 本目の bind を足すだけ** |

#### 変更箇所

**マイグレーション（additive のみ）**

| AI-CUE（新規） | 内容 | 移植元 |
|---|---|---|
| `database/migrations/XXXX_create_ticket_auto_recharges_table.php` | 設定 1 org 1 行。`organization_id` unique / `enabled` default false / `threshold_count` / `max_count` / `stripe_payment_method_id` / `failure_count` / `disabled_reason` / 同意 snapshot 4 列（`consented_at` / `consent_version` / `consented_max_count` / `consented_max_amount`）/ `created_by_user_id`。`max_count > threshold_count` CHECK は pgsql/mysql のみ（sqlite は ALTER ADD CONSTRAINT 非対応 → driver guard） | `/tmp/aigenba/database/migrations/2026_07_09_000100_create_ticket_auto_recharges_table.php` |
| `database/migrations/XXXX_create_ticket_auto_recharge_attempts_table.php` | 試行の状態機械。`attempt_ulid` unique / `status` / `quantity` / `unit_amount` / `stripe_price_id` / `stripe_invoice_id` unique nullable / `stripe_payment_intent_id` / `failure_code` / `resolved_at`。**partial unique `tar_attempts_org_pending_unique ON (organization_id) WHERE status='pending'`** | `2026_07_09_000200_create_ticket_auto_recharge_attempts_table.php`（verbatim） |
| `database/migrations/XXXX_add_stripe_invoice_id_to_ticket_ledger_entries.php` | `ticket_ledger_entries.stripe_invoice_id` nullable + index（現行は `stripe_checkout_session_id` のみ）。**D30 の invoice アンカーはこの 1 列で成立** | `2026_07_09_000300_add_invoice_anchor_to_ticket_purchases_and_ledger.php` の **ledger 側のみ** |

> **partial unique index の driver guard**: AI-CUE 内に前例がある（`2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php` = pgsql/sqlite 限定 + 非対応 driver は `RuntimeException` で fail-closed）。attempts の partial unique も**同一様式に揃える**（aigenba の raw `DB::statement` は driver チェックを持たないため、そこだけ AI-CUE の既存前例に合わせる）。

**Enum / DTO**

| AI-CUE（新規） | 移植元 |
|---|---|
| `app/Enums/Billing/AutoRechargeDisabledReason.php`（`PaymentFailures` / `User`） | 同名（verbatim） |
| `app/Enums/Billing/AutoRechargeAttemptStatus.php`（`Pending`/`Paid`/`Failed`/`Canceled`） | 同名（verbatim） |
| `app/Enums/Billing/SignupFundingChoice.php`（`AutoRecharge` / `Tickets` / `Later`。**3 case verbatim**。case 縮小は D1/D2 撤回済みの禁じ手） | 同名（verbatim。docblock も） |
| `app/DataTransferObjects/Billing/AutoRechargeConsentDto.php`（`version` のみ） | 同名（verbatim） |
| `app/DataTransferObjects/Billing/AutoRechargeConsentTermsDto.php`（`thresholdCount` / `maxCount` / `maxAmountJpy` / `unitAmountJpy` / `consentVersion`） | 同名（verbatim） |
| `app/DataTransferObjects/Billing/AutoRechargeSettingsDto.php`（**17 フィールド verbatim**。`pendingAutoEnable` / `setupPending` を含む） | 同名（verbatim） |
| `app/DataTransferObjects/Billing/DefaultPaymentMethodDto.php` / `OffSessionChargeResultDto.php` / `InvoiceStateDto.php` | 同名（gateway 戻り値） |
| `app/Enums/Billing/BillingNotificationType.php` に **4 case 追加**（`AutoRechargeFailed` / `AutoRechargeDisabled` / `AutoRechargeActionRequired` / `AutoRechargeEnabled`） | 同 enum L27-30（現行 AI-CUE は `PaymentFailed` / `RenewalReminder` の 2 case） |

> `PurchaseTicketsDto` は **AI-CUE では `PurchaseTicketsPageDto`** が現行名。P8a では `autoRechargeEnabled: bool` の 1 フィールド追加に留める（`formState`/`resumeUrl`/`returnTo` は audit `ticket-charge-5`/`-6` = **P8b の別 finding**）。`CheckoutIntent::SetupPaymentMethod` は **P2 で既に存在**（追加不要）。

**Model / Factory**

- `app/Models/Billing/TicketAutoRecharge.php` / `TicketAutoRechargeAttempt.php` ← aigenba 同名（verbatim。`disabled_reason` は enum cast）
- `database/factories/Billing/{TicketAutoRechargeFactory,TicketAutoRechargeAttemptFactory}.php` ← aigenba 同名（**新モデルには Factory を作る**規約 = テストデータ手組み禁止）

**Service / Gateway（D31）**

- `app/Services/Billing/AutoRechargeService.php` ← aigenba 同名（**AI-CUE 接地の 3 点**は「主要な契約」参照。T1004 の 2 メソッド（`applyReusedPaymentMethod` / `hasRecentAutoRechargeFundedSignup`）は **D29 により P9**）
- `app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php`（新規）+ `app/Services/Billing/CashierAutoRechargeGateway.php` + `app/Services/Billing/Fakes/FakeAutoRechargeGateway.php` ← aigenba `Contracts/StripeGatewayInterface.php` の **auto-recharge 8 メソッドのみ**を切り出す（`resolveSubscriptionPaymentMethod` は P9 で追加）
- `app/Providers/{AppServiceProvider,FakeExternalsServiceProvider}.php`: 3 本目の gateway bind を追加（`FakeExternalsServiceProvider.php:79-80` と同一様式）
- `app/Services/Billing/TicketLedgerService.php`: `grantAutoRecharge()` 追加 + **`reserve()` に trigger dispatch を追加**（`TicketLedgerService.php:277-279` の既存 `DB::afterCommit` に同居）

**Job / Command / Notification**

- `app/Jobs/Billing/{AutoRechargeTriggerJob,ExecuteAutoRechargeAttemptJob,HandleAutoRechargeChargeFailureJob,SetDefaultPaymentMethodJob}.php` ← aigenba 同名
- `app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php` ← aigenba 同名（**verbatim**）+ `routes/console.php` に scheduler 登録（**D20**。既存「課金 cron」ブロックの様式に合わせる）
- `app/Notifications/Billing/{AutoRechargeFailed,AutoRechargeDisabled,AutoRechargeActionRequired,AutoRechargeEnabled}Notification.php` ← aigenba 同名。AI-CUE の `TracksBillingDelivery` / `TracksBillingReminderDelivery` contract を実装（`BillingNotificationDispatcher::sendOnce` / `sendReminderOnce` が Assert で delivery key 一致を強制）

**Controller / Request / Route / Config**

- `app/Http/Controllers/Billing/BillingController.php`: `updateAutoRecharge` / `startAutoRechargeSetup` / `index` に setup 着地解決（303 + flash）を追加 ← aigenba `BillingController.php:737` / `:778` / `:216`
- `app/Http/Requests/Billing/{UpdateAutoRechargeRequest,StartAutoRechargeSetupRequest}.php` ← aigenba 同名（`ProhibitsProtectedKeys` は AI-CUE にも `app/Http/Requests/Concerns/` に実在。P3 で `ActivatePersonalRequest` に配線済み）
- **D29(i) の onboarding 部分**: `app/Http/Requests/Onboarding/ActivatePersonalRequest.php` に `funding_choice`（`Rule::in(SignupFundingChoice::cases())`）+ `consent_version`（`required_if:funding_choice,auto_recharge` + `Rule::in([currentConsentVersion()])`）を **additive 追加**（`messages()` の 2 文言も verbatim）/ `app/Http/Controllers/Onboarding/ActivatePersonalController.php` に `AutoRecharge` 分岐（`recordPreConsent` → `startSetupCheckout` → `Inertia::location`）・`Tickets` 分岐（`billing.tickets.show` へ redirect）・`setupAttemptToken()`（session 保持 ULID）を追加
- `routes/web.php`: `POST /billing/auto-recharge` → `billing.auto-recharge.update` / `POST /billing/auto-recharge/setup` → `billing.auto-recharge.setup`。**current-org スコープ**（D6/D21。aigenba の org-slug スコープは移植しない）。既存 `billing.*` と同じく**課金ゲート allowlist**（`require-active-subscription` group の外）
- `config/billing.php`: `auto_recharge` ブロック追加 ← `/tmp/aigenba/config/billing.php:31-47`（`default_threshold=5` / `default_max=50` / `max_count=1000` / `max_failures=3` / `pending_expiry_hours=24` / `setup_pending_window_minutes=30` / **`consent_version='v1'`（D29-b）**）
- `docs/architecture.md`: **監視対象リストへ登録**（D20。既存 L138 / L150 / L266 の「デプロイ手順・監視対象に … を必須項目として登録する」様式）

**UI（最小。情報密度の作り込みは P8b）**

- `resources/js/components/features/billing/AutoRechargeCard.svelte` ← aigenba 同名。T071 primitive（`molecules/PageHeaderSection` 配下）に載せ `Billing/Index.svelte` に組み込む。**P8a に含める理由**: これが無いと opt-in 導線が存在せず機能が到達不能なまま merge される
- `resources/js/pages/Onboarding/Checkout.svelte`: **funding 2 択（`auto_recharge`（既定・おすすめ）/ `later`）+ 同意条件の提示**を追加（D29(i)。`consentTermsFor()` の値をそのまま表示 = 単一計算源）。`tickets` は aigenba T1002 で UI 撤去済みのため**出さない**（enum・validation では受理継続 = verbatim）。**disabled でブロックしない**（禁止事項 #8 / D4）

#### 波及変更

**TypeScript 型定義**
- `resources/js/types/billing.ts`: `AutoRechargeProps`（= `AutoRechargeShape` と exact 対）/ `AutoRechargeConsentTerms` 新規、`PurchaseTicketsPageProps` に `autoRechargeEnabled: boolean`、`BillingIndexProps` に `autoRecharge: AutoRechargeProps` を追加 ← aigenba `resources/js/types/Billing.ts`
- `resources/js/types/onboarding.ts`（P3 産出）: `OnboardingCheckoutShape` に `consentTerms` / `fundingChoices` を additive 追加
- `resources/js/types/notification.ts`: 通知種別 union に auto_recharge 系 4 種を追加

**DTO / JsonResource**
- 新規 DTO 6 本（上記）。`AutoRechargeSettingsDto` は `@phpstan-type AutoRechargeShape` を持ち、**subscription 有無に依存せず常に非 null**（free 組織も対象）
- `PurchaseTicketsPageDto` に `autoRechargeEnabled` 追加（+ shape 更新）/ `OnboardingCheckoutDto` に `consentTerms: AutoRechargeConsentTermsShape` 追加（P3 が「フィールド名は aigenba と同一にし P8a は additive に足すだけ」と規定済み）
- `BillingController::index` の Inertia props に `autoRecharge` 追加（**DTO 経由。`response()->json()` 直書きなし**）。JsonResource の新設なし（auto-recharge は API 公開面を持たない）

**Inertia props**: `Billing/Index` に `autoRecharge: AutoRechargeShape` / `Billing/PurchaseTickets` に `autoRechargeEnabled: bool` / `Onboarding/Checkout` に `consentTerms`。

**P9 への申し送り（D29(ii)。未割当を残さない）**: P9 の DoD に **T1004 一式**（`billing_checkout_sessions.{funding_choice, pm_reuse_dispatched_at}` additive 追加 / `ReuseSubscriptionPaymentMethodJob` / `AutoRechargeService::{applyReusedPaymentMethod, isAutoEnablePending 呼び出し, hasRecentAutoRechargeFundedSignup}` / `AutoRechargeGatewayInterface::resolveSubscriptionPaymentMethod` / `settingsFor.setupPending` の (b) 条件 / 着地 flash 分岐 / **`consent_version` を `'v2'` へ改定**）を記載する。**`AutoRechargeSettingsDto` の shape は P8a で既に aigenba verbatim（`pendingAutoEnable` / `setupPending` を保持）**のため、P9 は DTO を変更せず配線のみで済む。

**テストファイル（新規）**
`tests/Feature/Billing/{AutoRechargeServiceTest,AutoRechargeEndpointTest,AutoRechargeWebhookTest,AutoRechargeReconcileTest,AutoRechargeTriggerTest,AutoRechargePreConsentTest,TicketAutoRechargeModelTest}.php` / `tests/js/components/features/billing/AutoRechargeCard.test.ts` + `tests/js/support/autoRechargeProps.ts`（aigenba 同名を移植）
（参考: aigenba 側対応 `tests/Feature/Billing/{AutoRechargeServiceTest,AutoRechargeEndpointTest,AutoRechargeWebhookTest,AutoRechargeReconcileTest,AutoRechargeAutoEnableTest,TicketServiceAutoRechargeGrantTest,TicketAutoRechargeModelTest}.php` / `tests/Feature/Onboarding/ActivatePersonalEndpointTest.php`）

**テストファイル（更新。削除しない）**
- `tests/Feature/Billing/TicketLedgerTest.php` — `reserve()` に trigger dispatch が増える（`Queue::fake()` 追加。**既存の低残高通知期待は維持**）
- `tests/Feature/Billing/BillingPageTest.php` — Index props に `autoRecharge` 追加
- `tests/Feature/Billing/TicketRefundClawbackTest.php` — invoice アンカー付与（`stripe_invoice_id`）の逆仕訳ケース追加
- `tests/Feature/Billing/{WebhookIdempotencyTest,TicketPurchaseWebhookTest}.php` — `invoice.paid` の auto_recharge 分岐追加に伴う期待更新
- `tests/Feature/Billing/BillingNotificationDispatchTest.php` — 新 4 種の dispatch 期待
- `tests/Feature/Onboarding/ActivatePersonalTest.php`（P3 産出）— `funding_choice` 省略時は **dashboard 着地のまま**（既存期待不変）+ `auto_recharge` 分岐を追加
- `tests/Architecture/{MassAssignmentSafetyTest,FormRequestProhibitedKeyTest}.php` — inventory に新 Model 2 / 新 FormRequest 2 が乗る
- `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` — 3 本目の gateway bind の期待追加（D31）

#### 主要な契約

**冪等キー（全経路の合流点）**

| アンカー | キー | 保証 |
|---|---|---|
| 付与 | `recharge:{stripeInvoiceId}`（`ticket_ledger_entries.idempotency_key` UNIQUE） | webhook / 同期 pay / リコンサイルのどれが先でも **1 invoice = 1 回付与** |
| Stripe 呼び出し | `idempotencyKeyBase = "auto-recharge:{attempt_ulid}"` | invoice create / pay の再送で同一 invoice に収束（プロセス死からの復帰でも二重 invoice を作らない） |
| カード登録 | `auto-recharge-setup:{attemptToken}`（`billing_checkout_sessions.idempotency_key` / `attempt_token` UNIQUE + Stripe 冪等キー） | 二重 submit で SetupPaymentMethod 台帳を増殖させない（`setupAttemptToken()` が session 保持 ULID を再利用） |
| pending | partial unique `tar_attempts_org_pending_unique` | **org あたり pending attempt は同時 1 つ**（アプリロックの最終防衛） |

**並行制御（契約）**
- ロック名 `billing:auto-recharge:{orgId}` / **TTL 180 秒**（`LOCK_TTL_SECONDS`）。**全ミューテータ**（`updateSettings` / `recordPreConsent` / `applySetupCompletion` / `executeAttempt`）が同一ロックを取るため、**停止後課金と部分適用が構造的に起こらない**。TTL は Stripe client timeout より十分長く取る。
- `createAttemptLocked` は `Organization` 行を `lockForUpdate()` してから残高評価〜起票する（**`reserve()` と同順の org 行ロック** = ロック順序の交差を作らない）。
- lock 取得失敗はバックグラウンド経路では **structured no-op**（`Log::info`）、ユーザー明示操作（`updateSettings` / `recordPreConsent`）のみ `CheckoutInProgressException`、webhook Job 経路（`applySetupCompletion`）は **`RuntimeException` で Job retry に乗せる**（snapshot 未反映を握り潰さない。verbatim）。

**`AutoRechargeService` 主要シグネチャ**（aigenba verbatim）

```php
public function isEnabledFor(Organization $org): bool
public function settingsFor(Organization $org, bool $canManage): AutoRechargeSettingsDto
public function updateSettings(Organization $org, User $user, bool $enabled, int $threshold, int $max, ?AutoRechargeConsentDto $consent): TicketAutoRecharge
public function consentTermsFor(): AutoRechargeConsentTermsDto
public function recordPreConsent(Organization $org, User $user, AutoRechargeConsentDto $consent): TicketAutoRecharge  // D29(i)
/** @return array{id: string, url: string|null} */
public function startSetupCheckout(Organization $org, User $user, string $successUrl, string $cancelUrl, string $attemptToken): array
public function maybeCreateAttempt(Organization $org): ?TicketAutoRechargeAttempt
public function executeAttempt(TicketAutoRechargeAttempt $attempt): void
public function recordSuccessfulCharge(Organization $org, TicketAutoRechargeAttempt $attempt, string $invoiceId, int $amountPaid, int $amountDue, ?string $paymentIntentId): void
public function handleChargeFailure(Organization $org, TicketAutoRechargeAttempt $attempt, ?string $failureCode, bool $requiresAction): void
public function terminateAndFail(Organization $org, TicketAutoRechargeAttempt $attempt): void
public function terminateAndCancel(TicketAutoRechargeAttempt $attempt): void
/** @return array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int} */
public function reconcile(): array
public function applySetupCompletion(Organization $org, string $paymentMethodId): bool
public function isAutoEnablePending(Organization $org): bool
```

**`AutoRechargeGatewayInterface`（D31。8 メソッド）**

```php
namespace App\Services\Billing\Contracts;

interface AutoRechargeGatewayInterface {
    /** @param array<string, string> $metadata @return array{id: string, url: string|null} */
    public function createSetupCheckout(Organization $org, string $successUrl, string $cancelUrl, array $metadata, string $idempotencyKey): array;
    /** @param array<string, string> $metadata  purpose / organization_id / recharge_attempt_ulid 必須 */
    public function createAutoRechargeInvoice(Organization $org, string $priceId, int $quantity, array $metadata, string $idempotencyKeyBase): string;
    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto;
    public function terminateInvoice(string $invoiceId): void;          // open→void / draft→delete。paid は例外
    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto;  // 不在は status='deleted'
    public function getDefaultPaymentMethodState(Organization $org): DefaultPaymentMethodDto;
    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string;
    public function setDefaultPaymentMethod(Organization $org, string $paymentMethodId): void;
}
```

**AI-CUE 接地のための 3 点の差分**（機械移植できない箇所。いずれも実コード由来）

1. **trigger 点は `commit` ではなく `reserve`**。aigenba は `TicketService::commit` で `-1` が書かれた経路のみ発火する（`TicketService.php:558-566`）。**AI-CUE は `balance() = SUM(delta) − SUM(reserved)` のため実効残高が減るのは `reserve`、`commit` は拘束 −amount と台帳 −amount が相殺して balance 不変**（`TicketLedgerService.php:270-280` の docblock が明示）。よって `AutoRechargeTriggerJob::dispatch` は **`reserve()` の `DB::afterCommit`（`TicketLedgerService.php:277-279`）に、既存 `notifyTicketBalanceLow` と同居**させる。audit `ticket-charge-9` が「同じ『残高が減った』イベントへの応答」と両者を同一点として記録しており、これが接地された対応点。閾値判定は Job 側で再評価するため過剰 dispatch は無害（pending 検査 + partial unique が吸収）。
2. **`grantAutoRecharge` は ledger インライン 1 本書き**（**D30**）。
   ```php
   public function grantAutoRecharge(Organization $org, int $count, string $stripeInvoiceId, int $amount, ?string $paymentIntentId): void
   // Assert::greaterThan($count, 0); Assert::greaterThanEq($amount, 0);  // credit balance 全額適用で 0 は正当
   // insertIdempotent($org, "recharge:{$stripeInvoiceId}", [
   //   delta: $count, kind: Grant, source: TicketSource::Purchased, granted_at: now, expires_at: null,
   //   stripe_invoice_id: $stripeInvoiceId, payment_intent_id: $paymentIntentId, purchase_amount: $amount ])
   ```
   **clawback は `payment_intent_id` で引く**（`clawbackPurchasedByPaymentIntent`）ため、auto-recharge invoice の PI を書けば既存の返金経路がそのまま効く。PI が webhook 欠落で null のときは aigenba と同型の **null→値の単調 backfill のみ**（値→別値の上書きはしない = 冪等・改竄防止）を ledger 行に対して行う。
3. **`resolveVolumeTier` / `PURCHASE_MAX_COUNT` の出典**。aigenba は `TicketService::PURCHASE_MAX_COUNT` / `resolveVolumeTier` / `currentUnitPriceAmount` / `volumeTiersForDisplay`。AI-CUE は **`TicketVolumePrice::PURCHASE_MAX_COUNT`(=1000) / `PURCHASE_MIN_COUNT`(=1)**（`app/Models/Billing/TicketVolumePrice.php:44,47`）と **`TicketVolumePrice::currentTierFor(int $count): TicketVolumeTier`**（`:72`）、**`TicketPricingService::{volumeTiersForDisplay,spotUnitAmount}`**（`:27,:52`）。invoice の `priceId` は `TicketVolumeTier::stripePriceId`、金額検証は `TicketVolumeTier::unitAmount`。`config('billing.auto_recharge.max_count')` は `TicketVolumePrice::PURCHASE_MAX_COUNT` と**単一真実源で揃える**（両者とも 1000 = aigenba 既定値と一致）。

**`BillingCheckoutSession` の最初の writer は P8a になる（P2 との契約）**
`startSetupCheckout` が `intent=SetupPaymentMethod` / `status=pending` の行を書く。`BillingAccess::state()` は **intent を見ない**（aigenba verbatim）が、setup 導線への到達には必ず **`ActiveFreePlan`（activate-personal 完了済み）または `Subscribed`** が先行するため、`state()` の分岐 2/1 で確定し **分岐 4（`PendingCheckout`）には落ちない**（aigenba でも同じ理由で不到達）。**`state()` は改変しない**。この不変条件は回帰テストで固定する。

**amount cross-check（fail-closed）**
`recordSuccessfulCharge` は `attempt.unit_amount * attempt.quantity === invoice.amount_due` を検証し、不一致は `RuntimeException`。**照合対象は `amount_due` であって `amount_paid` ではない**（customer credit balance 適用で `amount_paid < amount_due` は正当）。付与額（`purchase_amount`）には**実回収額 `amount_paid`** を記録する。

**状態機械（終端保証）**
`pending → paid`（冪等付与後）/ `pending → failed`（invoice void/delete **成功後のみ**。`failure_count+1`）/ `pending → canceled`（終端成功後のみ。`failure_count` 増分なし）。**open invoice を残したまま終端しない** = 遅延支払いによる二重課金・二重付与の構造的排除。invoice 終端に失敗したら pending 維持 → リコンサイルが再試行。SCA（`authentication_required`）は**終端させない**（pending 維持 + 日次リマインダ。`pending_expiry_hours` 超過で failed）。

**再同意判定（単一述語）**
`reconsentRequiredFor(TicketAutoRecharge $config, int $max): bool` を **UI 表示（`settingsFor.requiresReconsent`）/ 設定更新（`updateSettings.needsConsent`）/ 自動有効化（`autoEnableEligible`）/ attempt 起票停止（`createAttemptLocked`）の 4 箇所で共有**する。条件 = version 不一致 ∨ 同意記録欠落 ∨ `$max > consented_max_count` ∨ 現行カタログ最大請求額 > `consented_max_amount`。**同意金額は必ずサーバ再計算**（`TicketVolumePrice::currentTierFor($max)->unitAmount * $max`）。client hidden の金額は信用しない（`AutoRechargeConsentDto` は `version` のみを受ける）。

**事前同意 → 自動有効化（D29(i)。fail-closed）**
`recordPreConsent` は `enabled=false` のまま同意証跡のみ記録し、**稼働中設定（`enabled=true`）は上書きしない** / **`disabled_reason` を消さない** / **PM snapshot が既にある row を enabled にしない**（= `pendingAutoEnable` も false。有効化は請求ページの既存 UI に委ねる）。`autoEnableEligible($config)` = `! enabled && disabled_reason === null && consented_at !== null && ! reconsentRequiredFor($config, $config->max_count)`。`applySetupCompletion` は同一 org lock 内で PM snapshot を書き、`autoEnableEligible` のときのみ `enabled=true` + `failure_count=0` に遷移して `AutoRechargeEnabled` を通知する（**通知失敗で webhook Job を落とさない** = `report()` で握る。verbatim）。`pendingAutoEnable` の PM 有無判定は**必ず local snapshot（`stripe_payment_method_id`）**で行う（gateway の default PM を見ると `setDefaultPaymentMethod` 後〜snapshot 反映前の窓で同意ダイアログが誤オープンする）。

**quantity 確定**
`quantity = min($config->max_count - availableTrueBalance($org), TicketVolumePrice::PURCHASE_MAX_COUNT)`、`Assert::greaterThan($quantity, 0)`。attempt 作成時に**一度だけ**確定し以降 `attempt.quantity` が真実源。`availableTrueBalance` が構造的に非負（P5 の per-source `max(...,0)`）であることが `quantity <= max_count` = 同意上限 invariant の根拠。**P5 側 docblock に「変更時は AutoRechargeService の契約も見直す」旨を追記**する。

**webhook 分岐（`StripeWebhookProcessor`）**
現行 `invoice.paid` は `GRANTING_BILLING_REASONS = ['subscription_create','subscription_cycle']` の allowlist で弾くため、auto-recharge invoice（`billing_reason='manual'`）は**月次付与に誤混入しない**（既存ガードで安全。D28 で付与枚数も 0）。新たに `metadata.purpose === 'auto_recharge'` かつ `metadata.recharge_attempt_ulid` を持つ invoice を `recordSuccessfulCharge` へ、`invoice.payment_failed` を `HandleAutoRechargeChargeFailureJob` へ、`checkout.session.completed`（`intent=SetupPaymentMethod`）を `SetDefaultPaymentMethodJob` へ振る分岐を追加。**metadata は照合専用**（org 解決・認可には使わない = 既存 `grantPurchasedTickets` の tenant キー不信規約 / 不変条件 #1 に従う）。**外向き Stripe API は webhook 同期処理から Job へ退避**（aigenba T710 invariant と AI-CUE の既存 webhook 規約が一致）。

**通知 dedup**
`AutoRechargeFailed` / `AutoRechargeDisabled` / `AutoRechargeEnabled` → `sendOnce($org, $type, invoiceId: "recharge:{$attempt->attempt_ulid}", ...)`（`sendOnce` は `Assert::stringNotEmpty($invoiceId)` のため invoice 未作成でもキーが立つ ULID を使う）。`AutoRechargeActionRequired` → `sendReminderOnce($org, $type, dedupKey: "auto_recharge_sca:{$invoiceId}:{JST Y-m-d}", ...)`（日次で再通知 = 放置失効の防止）。**低残高通知（`notifyTicketBalanceLow`）は無改変で併存**（既定 off の opt-in のため既存挙動は変わらない。AI-CUE 独自の抑制ロジックは発明しない）。

**ルート / 認可**
```
POST /billing/auto-recharge        → billing.auto-recharge.update   Gate::authorize('manageBilling', $org)
POST /billing/auto-recharge/setup  → billing.auto-recharge.setup    Gate::authorize('manageBilling', $org)
```
Gate ability 名は **AI-CUE の `manageBilling`**（`OrganizationPolicy::manageBilling`。既存 `BillingController.php:75,101` と同一。P3 の adaptation 規約）。permission 文字列は P2 の `BillingPermissionService::PERMISSION_MANAGE_BILLING = 'manage-billing'`。両 route とも課金ゲート allowlist（既存 `billing.*` と同扱い）。閲覧（Index の card 表示）は組織メンバー全員、変更は owner/admin。

**D20: リコンサイルの監視（DoD 必須。「注意喚起」で終わらせない）**

**既存監視への接続確認（実施済み）**: AI-CUE に scheduler 失敗の専用アラート機構は**存在しない**（`routes/console.php` / `app/Console/Commands/**` / `bootstrap/app.php` に `onFailure` / 外形監視の実装は 0 件）。**唯一の運用アラート経路は `report()`**（`docs/architecture.md:207`「attempts 上限 (8) に到達すると terminal-ack + `report()`（運用アラート）」）。よって本フェーズは**その既存経路へ接続する**（新機構を発明しない）。DoD:

```php
// routes/console.php（既存「課金 daily バッチ」ブロックの隣）
Schedule::command('billing:reconcile-auto-recharge')
    ->everyFifteenMinutes()->onOneServer()->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:reconcile-auto-recharge 失敗 — 資金回収済み・チケット未付与が滞留する可能性',
    )));
```
1. 上記 `onFailure` → `report()` 配線（`ReconcileAutoRechargeAttempts` 本体は **verbatim**。lock timeout は `Log::warning` + exit 1 = aigenba のまま）。
2. `docs/architecture.md` の**監視対象リストへ必須項目として登録**（既存 L138/150/266 の様式）: コマンド名・実行間隔（15 分）・**失敗/停止の意味（webhook が `MAX_PROCESSING_ATTEMPTS=8` で恒久 drop した「課金済み・付与なし」の唯一の回収経路）**・滞留の観測点（`ticket_auto_recharge_attempts.status='pending'` の滞留件数）。
3. 回帰テスト（下記テスト計画）で **scheduler 登録そのもの**（コマンド + cron 式 `*/15 * * * *`）を固定する。

#### PHPStan 適合チェック（level 10 / widen・baseline 禁止）

- **`reconcile(): array` は `@return array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int}`** を付し、`ReconcileAutoRechargeAttempts::handle` 側は `Cache::lock(...)->block(5, fn (): array => ...)` の戻りが `mixed` になるため **`/** @var array{...} $stats */` で narrowing**（aigenba 同型）。
- **`Cache::lock()->block()` のクロージャ戻り値は `mixed`**。`updateSettings`（`TicketAutoRecharge`）/ `recordPreConsent`（`TicketAutoRecharge`）/ `applySetupCompletion`（`bool`）/ `maybeCreateAttempt` / `executeAttempt` の各所で `/** @var T $result */` + `Assert` により narrowing。
- **`$attempt->organization` は `BelongsTo` の nullable 解決**のため `Assert::isInstanceOf($org, Organization::class)` で narrowing（`reconcile` ループ / `executeAttempt`）。`$attempt->created_at` は `Carbon|null` → `Assert::notNull` 後に `CarbonImmutable::instance()`。
- **`OffSessionChargeResultDto::$amountPaid` / `$amountDue` は `int|null`**（Stripe 応答由来）→ `Assert::integer()` で narrowing してから `recordSuccessfulCharge(int, int)` へ渡す。**戻り型に nullable を漏らさない**。
- **`config()` 戻り値は `mixed`** → `TicketLedgerService` が使う **`config()->integer('billing.…')` に揃える**（`intConfig` helper を新設せず既存規約に寄せる）。`currentConsentVersion(): string` のみ `config()->string(...)` + 空文字ガード。
- **`SignupFundingChoice` は enum で比較**（`$funding === SignupFundingChoice::AutoRecharge`）。`$request->validated('funding_choice')` は `mixed` → `is_string()` 判定後に `::from()`（aigenba T1002 Codex R3 と同じ理由 = 分岐網羅を PHPStan に見せる）。
- **generics**: `TicketAutoRecharge` / `TicketAutoRechargeAttempt` に `/** @use HasFactory<TicketAutoRechargeFactory> */`、`organization(): BelongsTo` に `@return BelongsTo<Organization, $this>`。Factory は `/** @extends Factory<TicketAutoRecharge> */`。
- **DTO 返却**: `settingsFor` / `consentTermsFor` は DTO を返し Controller は `->toArray()` を Inertia props に渡す（`response()->json()` 直書きなし）。`@phpstan-type AutoRechargeShape` / `@phpstan-import-type PurchaseTierShape from PurchaseTierDto` で TS 側と shape を固定。
- **`disabled_reason`** は `AutoRechargeDisabledReason|null` cast → DTO へは `$config?->disabled_reason?->value`（`string|null`）。
- **`isUniqueViolation(QueryException $e): bool`** は driver 別 SQLSTATE（`23505` pgsql / sqlite）判定。`$e->getCode()` は `mixed` のため文字列比較前に narrowing。

#### テスト計画（テストファースト。既存テストは削除せず期待を更新）

**先に red を作るテスト**

`tests/Feature/Billing/AutoRechargeServiceTest.php`
- `既定は off` — 設定行が無い org で `isEnabledFor` false / `settingsFor.enabled` false / trigger しても attempt が起票されない（**opt-in の回帰**）
- `有効化は fail-closed` — default PM 無しで `updateSettings(enabled: true)` → `ValidationException`（422）/ 同意 version 不一致 → `ValidationException`
- `同意金額はサーバ再計算` — client が偽の金額を送っても `consented_max_amount = currentTierFor($max)->unitAmount * $max`
- `再同意の 4 箇所一致` — 価格改定後に `settingsFor.requiresReconsent === true` **かつ** `createAttemptLocked` が起票しない **かつ** `autoEnableEligible` が false（UI 文言と実挙動の一致）
- `quantity は attempt 作成時に一度だけ確定` — 作成後に残高が動いても `attempt.quantity` 不変
- `停止後課金の禁止` — pending attempt がある状態で `updateSettings(enabled: false)` → invoice 終端 + `canceled` 遷移、以降 `executeAttempt` は no-op
- `連続失敗で自動無効化` — `max_failures`(3) 回目の failed で `enabled=false` + `disabled_reason=payment_failures` + `AutoRechargeDisabled` 通知
- `SCA は終端しない` — `requires_action` で pending 維持 + `failure_count` 増えない + `AutoRechargeActionRequired` 通知

`tests/Feature/Billing/AutoRechargePreConsentTest.php`（**D29(i)**）
- `activate-personal + funding_choice=auto_recharge` — `recordPreConsent` が `enabled=false` + 同意 4 列を記録し、setup Checkout へ `Inertia::location`
- `consent_version 欠落 / 現行版不一致 → 422`（`ActivatePersonalRequest` で activate 前に fail-closed）
- `二重 submit で SetupPaymentMethod 台帳が増殖しない`（`setupAttemptToken` の session 安定化 + `attempt_token` unique）
- `カード登録完了で自動有効化` — `applySetupCompletion` → `enabled=true` + `AutoRechargeEnabled` 通知（1 回だけ）
- **`fail-closed の 3 条件`** — 稼働中設定は上書きされない / `disabled_reason` 保持の row は自動有効化しない / **PM snapshot 済み row は `pendingAutoEnable=false`**
- `funding_choice=later（既定）は dashboard 着地のまま`（P3 の既存挙動が変わらない回帰）/ `funding_choice=tickets` は `billing.tickets.show` へ
- **`setup session は state() を PendingCheckout にしない`** — activate-personal 済み org は `ActiveFreePlan` 優先（P2 契約の回帰）

`tests/Feature/Billing/AutoRechargeTriggerTest.php`（**AI-CUE 固有の要**）
- `reserve で閾値クロス → AutoRechargeTriggerJob が dispatch される`（`Queue::fake()`）
- **`既存の低残高通知が消えていない`** — 同一 reserve で `notifyTicketBalanceLow` も発火する（**parity の名での機能後退を防ぐ回帰**。audit `ticket-charge-9`）
- `commit では dispatch されない`（balance 不変のため）— AI-CUE 特有の意味論を固定
- `reserve が rollback したら dispatch されない`（`afterCommit` の保証）
- **`amount ベース reserve が壊れていない`** — 可変コスト（`reserve($org, 7)`）が従来どおり成立（D5 のドメイン境界の回帰）
- **`reserve→commit/release の 2 フェーズが維持されている`**（AGENTS.md 不変条件 #7）

`tests/Feature/Billing/AutoRechargeWebhookTest.php`
- **`二重課金・二重付与しない`** — 同一 invoice の `invoice.paid` を 2 回処理しても ledger は 1 行（`recharge:{invoiceId}` 冪等）
- `webhook と同期 pay の競合` — どちらが先でも付与 1 回、`attempt.status=paid` 1 回
- `auto-recharge invoice が月次付与に混入しない` — `billing_reason='manual'` の invoice.paid で `grantMonthlyTickets` が呼ばれない（既存 allowlist の回帰）
- `amount_due 不一致で fail-closed` — `RuntimeException` + 付与なし
- `amount_paid < amount_due（credit balance 適用）は正当` — 付与成立 + `purchase_amount = amount_paid`
- **`PI の単調 backfill`** — PI 欠落で付与された行に後続再送で PI が載る（値→別値の上書きはしない）

`tests/Feature/Billing/AutoRechargeReconcileTest.php`（**5 分岐すべて + D20**）
- (i) invoice 未作成 + 15 分超 → 再実行（`retried`）
- (ii) Stripe 上 paid だが webhook 未着 → **付与回収**（`recovered_paid`）。**terminal drop の唯一のセーフティネット**
- (iii) SCA 待ち → 日次リマインダ（`sca_reminded`）、同日 2 回目は dedup で送られない
- (iv) `pending_expiry_hours` 超過 → SCA は failed / それ以外は canceled（`expired`）
- (v) enabled + 閾値割れ + pending なし → 取りこぼし起票（`triggered`）
- `1 attempt の例外が他 org の回収を止めない`（隔離）/ `lock 競合で exit 1`（`LockTimeoutException` 経路 + `Log::warning`）
- **D20: scheduler 登録の回帰** — `app(Schedule::class)->events()` に `billing:reconcile-auto-recharge` が **`*/15 * * * *`** で登録されている（`getExpression()` / コマンド文字列で照合）

`tests/Feature/Billing/AutoRechargeEndpointTest.php`
- `manageBilling を持たない member は 403`（update / setup 両方）/ `他 org の設定を触れない`（IDOR）
- `enabled=true で consent_version 欠落 → 422` / `max_count <= threshold_count → 422` / `max_count > config max → 422`
- setup 着地が 303 + flash（GET で副作用を起こさない）

`tests/Feature/Billing/TicketAutoRechargeModelTest.php`
- **`org に pending は同時 1 つ`** — 並行起票で `tar_attempts_org_pending_unique` が効き、後着は **500 にせず no-op**（`isUniqueViolation` 吸収）
- `max_count > threshold_count` CHECK（pgsql のみ。sqlite は skip）/ append-only / mass assignment 安全性

`tests/js/components/features/billing/AutoRechargeCard.test.ts` / `tests/js/pages/OnboardingCheckout.test.ts`
- 既定 off の表示 / PM 未登録時はカード登録 CTA / `requiresReconsent` 時に「再同意まで自動購入は行われません」/ `pendingAutoEnable` 時に「カード登録完了で自動的に有効になります」/ `canManage=false` で操作不可（**disabled にしない** = 押下時にエラー表示。禁止事項 #8）
- Onboarding の funding 2 択（`auto_recharge` 既定 + `later`）と同意条件の表示値が `consentTerms` と一致する
- `tests/js/support/autoRechargeProps.ts` に props factory（aigenba 同名を移植）

**既存テストの更新（削除禁止・期待の更新のみ）**: `TicketLedgerTest`（`Queue::fake()` 追加。**低残高通知期待はそのまま残す**）/ `BillingPageTest`（`autoRecharge` props）/ `TicketRefundClawbackTest`（`stripe_invoice_id` 経由付与の返金按分ケース追加。既存 checkout 経路の期待は不変）/ `WebhookIdempotencyTest`・`TicketPurchaseWebhookTest`（`invoice.paid` 分岐）/ `BillingNotificationDispatchTest`（新 4 type）/ `ActivatePersonalTest`（`funding_choice` 省略時の既存期待は不変）/ `FakeExternalsServiceProviderTest`（3 本目の bind）/ arch テスト inventory。

**arch テスト（UI 分）**: `AutoRechargeCard.svelte` / `Billing/Index.svelte` / `Onboarding/Checkout.svelte` が `page-shell-structure` / `ds-purity`（token のみ・hex 直書き禁止）/ `atomic-import-graph` / `lucide-scoped-import` を満たす。

**共通 DoD**: Factory 必須（手組み禁止）/ 個別 `DatabaseTransactions` 不使用（`RefreshDatabase` グローバル・`--parallel`）/ Stripe は `FakeAutoRechargeGateway` を bind（実 API を撃たない）。

#### リスク

| リスク | 緩和 |
|---|---|
| **二重課金（最重大）** | 3 層: (1) Stripe idempotency key `auto-recharge:{ulid}` で invoice create/pay が収束、(2) `tar_attempts_org_pending_unique` で org あたり pending 1 つ、(3) 付与は `recharge:{invoiceId}` の ledger UNIQUE。加えて **failed/canceled は invoice 終端（void/delete）成功後のみ** = open invoice を残して終端しないため遅延成功による二重課金が構造的に起きない |
| **停止後課金** | `updateSettings` / `recordPreConsent` / `applySetupCompletion` / `executeAttempt` が**同一ロック** `billing:auto-recharge:{orgId}`。lock 内で `enabled` を再確認してから invoice 作成 → 停止側は実行完了後にしか pending を終端できない |
| **課金済み・付与なし（webhook terminal drop）** | `MAX_PROCESSING_ATTEMPTS = 8` で webhook は恒久 drop し得る。**リコンサイル (ii) が唯一のセーフティネット**。scheduler 15 分毎 + `onOneServer()` + `withoutOverlapping()`。**D20 の監視 DoD（`onFailure` → `report()` / `docs/architecture.md` の監視対象登録 / scheduler 登録の回帰テスト）を満たさない限り本フェーズは完了しない** |
| **迷子 invoice（プロセス死）** | `stripe_invoice_id` の永続化を `pay` より**必ず前**に行う。復帰時は同一 key base で Stripe 冪等により同一 invoice が返る |
| **trigger 点の変更（commit→reserve）が aigenba と非対称** | 意図的。AI-CUE の `balance()` は reserve で減り commit で不変（実コード docblock + audit `ticket-charge-9`）。commit に置くと**閾値クロスを取り逃す**。`AutoRechargeTriggerTest` で両方向（reserve で発火 / commit で発火しない）を固定 |
| **低残高通知との二重通知** | **aigenba のまま両立**（既定 off の opt-in のため既存挙動は無変更）。**独自の抑制ロジックは発明しない**（audit `ticket-charge-9`「AI-CUE 固有の低残高通知は parity の名で削除しない」） |
| **`ticket_purchases` を持たない差分（D30）** | **F3（台帳の置換ではない）の帰結**であり P8a 単独の逸脱ではない。`payment_intent_id` + `purchase_amount` + 新 `stripe_invoice_id` で返金逆引きが成立することを `TicketRefundClawbackTest` で固定。両建てが無いため片肺検証は構造的に不要 |
| **signup-funding の 2 分割（D29）** | P8a 時点では `pendingAutoEnable` は「setup Checkout 経路」でのみ true になり、サブスク決済カード流用（T1004）は P9 まで働かない。**`consent_version='v1'` が「カード登録経路のみ」を意味する**ため、同意文言と実挙動は P8a 時点で一致する（P9 で v2 へ上げると既存同意は自動失効 → 再同意 = aigenba の版管理契約どおり）。**P9 の DoD に T1004 一式と version 改定を明記**することで未割当を残さない |
| **`BillingCheckoutSession` の最初の writer になる** | `state()` は intent を見ない（verbatim）が、setup 導線の到達には `ActiveFreePlan` / `Subscribed` が先行するため `PendingCheckout` には落ちない。**`state()` を改変せず**回帰テストで固定する |
| **P5 依存（`availableTrueBalance`）** | P5 未達だと閾値判定が保守的近似（過小評価）になり**過剰補充**する。P5 マージ後に着手する順序を DoD に固定。P5 側 docblock に本契約への依存を明記 |
| **ロック TTL 失効による直列化の破れ** | TTL 180 秒（Stripe client timeout より十分長い）。`block` 待機は短く（3〜10 秒）し、競合時は no-op → リコンサイルが再試行 |
| **消費者保護 / 特商法** | 同意文言の実質（開始残高・補充枚数・上限額の提示形式・停止方法・即時課金可能性・**カードの取得手段**）を変える改定では **`consent_version` を上げる** = `reconsentRequiredFor` 経由で既存同意が自動失効し自動購入が停止する（fail-closed）。**既定値・文言・版番号は aigenba verbatim**（D29-b / 原則 3） |
| **rollback** | 全変更が additive（新テーブル 2 + 列 1 + 新 route/Job/Command）。**コード revert で即時復帰** — 既定 off のため設定行が存在せず、`reserve` の dispatch も消える。pending attempt が残る場合のみ、revert 前に `billing:reconcile-auto-recharge` を 1 回流して収束させる（資金回収済みは必ずチケットになる） |

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
- `/workspace/resources/js/types/marketing.ts`: **変更なし**（`PricingPlanShape.monthlyTicketGrant` の削除は **D28 により P1 が実施済み**。P8b では触らない = 二重定義しない）

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
PricingPlanShape         = { code, name, baseAmountJpy: int|null, 
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

### P9: サブスク checkout の冪等・着地 feedback + 請求先情報 + PM 流用（T1004）

前提（v2）: P1〜P8b がマージ済み。**`BillingCheckoutSession`（model + migration + Factory）・`CheckoutIntent`（`App\Enums\CheckoutIntent`: `SubscriptionStart` / `SetupPaymentMethod`）・`CheckoutSessionStatus`（`App\Enums\CheckoutSessionStatus`: `Pending` / `Completed` / `Failed` / `Expired`）は P2 で導入済み**（`BillingAccess::state()` の `PendingCheckout` / `ExpiredCheckout` が読むため前倒し = D25 v2）。**`billing_checkout_sessions` の最初の writer は P8a**（`startSetupCheckout` が `intent=SetupPaymentMethod` / `status=pending` の行を書く。P8a 本文「最初の writer は P8a になる（P2 との契約）」）。**P9 が新規に書くのは `intent=SubscriptionStart` の行**であり、P9 の冪等状態機械・dedup・feedback・sweeper はすべて **P8a の setup 行と同居する前提**で設計する。P2 の `state()` は **live pending を `created_at >= now()-1day` の in-memory 判定で見る**（`expires_at` 列は存在しない）。P8b までで `BillingDashboardDto` / `BillingPlansPageDto` / `Billing/Plans.svelte` / `BillingCustomerSynchronizer` / `SyncBillingCustomerDetails` / `StripeGatewayInterface` + `CashierStripeGateway` + `FakeStripeGateway` が揃っている。P8a までで **`AutoRechargeService`（`recordPreConsent` / `applySetupCompletion` / `autoEnableEligible` / `isAutoEnablePending` / `hasRecentCompletedSetup` / `reconsentRequiredFor`）・`SignupFundingChoice`（3 case）・`AutoRechargeSettingsDto`（`pendingAutoEnable` / `setupPending` を持つ aigenba verbatim shape）・`Contracts\AutoRechargeGatewayInterface`（8 メソッド）+ `CashierAutoRechargeGateway` + `Fakes\FakeAutoRechargeGateway`・`app/Jobs/Billing/*`・`config/billing.php` の `auto_recharge` ブロック（`consent_version='v1'`）**が揃っている。

**P9 の担当は 4 つ**: (a) `attempt_token` による冪等状態機械を **`SubscriptionStart` 行の writer として配線**する、(b) 着地 feedback（`resolveBillingFeedback` + `Billing/Index` バナー）、(c) 請求先情報（`billing_contact_email` / `billing_contact_name`）、**(d) T1004 = サブスク決済カードのオートリチャージ流用**（D29(ii) で P8a から明示移譲。`ReuseSubscriptionPaymentMethodJob` / `applyReusedPaymentMethod` / `resolveSubscriptionPaymentMethod` / `hasRecentAutoRechargeFundedSignup` / `billing_checkout_sessions.{funding_choice, pm_reuse_dispatched_at}` / `settingsFor.setupPending` の (b) 条件 / 着地 flash 分岐 / `consent_version` の `'v2'` 改定）。

**DoD**: サブスク checkout が二重 subscription 作成を構造的に起こせない（`UNIQUE(organization_id, intent, attempt_token)` + org-wide live pending dedup + Stripe idempotency key + INSERT race の re-read 収束）。**`state()` / `startCheckout()` / 日次 sweeper が同一の live 判定（`created_at >= now()-1day`）を単一出典から共有し、stale pending が永久に再利用される経路が構造的に存在しない**（下記 C-1）。**webhook の遷移条件が一意**（`Completed` 以外は payload の判定結果へ遷移。`Completed` 終局。下記 C-2）。**T1004 一式が実装され**、`funding_choice=auto_recharge` の契約 checkout が**決済確定（`payment_status ∈ {paid, no_payment_required}`）のときだけ** `ReuseSubscriptionPaymentMethodJob` を dispatch し、`applyReusedPaymentMethod` が**適格性先行 fail-closed**（同意なし・失効・停止状態では customer default PM にもローカル snapshot にも一切触れない）で有効化する。**`consent_version` は `'v2'`**（= v1 同意は `reconsentRequiredFor` 経由で自動失効 → 再同意。fail-closed）。`billing_contact_*` は **CipherSweet 暗号化で保存**され、平文 DB 非保存・平文 where 不 hit が Feature/Architecture テストで固定される。**金銭の付与経路には一切触らない**（D7 維持: 付与は `invoice.paid`、`plan_code` 同期は `customer.subscription.*`）。**`EffectivePlan` は使わない**（判定源は `BillingAccess::state()` の `OnboardingBillingState`）。

**token 型名の分離（交渉不可）**: チケット決済の `ticketAttemptToken` / `ticket_checkout_sessions.attempt_token` / Stripe key `purchase:{token}` は **P8b までで確定済みの別テーブル・別 key 空間**。P8a のカード登録は `billing_checkout_sessions` の **`intent=setup_payment_method`** + Stripe key `auto-recharge-setup:{token}`。P9 が導入するのは `subscriptionAttemptToken`（props / TS 型名）/ `billing_checkout_sessions.attempt_token`（**`intent=subscription_start` でスコープ**）/ Stripe key `sub_start:{token}`（aigenba verbatim の名前空間）。3 者を同一 DTO・同一 key 空間に混ぜない。

#### 変更箇所

| ファイル (AI-CUE) | 何をするか | 移植元 (aigenba) |
|---|---|---|
| `app/Models/Billing/BillingCheckoutSession.php`（改修。P2 導入分） | **live 判定の単一出典を置く**（C-1）。`// 境界は排他的に統一する:`<br>`//   live  : created_at >= staleThresholdAt($now)  （isLivePending / state() / dedup の SQL filter）`<br>`//   stale : created_at <  staleThresholdAt($now)  （sweeper の expireStaleCheckouts）`<br>`// 両者は補集合であり、境界時刻ちょうどの行が「live かつ Expired 化対象」になることはない。`<br>`public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable` = `$now->subDay()`（**aigenba の閾値をそのまま**）/ `isLivePending(CarbonImmutable $now): bool` = `status === CheckoutSessionStatus::Pending->value && ($created_at === null \|\| $created_at->greaterThanOrEqualTo(self::staleThresholdAt($now)))` / `isReplayablePending(CarbonImmutable $now): bool` = `isLivePending($now) && checkout_url !== null && checkout_url !== ''`。**additive 2 列の宿主化**: `@property string\|null $funding_choice` / `@property Carbon\|null $pm_reuse_dispatched_at` + `$fillable` へ `funding_choice`、`$casts` へ `'pm_reuse_dispatched_at' => 'datetime'`（**`pm_reuse_dispatched_at` は `$fillable` に入れない** = webhook の `forceFill` 専用 marker） | `/tmp/aigenba/app/Models/Billing/BillingCheckoutSession.php:23,35,49,67,96-104` + `BillingAccess.php:58` / `ReconcileSubscriptionSchedules.php:113` の `subDay()` を 1 箇所へ集約。AI-CUE 先例 `app/Models/Billing/TicketCheckoutSession.php:64-68` |
| `database/migrations/2026_07_xx_xxxxxx_add_signup_funding_to_billing_checkout_sessions.php`（新規。**additive のみ**） | `funding_choice` = `string(16)->nullable()->after('plan_code')` / `pm_reuse_dispatched_at` = `timestamp()->nullable()->after('completed_at')`。**P2 所管テーブルへの additive 列追加のみ**（既存列・index・UNIQUE は触らない）。`down()` は `dropColumn(['funding_choice','pm_reuse_dispatched_at'])` | `/tmp/aigenba/database/migrations/2026_06_25_090200_add_signup_funding_to_billing_checkout_sessions.php:21`（`funding_choice` の列型 verbatim。`pack_count` / `topup_count` / `applied_trial_days` は原則 4 で非移植）+ `/tmp/aigenba/database/migrations/2026_07_09_140000_add_pm_reuse_dispatched_at_to_billing_checkout_sessions.php`（docblock ごと verbatim） |
| `app/Services/Billing/BillingAccess.php`（改修） | `state()` の stale 判定を `$row->isLivePending($now)` 経由へ差し替える（`$now = CarbonImmutable::now()` を 1 回だけ取り、`$threshold` のローカル literal を撤去）。**挙動不変**（同じ `subDay()` 値）。P2 の分岐表・`BillingAccessStateTest` は**無変更で green** | `/tmp/aigenba/app/Services/Billing/BillingAccess.php:57-75` |
| `app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php`（改修） | **`expireStaleCheckouts()` を追加**（**境界は排他: `created_at < staleThresholdAt()`** = live 判定 `>=` の補集合）。`BillingCheckoutSession::query()->where('status', Pending)->where('created_at', '<', BillingCheckoutSession::staleThresholdAt(CarbonImmutable::now()))->update(['status' => Expired])`。**intent で絞らない**（verbatim。P8a の `SetupPaymentMethod` 行も対象）。Stripe 照会なし。`handle()` の集計行へ `expired={n}` を追加。既存 daily 登録（`routes/console.php:38`）に相乗り = **新 command も新 `Schedule::command()` 行も作らない** | `/tmp/aigenba/app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php:112-121` |
| `app/Services/Billing/SubscriptionService.php`（改修） | **`startCheckout()` を冪等マシンへ差し替える**（`SubscriptionCheckoutService` を新設しない = aigenba は本 Service に置いている）。シグネチャ: `startCheckout(Organization $org, User $user, Plan $plan, string $successUrl, string $cancelUrl, string $attemptToken, ?SignupFundingChoice $funding): CheckoutSessionDto`。`Cache::lock("billing:checkout:start:{$org->id}", 10)->block(5, …)`（**lock 名も verbatim**）。`assertCheckoutReady()` / `isReplayableCheckout()` / `replayCheckout()` / `isUniqueViolation()` / `attemptTokenIsForeign()` を実装。**行 INSERT に `'funding_choice' => $funding?->value` を含める**（T1004 の唯一の入力）。lock closure 先頭で `$now = CarbonImmutable::now()` を 1 回取り、段 2/3/4 の live 判定をすべて共有述語へ通す（C-1） | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:508-717,738,854,930-985` |
| `app/DataTransferObjects/Billing/CheckoutSessionDto.php`（新規） | **verbatim**（`stripeSessionId` / `url` / `intent` / `planCode` + `toArray()` + `@phpstan-type CheckoutSessionShape`） | `/tmp/aigenba/app/DataTransferObjects/Billing/CheckoutSessionDto.php` |
| `app/Services/Billing/Contracts/StripeGatewayInterface.php`（改修） | `createSubscriptionCheckout(Organization $org, string $stripePriceId, string $successUrl, string $cancelUrl, array $metadata, string $idempotencyKey): CreatedCheckoutSession`（戻り値は既存 `CreatedCheckoutSession` = session id の pin が webhook 照合に必須）。`expireCheckoutSession(string $stripeSessionId): string` を追加。**席引数は移植しない**（原則 4） | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php:50,200` / AI-CUE `app/Services/Billing/TicketCheckoutGateway.php` |
| `app/Services/Billing/CashierStripeGateway.php`（改修） | `newSubscription('default',…)->checkout()` をやめ `$org->stripe()->checkout->sessions->create($payload, ['idempotency_key' => $key])` 直呼びへ（**Cashier の `checkout()` ヘルパは per-request idempotency key を公開しない**）。`buildSubscriptionSessionPayload()` を public pure メソッドで切り出し、`subscription_data.metadata.{name,type}='default'` + **`payment_settings.save_default_payment_method='on_subscription'`**（**T1004 の第一候補 `subscription.default_payment_method` が埋まる前提**）を含める。`expireCheckoutSession()` を実装 | `/tmp/aigenba/app/Services/Billing/CashierStripeGateway.php:69-82` / AI-CUE `CashierTicketCheckoutGateway::buildSessionPayload()` |
| `app/Services/Billing/Fakes/FakeStripeGateway.php`（改修） | 新シグネチャに追随。`CreatedCheckoutSession` を決定的に返し、**同一 `idempotencyKey` の再呼び出しで同一 sessionId** を返す。`expireCheckoutSession()` は既定 `'expired'`（テストが `'complete'` / throw を注入可） | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php` / AI-CUE `Fakes/FakeTicketCheckoutGateway.php` |
| `app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php`（改修。P8a 導入分） | **9 本目**として `resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string` を追加（`@return non-empty-string\|null`。docblock「解決順序: `subscription.default_payment_method` → `latest_invoice.payment_intent.payment_method`。双方 null なら null。空文字は返さない」を verbatim）。D31 の狭い gateway 規約は維持（`StripeGatewayInterface` には足さない） | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php:286-294` |
| `app/Services/Billing/CashierAutoRechargeGateway.php`（改修。P8a 導入分） | `resolveSubscriptionPaymentMethod()` = `Cashier::stripe()->subscriptions->retrieve($id, ['expand' => ['latest_invoice.payments.data.payment.payment_intent']])` → **`public static function resolvePaymentMethodFromSubscription(\Stripe\Subscription $subscription): ?string`**（多段解決の純関数として分離 = fixture で分岐を直接固定できる。verbatim） | `/tmp/aigenba/app/Services/Billing/CashierStripeGateway.php:930-975` |
| `app/Services/Billing/Fakes/FakeAutoRechargeGateway.php`（改修。P8a 導入分） | `resolveSubscriptionPaymentMethod()` を決定的に実装（既知 prefix の subscription id に対して対の PM id を返し、未知は **null**（= 解決不能。空文字は返さない））。テストが「解決不能」「例外」を注入できる | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php:412-421` |
| `app/Jobs/Billing/ReuseSubscriptionPaymentMethodJob.php`（新規） | **verbatim**（`public int $tries = 3` / `public int $backoff = 30` / `__construct(public readonly int $organizationId, public readonly string $stripeSubscriptionId)` / `handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge)`）。org 不在 → return / **軽量 guard `! $autoRecharge->isAutoEnablePending($org)` → Stripe retrieve 前に return** / PM 解決 null → `Log::warning('auto-recharge: subscription PM unresolved, skipping reuse', ['organization_id','stripe_subscription_id'])` + return（**PM・customer 情報はログに出さない**）/ それ以外 → `applyReusedPaymentMethod()`。docblock（T710 = 外向き Stripe API を webhook 同期処理から Job へ退避）ごと移植 | `/tmp/aigenba/app/Jobs/Billing/ReuseSubscriptionPaymentMethodJob.php`（gateway 型のみ D31 に合わせ `AutoRechargeGatewayInterface`） |
| `app/Services/Billing/AutoRechargeService.php`（改修。P8a 導入分） | **`applyReusedPaymentMethod(Organization $org, string $paymentMethodId): bool` を追加（verbatim）**: `Assert::stringNotEmpty($paymentMethodId)` → `Cache::lock("billing:auto-recharge:{$org->id}")->block(10, …)` → **lock 内・TX 外で適格性先行確認**（`$config === null \|\| ! autoEnableEligible($config)` → `Log::info('auto-recharge: subscription PM reuse skipped (not eligible)', ['organization_id','reason'])` + `return false` = **Stripe にも DB にも触らない完全 no-op**）→ `gateway->setDefaultPaymentMethod()` → `DB::transaction`（`lockForUpdate` 再取得 → 不適格なら **`RuntimeException`（部分適用の顕在化。silent no-op にしない）** → snapshot + `enabled=true` + `failure_count=0`）→ `LockTimeoutException` は `RuntimeException` で Job retry へ → `$enabledNow` なら `notifyAutoEnabled()`（`report()` で握る）。**`hasRecentAutoRechargeFundedSignup(Organization $org): bool` を追加**: `intent=subscription_start` + `funding_choice=auto_recharge` + `status=completed` + **`pm_reuse_dispatched_at >= now()-{setup_pending_window_minutes}`**（`updated_at`/`completed_at` は使わない = 未決済 completed で窓が誤って開く）。**`settingsFor()` の `setupPending` を (b) 込みへ**: `$setupPending = ! $hasPm && (hasRecentCompletedSetup($org) \|\| ($pendingAutoEnable && hasRecentAutoRechargeFundedSignup($org)))` | `/tmp/aigenba/app/Services/Billing/AutoRechargeService.php:113-120,955-1025,1216-1228`（`intent=SignupFunding` → **`SubscriptionStart`** の 1 点のみ読み替え。`SignupFunding` intent は P2 が原則 4 で非移植のため） |
| `config/billing.php`（改修。P8a 導入分） | `auto_recharge.consent_version` を **`'v1'` → `'v2'`**（D29-b）。**改定履歴コメントを verbatim で持ち込む**（「v1 = T1003 初版（カード登録経路のみ）/ v2 = T1004 有償契約でサブスク決済カードをオートリチャージへ流用することを明示」「提示条件の実質（…カードの取得手段）を変える変更では必ず version を上げること」）。他の値（`default_threshold=5` / `default_max=50` / `max_count=1000` / `max_failures=3` / `pending_expiry_hours=24` / `setup_pending_window_minutes=30`）は**不変** | `/tmp/aigenba/config/billing.php:31-47` |
| `app/Exceptions/Billing/SubscriptionAttemptPlanMismatchException.php`（新規） | 同 token・別 plan の再送。Controller が `ValidationException::withMessages(['plan_code' => …])` = **422**（非 verbatim。根拠は N-1） | — |
| `app/Exceptions/Billing/StaleCheckoutAttemptException.php` / `CheckoutInProgressException.php`（再利用） | **既存クラス**をサブスク側でも使う。新設しない | `/tmp/aigenba/app/Exceptions/Billing/StaleCheckoutAttemptException.php` |
| `app/Http/Requests/Billing/BillingCheckoutRequest.php`（改修） | `subscription_attempt_token => ['required','ulid']`（`Str::ulid()` は大文字 Crockford base32 のため lowercase regex 不可 = aigenba のコメントごと移植）。**T1004**: `funding_choice => ['nullable','string', Rule::in(array_map(fn (SignupFundingChoice $c): string => $c->value, SignupFundingChoice::cases()))]` / `consent_version => ['required_if:funding_choice,'.SignupFundingChoice::AutoRecharge->value, 'string','max:16', Rule::in([$this->currentAutoRechargeConsentVersion()])]` + `messages()` の 2 文言 verbatim（`'consent_version.required_if' => '自動購入への同意が必要です。'` / `'consent_version.in' => '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。'`）。**`pack_count` / `topup_count` / `campaign_code` / `seats` は移植しない**（原則 4）。`ProhibitsProtectedKeys` は据置 | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:624-652`（`funding_choice` は AI-CUE の単一契約 route が Plans 経路（funding 非提示）と Onboarding 経路（funding 2 択 = P8a）の両方を宿すため **`required` → `nullable`**。null = 従来の契約 checkout = 流用しない） |
| `app/Enums/Billing/BillingFeedbackKind.php`（新規） | **verbatim 5 case**（`PurchaseReceived` / `PurchaseProcessing` / `PurchaseAlreadyReceived` / `CheckoutRetryRequired` / `PortalReturned`） | `/tmp/aigenba/app/Enums/Billing/BillingFeedbackKind.php` |
| `app/DataTransferObjects/Billing/BillingFeedbackDto.php`（新規） | **verbatim**（`private __construct` + `simple(kind, message)` + `toArray(): BillingFeedbackShape` + `@phpstan-type SimpleBillingFeedbackKind`） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingFeedbackDto.php` |
| `app/DataTransferObjects/Billing/BillingContactDto.php`（新規） | `email` / `name` / `fallbackEmail`（owner email）+ `toArray(): BillingContactShape` | `/tmp/aigenba/app/Models/Organization.php:119-138` の fallback 意味論 |
| `app/Http/Controllers/Billing/BillingController.php`（改修） | `index` に private `resolveBillingFeedback(Request, Organization): ?BillingFeedbackDto`（**verbatim**）+ **`resolveAutoRechargeLanding(Request, Organization): ?RedirectResponse`（T1004 の `?session_id` 分岐のみ）** を追加。`checkout` を新 `startCheckout()` へ配線（404 → 認可 → **`recordPreConsent`** → 開始の順）。`portal` の return URL を `route('billing.index', ['portal' => 1])` へ。`plans` に `subscriptionAttemptToken` を載せる。`updateBillingContact` を追加 | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:195,235-265,318-393,540-610,657-684` |
| `app/Services/Billing/StripeWebhookProcessor.php`（改修） | `CheckoutSessionCompleted` arm に `settleSubscriptionCheckout()` を**追加**（遷移条件は C-2 の 1 定義のみ）+ **T1004 dispatch 分岐**（`funding_choice=auto_recharge` + `payment_status ∈ {paid,no_payment_required}` + `subscriptionIdFrom($object) !== null` → `forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save()` → `ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId)`）+ private `subscriptionIdFrom(array $object): ?string`（`$object['subscription']` が array なら `['id']` を取る verbatim）。既存 `grantPurchasedTickets()` は**無改変** | `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:447-470,508-526,1422-1433,1528-1541` |
| `app/DataTransferObjects/Billing/BillingDashboardDto.php`（改修） | additive: `feedback: BillingFeedbackShape\|null` / `billingContact: BillingContactShape`。**`autoRecharge: AutoRechargeShape` は P8a 導入済み・無変更**（`setupPending` / `pendingAutoEnable` の shape は P8a で aigenba verbatim） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingDashboardDto.php` |
| `app/DataTransferObjects/Billing/BillingPlansPageDto.php`（改修） | additive: `subscriptionAttemptToken: string`（render ごとの ULID） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingPlansDto.php` |
| `app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php`（改修。P3 導入分） | additive: `subscriptionAttemptToken: string`（P3 本文が「`attemptToken` 同梱（P9）」と明示委譲済み。T1004 の POST が冪等 token を必要とする） | `/tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte:34`（props `attemptToken`） |
| `resources/js/pages/Onboarding/Checkout.svelte`（改修。P3 導出 + P8a の funding 2 択） | 有償プランの submit body を `{plan_code, subscription_attempt_token, funding_choice, ...(funding_choice==='auto_recharge' ? {consent_version: consentTerms.consentVersion} : {})}` にして `billing.checkout` へ POST（aigenba の `signup-checkout` POST 相当）。**同意アクションは実行ボタンのクリック**（コメント verbatim）。**disabled でブロックしない**（禁止事項 #8 / D4） | `/tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte:190-251` |
| `app/Models/Organization.php`（改修） | `checkoutSessions(): HasMany<BillingCheckoutSession, $this>` を追加（feedback / T1004 着地の org スコープ引きに必須）。`implements CipherSweetEncrypted` + `use UsesCipherSweet` + `configureCipherSweet()`。`routeNotificationForMail()` を `billing_contact_email` 正本 → owner email fallback へ。**両列とも `$fillable` 外** | `/tmp/aigenba/app/Models/Organization.php:119-138`（fallback 意味論のみ） |
| `database/migrations/2026_07_xx_xxxxxx_add_billing_contact_columns_to_organizations_table.php`（新規） | `billing_contact_email` / `billing_contact_name` を **`text()->nullable()`**（CipherSweet ciphertext のため `string(255)` を使わない）。**blind index 列は作らない**（共有 `blind_indexes` morph テーブル） | `/tmp/aigenba/database/migrations/2026_04_14_011301_add_cashier_columns_to_organizations_table.php:16-17`（**列型は非 verbatim**） |
| `app/DataTransferObjects/Billing/UpdateBillingContactData.php`（新規） | **verbatim**（`fromRequest()` で `EmailNormalizer::normalize()` + `Assert::stringNotEmpty()`、name は空文字を null へ畳む） | `/tmp/aigenba/app/DataTransferObjects/Billing/UpdateBillingContactData.php` |
| `app/Http/Requests/Billing/UpdateBillingContactRequest.php`（新規） | `billing_contact_email => ['required','email:rfc','max:255']` / `billing_contact_name => ['nullable','string','max:255']` + `protectedKeyMissingRules()`（**`array_merge`** = AI-CUE trait docblock の保護キー後勝ち merge） | `/tmp/aigenba/app/Http/Requests/Organizations/Billing/UpdateBillingContactRequest.php` |
| `app/Actions/Billing/UpdateBillingContactAction.php`（新規） | **verbatim**（`DB::transaction` 内で両列代入 → **`save()` 前に `isDirty('billing_contact_email')` 判定** → `save()` → email dirty 時のみ `BillingCustomerSynchronizer::dispatchFor()`） | `/tmp/aigenba/app/Actions/Billing/UpdateBillingContactAction.php` |
| `resources/js/components/features/billing/BillingContactForm.svelte`（新規） | 請求先メール / 宛名の更新フォーム。`@lucide/svelte` の `Receipt`、DS token のみ | `/tmp/aigenba/resources/js/pages/Billing/_helpers/BillingContactForm.svelte` |
| `resources/js/pages/Billing/Index.svelte`（改修） | `page.feedback` バナー（`kind` で variant 決定・**raw query を UI が見ない**）と `BillingContactForm` を T071 primitive 配下に追加。**`?highlight=auto-recharge` で P8a の `AutoRechargeCard` へスクロール/強調**（T1004 着地の主役化） | `/tmp/aigenba/resources/js/pages/Billing/Index.svelte` |
| `resources/js/pages/Billing/Plans.svelte`（改修） | POST body を `{plan_code}` → `{plan_code, subscription_attempt_token}` へ（**funding_choice は載せない** = 契約変更経路に funding 提示は無い） | `/tmp/aigenba/resources/js/pages/Billing/Plans.svelte:117-119` |
| `routes/web.php`（改修） | `PATCH /billing/contact` → `billing.contact.update`（課金ゲート allowlist 内・**route parameter なし** = current-org スコープ）。**T1004 は既存 `billing.checkout` に相乗り**（新 route なし） | — |

**列を足す / 足さない点**: `billing_checkout_sessions` は **P2 で作成済み・P8a が `SetupPaymentMethod` 行の writer として先行**。P9 は **additive 2 列（`funding_choice` / `pm_reuse_dispatched_at`）のみ**を足す（P2 所管テーブルへの additive は許容）。**`expires_at` 列は追加しない**（live 判定は `status=Pending` + `created_at >= now()-1day` = `isLivePending()` が単一出典）。`organizations.billing_contact_*` を含め **P9 の migration は 2 本**。

**非スコープ（P9 で持ち込まない）**: `SignupFunding` / `CreditPurchase` intent（対象機能が無い = 原則 4。T1004 は既存 `SubscriptionStart` + `funding_choice` 列で成立する）/ `seats`・`pack_count`・`topup_count`・`applied_campaign_id`・`applied_trial_days`・`credit_count`・`unit_amount`（P2 が原則 4 で非移植と決定済み）/ `?setup_session_id` 着地・`autoRechargeAutoConsent`（aigenba T1002 G4 = **カード登録 Checkout の着地** = D29(i) の「P8a = free（personal）経路の全部」所管。P9 へ移譲された T1004 の列挙に含まれない）/ `resolveOnboardingContinue`（`OnboardingReturnResolver` は P7 所管で `?session_id` 非依存に配線済み）/ `checkout.session.expired` の購読（`created_at` 閾値 + 日次 sweeper で決定的に扱えるため Stripe 照会を増やさない）/ `billing_contact_email` の NOT NULL 化・backfill（fallback が生きている限り不要）。

#### 波及変更

- **`BillingCheckoutSession` の writer 構成**: P8a（`intent=setup_payment_method`）+ P9（`intent=subscription_start`）の 2 writer になる。P9 の冪等マシンは **クエリを常に `intent=subscription_start` でスコープ**するため、段 2（同 token）/ 段 3（同 plan live pending dedup）/ 段 4（別 plan expire）に P8a の setup 行が混入しない（`UNIQUE(organization_id, intent, attempt_token)` の `intent` 軸が token 空間を分ける）。逆に **日次 sweeper は intent で絞らない**（verbatim）ため、P8a の stale な setup pending も `Expired` へ収束する。
- **live 判定の単一出典化（C-1）が触る P2 資産**: `BillingCheckoutSession`（述語 3 本を追加）/ `BillingAccess::state()`（**挙動不変のリファクタ**）。P2 の migration・Factory・`BillingAccessStateTest` の期待は**変更しない**。P8a が固定した不変条件（「setup 行は `state()` の `PendingCheckout` に落ちない」）も**無変更で green**（P9 は `state()` の分岐を変えない）。**P9 が書く `subscription_start` の live pending 行は `PendingCheckout` の正当な対象**であり、これは P2 の分岐表どおりの意味である。
- **P8a 資産への追記（P8a の既存挙動は不変）**: `AutoRechargeService`（**2 メソッド追加** + `settingsFor` の `setupPending` に (b) 条件を OR で追加。既存 (a) 条件と `pendingAutoEnable` の定義は不変）/ `Contracts\AutoRechargeGatewayInterface`（9 本目）+ `CashierAutoRechargeGateway` + `FakeAutoRechargeGateway`（`FakeExternalsServiceProvider` の bind は P8a のまま = **新 bind なし**）/ `config/billing.php`（`consent_version` v1 → v2）。**`AutoRechargeSettingsDto` は無変更**（P8a が aigenba verbatim の shape で導入済み = `pendingAutoEnable` / `setupPending` を保持）。
- **`consent_version='v2'` の移行効果（data migration なし）**: P8a 期に記録された v1 同意行は `reconsentRequiredFor()` が **自動失効**と判定し、`autoEnableEligible()` = false → `pendingAutoEnable` / `setupPending` が false → 自動有効化は起きず**再同意 UI（P8a の `AutoRechargeCard`）へ落ちる**。**既に `enabled=true` の org は `requiresReconsent=true` になり自動購入が停止する**（fail-closed = aigenba の版管理契約そのもの。原則 3 により値も文言も verbatim）。backfill・救済スクリプトは作らない。
- **既存 daily バッチへの相乗り**: `ReconcileSubscriptionSchedules`（`routes/console.php:38` で daily 登録済み）に `expireStaleCheckouts()` を追加。**新 command / 新 Schedule 行なし**。
- **TypeScript 型定義** `resources/js/types/billing.ts`:
  - 追加 `BillingFeedbackKind = 'purchase_received'|'purchase_processing'|'purchase_already_received'|'checkout_retry_required'|'portal_returned'`（**5 値**。PHP の `SimpleBillingFeedbackKind` と exact 対）/ `BillingFeedbackShape { readonly kind: BillingFeedbackKind; readonly message: string }` / `BillingContactShape { readonly email: string | null; readonly name: string | null; readonly fallbackEmail: string | null }`。
  - `BillingDashboardProps` に `feedback` / `billingContact` を追加。`BillingPlansPageProps` に `subscriptionAttemptToken: string` を追加。`AutoRechargeShape` は **P8a のまま無変更**。
  - `resources/js/types/onboarding.ts`（P3 産出）の `OnboardingCheckoutShape` に `subscriptionAttemptToken: string` を追加（`consentTerms` は P8a 追加済み）。`SignupFundingChoice` の TS literal union は **P8a 産出を再利用**（P9 で再定義しない）。
- **Inertia props**: `Billing/Index` / `Billing/Plans` / `Onboarding/Checkout` の `page` shape 拡張（DTO `toArray()` 経由。`response()->json()` 直書きなし）。新規ページなし。
- **DTO**: 新規 `CheckoutSessionDto` / `BillingFeedbackDto` / `BillingContactDto` / `UpdateBillingContactData`。改修 `BillingDashboardDto` / `BillingPlansPageDto` / `OnboardingCheckoutDto`。既存 `CreatedCheckoutSession` をサブスク側でも再利用。
- **Factory**: P2 の `BillingCheckoutSessionFactory` に **`initiatedBy(User $user)` / `withAttempt(string $token, string $planCode)` / `stale()` / `fundingAutoRecharge()` / `pmReuseDispatched(?CarbonImmutable $at = null)`** を追加。`OrganizationFactory` に `withBillingContact(?string $email = null, ?string $name = null)` を追加（テストデータ手組み禁止）。P8a の `TicketAutoRechargeFactory`（同意 4 列の state）を**そのまま再利用**する。
- **config**: `config/cashier.php` の購読集合は既存導出のまま（**case を増やさない** = `CheckoutSessionCompleted` は既存。`WebhookEventSubscriptionInvariantTest` は無変更で green）。**T1004 は新 webhook event を購読しない**。
- **テストファイル（更新・削除しない）**: `tests/Feature/Billing/BillingPageTest.php`（Index props に `feedback` / `billingContact`）/ `BillingPlansPageTest.php`（`subscriptionAttemptToken`）/ `PortalConfigurationTest.php`（`?portal=1`）/ `ReconcileSubscriptionSchedulesTest.php`（stale expire ケース追加）/ `BillingCheckoutSessionModelTest.php`（live 述語 + 新 2 列の cast/fillable ケース追加）/ `WebhookIdempotencyTest.php`・`WebhookEventSubscriptionInvariantTest.php`（期待不変）/ `TicketPurchaseWebhookTest.php`・`TicketCheckoutTest.php`（**無改変で green**）/ `BillingAccessStateTest.php`（P2 の期待不変 + writer 経由ケース追加）/ **P8a 産出の `AutoRechargeServiceTest`・`AutoRechargePreConsentTest`・`AutoRechargeEndpointTest`（`consent_version` の期待を `'v1'` → `'v2'` へ更新。`setupPending` の既存 (a) ケースは不変）**/ `tests/js/support/autoRechargeProps.ts`（**無変更**）/ `tests/js/pages/Billing/Index.test.ts`・`Plans.test.ts`・`OnboardingCheckout.test.ts`。
- **Architecture テストへの影響**: `MassAssignmentSafetyTest`（`billing_contact_*` / `pm_reuse_dispatched_at` は `$fillable` 外）/ `FormRequestProhibitedKeyTest`（新 FormRequest）/ `ManageRouteAuthGuardTest`（`billing.contact.update`）/ `BillingSyncDispatchInvariantTest`（`dispatchFor` の呼び出し元に `UpdateBillingContactAction` を追加）/ **P8a の `WebhookAsyncDispatchTest` 相当（webhook 同期処理から外向き Stripe API を撃たない）に `settleSubscriptionCheckout` を追加**（T1004 の Stripe 呼び出しは Job 側のみ）。新規 3 本は「テスト計画」§。

#### 主要な契約

**ルート**（課金ゲート allowlist 内・route parameter を持たない current-org スコープ。current org 不在 / 非所属は認可より前に 404）

```
GET   /billing            billing.index           BillingController@index      … 既存 (?session_id / ?portal / ?replayed / ?retry / ?highlight を解釈)
GET   /billing/plans      billing.plans           BillingController@plans      … 既存 (subscriptionAttemptToken を発行)
POST  /billing/checkout   billing.checkout        BillingController@checkout   … 既存 (body: {plan_code, subscription_attempt_token, funding_choice?, consent_version?})
POST  /billing/portal     billing.portal          BillingController@portal     … 既存 (return URL に ?portal=1)
PATCH /billing/contact    billing.contact.update  BillingController@updateBillingContact  ← 新規 (manageBilling)
```

**DB**

```sql
-- billing_checkout_sessions (P2 で作成。P8a が intent='setup_payment_method' 行の writer として先行済み。
--  P9 は intent='subscription_start' 行の writer + 下記 2 列の additive 追加のみ)
id, organization_id FK cascade, initiated_by_user_id FK users nullOnDelete,
intent varchar(32), plan_code varchar(32) null,
stripe_session_id varchar UNIQUE, idempotency_key varchar(128) UNIQUE,
attempt_token varchar null, checkout_url varchar(2048) null,
status varchar(16) default 'pending', completed_at timestamp null, timestamps
UNIQUE (organization_id, intent, attempt_token)  -- 名: billing_checkout_sessions_org_intent_attempt_unique
INDEX  (organization_id, intent, status) / INDEX (organization_id, intent, initiated_by_user_id, id)
+ funding_choice varchar(16) null            -- P9 additive (T1004 の唯一の入力。SignupFundingChoice の値)
+ pm_reuse_dispatched_at timestamp null      -- P9 additive (PM 流用 Job dispatch の永続マーカー)

-- organizations (additive)
billing_contact_email text null,  billing_contact_name text null   -- CipherSweet ciphertext
```

##### C-1: live 判定の単一出典（`state()` と `startCheckout()` が同一閾値を共有する契約）

**契約**: 「pending 行が live か」の判定は **`BillingCheckoutSession` の述語だけが定義する**。閾値 `now()-1day`（aigenba の `subDay()` 値。Stripe Checkout Session の 24h 自動 expire と一致）は `staleThresholdAt()` の**1 箇所にしか literal として現れない**。

```php
// App\Models\Billing\BillingCheckoutSession — 閾値の単一出典
/** live/stale の境界。Stripe Checkout Session の 24h 自動 expire と一致させる (aigenba: subDay)。 */
public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable
{
    return $now->subDay();
}

/** live pending (= 決済待ちとして生きている) か。created_at が null の行は live 扱い (P2 state() の else 分岐と同一)。 */
public function isLivePending(CarbonImmutable $now): bool
{
    return $this->status === CheckoutSessionStatus::Pending->value
        && ($this->created_at === null
            || $this->created_at->greaterThanOrEqualTo(self::staleThresholdAt($now)));
}

/** live pending かつ checkout_url 生存 = 復帰可能な進行中 Checkout。 */
public function isReplayablePending(CarbonImmutable $now): bool
{
    return $this->isLivePending($now)
        && $this->checkout_url !== null && $this->checkout_url !== '';
}
```

**共有方法（この 4 経路が上の述語 / 閾値だけを使う。独自の日付比較を書かない）**

| 経路 | 使い方 | 効果 |
|---|---|---|
| `BillingAccess::state()`（P2） | `$now = CarbonImmutable::now()` を 1 回取り、pending 行を `$row->isLivePending($now)` で分類（live → `PendingCheckout` / stale → `hasExpired` 材料）。**read 経路で DB 書込をしない**（P2 verbatim） | 挙動不変（同じ `subDay()`） |
| `SubscriptionService::startCheckout()` 段 2（同 token） | `isReplayableCheckout($row, $now)` = `status === Completed` **または** `$row->isReplayablePending($now)` | **stale pending の同 token 再送が死んだ `checkout_url` へ収束せず `StaleCheckoutAttemptException` → `?retry=1`** |
| 同 段 3 / 段 4（live pending dedup / 別 plan expire。**`intent=subscription_start` スコープ**） | クエリに `->where('created_at', '>=', BillingCheckoutSession::staleThresholdAt($now))` を付す（SQL 側 live filter） | **stale pending が dedup に hit しない = 新 token で新規 Checkout が成立する** |
| `ReconcileSubscriptionSchedules::expireStaleCheckouts()`（daily） | `->where('created_at', '<', BillingCheckoutSession::staleThresholdAt(CarbonImmutable::now()))->update(['status' => Expired])`（**intent で絞らない** = P8a の setup 行も収束させる） | stale 行を実 DB でも `Expired` へ収束 |

**成立する同値（テスト 14 / 21 で機械固定）**: 任意の org・任意時刻で
`state($org) === PendingCheckout` ⇔ `startCheckout()` が新規 Checkout を作らない（同 plan は段 3 の dedup、別 plan は段 4 の expire 経由）。
`state($org) === ExpiredCheckout`（stale pending のみが理由） ⇒ **新 token の `startCheckout()` は新規 Checkout を作れる**。
**「2 日後に新 token で新規 Checkout」が成立する**（日次 sweeper の実行有無に依存しない）。

##### 冪等状態機械の契約（要件 1-9）

| # | 契約 | 実現 |
|---|---|---|
| 1 | **`organization_id` + `subscription_attempt_token` の UNIQUE** | `UNIQUE(organization_id, intent, attempt_token)`（P2）に **`intent='subscription_start'` を pin** して成立させる。`intent` はサブスク token 空間と **P8a のカード登録 token 空間（`setup_payment_method` / `auto-recharge-setup:{token}`）** を分ける軸であり、チケット token は**別テーブル**のため混線しない |
| 2 | **`initiated_by_user_id` による actor scope** | 行作成時に `initiatedBy()->associate($user)` で**必ず非 null 記録**。**live pending dedup は org-wide のまま**（要件 4）— subscription は org 単位の singleton であり、actor scope にすると同 org の 2 人が同時に live Checkout を持てて**二重 subscription を許す**。actor scope が効くのは **token の所有者判定（要件 7）のみ** |
| 3 | **`pending` / `completed` / `failed` / `expired`** | P2 の `CheckoutSessionStatus`（verbatim）。遷移は C-2 の 1 定義のみ |
| 4 | **同 token 再送は既存 Checkout URL へ収束** | 同 token 行が `isReplayableCheckout($row, $now)` なら `replayCheckout()`: live pending → **保存済み `checkout_url`** / `Completed` → `url=null`。非 replayable（stale pending / `Failed` / `Expired`）→ `StaleCheckoutAttemptException`。**新規 Checkout を作らない** |
| 5 | **Stripe idempotency key 対応** | Stripe へ渡す key は **`'sub_start:'.$attemptToken`**（aigenba verbatim の名前空間）。DB `idempotency_key` 列には**同値を保存**し UNIQUE を張る |
| 6 | **plan code 不一致の token 再利用は 422** | 同 token 行の `plan_code !== $plan->code` → `SubscriptionAttemptPlanMismatchException` → **422**（`assertInvalid(['plan_code'])`）。**`isReplayableCheckout()` より前に判定する** |
| 7 | **他 org・他 user の token は 404** | `attemptTokenIsForeign(string $token, Organization $org, User $user): bool` = `intent=subscription_start` かつ同 `attempt_token` の行が**存在し、かつ (org, initiated_by_user_id) が一致しない**とき true。Controller が **`Gate` より前に 404**（存在オラクル封じ） |
| 8 | **success / cancel webhook との競合と再送** | `settleSubscriptionCheckout()` の遷移は C-2 の 1 定義（`Completed` 終局 = 再送 no-op / **`Failed`・`Expired` からの遅延成功は受理**）。cancel は Stripe から `completed` が来ない → 行は `Pending` のまま → 1 日経過で `state()` が `ExpiredCheckout` |
| 9 | **tenant キーを payload から受け取らない Request 契約** | `BillingCheckoutRequest` / `UpdateBillingContactRequest` が `ProhibitsProtectedKeys`。`organization_id` / `initiated_by_user_id` / `plan_id` は `missing` = 存在するだけで **422** |

```php
// App\Services\Billing\SubscriptionService（新 Service を作らない）
/**
 * @throws SubscriptionAttemptPlanMismatchException|StaleCheckoutAttemptException|CheckoutInProgressException|StripePriceNotSyncedException
 */
public function startCheckout(
    Organization $org, User $user, Plan $plan,
    string $successUrl, string $cancelUrl, string $attemptToken,
    ?SignupFundingChoice $funding,   // T1004: 行の funding_choice に記録する (null = 従来の契約 checkout)
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

`startCheckout()` の手順（`Cache::lock("billing:checkout:start:{$org->id}", 10)->block(5, …)` 内。`LockTimeoutException` は fail-closed で `CheckoutInProgressException('直前の操作が進行中です。数秒お待ちください。')`）:

| # | 段 | 挙動 |
|---|---|---|
| 0 | 事前 assert + 基準時刻 | `Assert::stringNotEmpty($attemptToken, '契約手続きトークンが不正です')` / `assertCheckoutReady($org)` / `assertPriceSynced($basePrice)` / `assertStripeBillablePlan($plan)`。**lock closure 先頭で `$now = CarbonImmutable::now()` を 1 回だけ取る** |
| 1 | 既存 subscription guard | `$org->subscription('default')` が `valid()` なら `'既に有効なサブスクリプションがあります。プラン変更をご利用ください。'`（`Assert::true`） |
| 2 | **同 token 行**（`org` + `intent=subscription_start` + `attempt_token`。`latest('id')->first()`） | `plan_code !== $plan->code` → `SubscriptionAttemptPlanMismatchException`（**要件 6**）<br>`isReplayableCheckout($row, $now)` → `replayCheckout()`（**要件 4**）<br>それ以外（**stale pending 含む** / `Failed` / `Expired`）→ `StaleCheckoutAttemptException('契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。')` |
| 3 | **同 plan の live pending dedup**（`org` + `intent=subscription_start` + `plan_code` + `status=Pending` + **`created_at >= staleThresholdAt($now)`**。**org-wide**） | `CheckoutSessionDto(url: null, …)` → Controller が `back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。')` |
| 4 | **別 plan の live pending を expire**（同じ live filter・同じ intent スコープ） | `gateway->expireCheckoutSession()` が throw → `CheckoutInProgressException('前回の決済セッションの整理に失敗しました。 数分後に再試行してください。')` / `'complete'` → `CheckoutInProgressException('直前の決済が処理中です。数分お待ちください。')` / それ以外 → 行を `Expired` にして続行。**stale な別 plan 行は Stripe 側で既に expire 済みのため照会せず放置** |
| 5 | Stripe 作成 → DB 記録 | `gateway->createSubscriptionCheckout(…, metadata: ['purpose' => 'subscription_start', 'org_ref' => (string) $org->id, 'plan_code' => $plan->code], idempotencyKey: 'sub_start:'.$attemptToken)` → `DB::transaction` で行 INSERT（`intent` / `plan_code` / **`funding_choice` = `$funding?->value`** / `stripe_session_id` / `idempotency_key` / `attempt_token` / `checkout_url` / `status=Pending` / `initiated_by_user_id`） |
| 6 | `UniqueConstraintViolationException` の re-read 収束 | `isUniqueViolation()`（SQLSTATE `23000`/`23505` + index 名 `billing_checkout_sessions_org_intent_attempt_unique` / SQLite は構成列名で一致）以外は rethrow。該当時は `(org, intent, attempt_token)` を再読込 → `isReplayableCheckout($row, $now)` なら `replayCheckout()` / でなければ `StaleCheckoutAttemptException`（**500 にしない**） |

##### C-2: webhook 状態遷移（要件 3 / 8。**遷移条件はこの 1 定義のみ**）

**遷移条件（唯一）**: `settleSubscriptionCheckout()` は **`status !== Completed` の行だけ**を、`checkout.session.completed` payload の `payment_status` が確定した結果へ遷移させる。`Completed` は**終局**（再送・後続 payload は no-op = 冪等）。

- `payment_status ∈ {paid, no_payment_required}` → `Completed` + `completed_at = now()`
- `payment_status === 'unpaid'` → `Failed`
- 上記以外（null 等）→ **遷移しない**（受理のみ）

```
Pending   ──paid|no_payment_required──▶ Completed (+completed_at)
Failed    ──paid|no_payment_required──▶ Completed   … 遅延成功の受理 (非同期決済の後着)
Expired   ──paid|no_payment_required──▶ Completed   … 遅延成功の受理 (段 4 / sweeper で expire 済みの行)
Pending   ──unpaid──────────────────▶ Failed
Expired   ──unpaid──────────────────▶ Failed
Completed ──(任意の payload)─────────▶ Completed    … 終局 = no-op (冪等)
Pending   ──(段 4 の明示 expire / 日次 sweeper)──▶ Expired
```

cancel / 離脱は**遷移を持たない**。`BillingAccess::state()` が C-1 の述語で `PendingCheckout` / `ExpiredCheckout` と読む（**read 経路で DB 書込をしない**）。**遅延成功を受理する根拠**: `Expired` / `Failed` は AI-CUE 側の都合で付く**ローカルな見立て**であり、決済の終局は Stripe が持つ。金銭の付与は `invoice.paid` が真実源（D7）なので本遷移は feedback と冪等の忠実性のみを回復する。

```php
// StripeWebhookProcessor::settleSubscriptionCheckout(array $payload): void
// (1) purpose ガード: metadata.purpose !== 'subscription_start' → 受理のみ / mode !== 'subscription' → 受理のみ
//     (既存 grantPurchasedTickets の 'ticket_purchase' + mode=payment ガード / P8a の mode=setup 分岐と相互排他)
// (2) 真実源は自 DB 行。行不在 → throw = retryable failure
// (3) tenant キー不信: payload の customer / metadata.org_ref は照合のみ (不一致は throw)。org 解決には使わない
// (4) 遷移は C-2 の 1 定義:
//     if ($local->status === CheckoutSessionStatus::Completed->value) { return; }   // 終局 no-op
//     $status = $this->stringAt($payload, 'data.object.payment_status');
//     if (in_array($status, ['paid', 'no_payment_required'], true)) {
//         $local->forceFill(['status' => Completed->value, 'completed_at' => CarbonImmutable::now()])->save();
//     } elseif ($status === 'unpaid') {
//         $local->forceFill(['status' => Failed->value])->save();
//     }                                                                              // それ以外は受理のみ
// (5) T1004 dispatch (下記 C-3)。チケット・プランの付与も plan_code 同期もここでは一切行わない (D7)。
```

##### C-3: T1004 サブスク決済カード流用（D29(ii) で P8a から移譲。aigenba verbatim）

**入力**: P9 が書く `intent=subscription_start` + `funding_choice='auto_recharge'` の `BillingCheckoutSession` 行（= Onboarding/Checkout の funding 2 択で `auto_recharge` を選んだ有償契約）。**事前同意（`recordPreConsent`）は checkout POST 時に記録済み**（`enabled=false` + 同意 4 列）。**適格性の最終判定は Job → `applyReusedPaymentMethod` の fail-closed が担う**。

**(1) dispatch 条件（webhook 同期処理。外向き Stripe API は撃たない = T710 invariant）**

```php
// StripeWebhookProcessor::settleSubscriptionCheckout の末尾 (C-2 の遷移で Completed になった呼び出しのみ)
$paymentStatus  = $this->stringAt($payload, 'data.object.payment_status');
$subscriptionId = $this->subscriptionIdFrom($object);        // $object['subscription'] が array なら ['id']
if ($local->funding_choice === SignupFundingChoice::AutoRecharge->value
    && ($paymentStatus === 'paid' || $paymentStatus === 'no_payment_required')
    && $subscriptionId !== null) {
    // dispatch の事実を session に永続化する — setupPending / 着地 flash の「自動的に有効になります」
    // 表示を決済確定済みの契約に限定する出典 (未決済 completed への伝播防止)。
    $local->forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save();
    ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId);
}
```

**決済未確定（`payment_status` が `paid` / `no_payment_required` 以外）では dispatch しない**（決済未確定の契約カードでオートリチャージを有効化しない = aigenba の top-up 付与ガードと同一基準）。**再送は C-2 の終局 no-op に従い dispatch されない**（marker の 30 分窓が再送で延びない。aigenba は再送でも dispatch するが Job の `isAutoEnablePending` guard により**結果は同一**であり、差分は窓の延長有無のみ = C-2 の一意化（N-5）から機械的に導かれる帰結）。

**(2) `ReuseSubscriptionPaymentMethodJob`（`tries=3` / `backoff=30`。verbatim）**: org 不在 → return / **`! isAutoEnablePending($org)` → Stripe retrieve 前に return**（不要な外部 API の排除）/ `resolveSubscriptionPaymentMethod()` が null → `Log::warning`（org id + subscription id のみ）+ return（**詰まない**: 請求ページのカード登録 CTA で回復できる）/ それ以外 → `applyReusedPaymentMethod($org, $pm)`。

**(3) `AutoRechargeService::applyReusedPaymentMethod(Organization $org, string $paymentMethodId): bool`（verbatim）**

- setup 経路（`applySetupCompletion`）との違い: **ユーザーは「オートリチャージ用のカード登録」を明示していない**ため、**適格性（`autoEnableEligible`）を先に確認し、不適格なら customer default PM もローカル snapshot も一切変更しない完全 no-op**（fail-closed。`Log::info(reason: no_config|not_eligible)`）。
- 適格 → `gateway->setDefaultPaymentMethod()`（Cashier 冪等実装。副作用は customer の `invoice_settings.default_payment_method` = **v2 同意文言で開示済み**）→ `DB::transaction` で `lockForUpdate` 再取得 → snapshot + `enabled=true` + `failure_count=0` → `return ! $wasEnabled`。
- **TX 内で適格性が失われていたら `RuntimeException`**（「Stripe だけ変更済みの部分適用」を silent no-op にせず顕在化。Job retry で収束 / 継続不適格なら `failed_jobs` で検知）。
- `updateSettings` / `applySetupCompletion` / `recordPreConsent` / `executeAttempt` と**同一 org lock**（`billing:auto-recharge:{org}`）で直列化 = lock 保持中に適格性が変化する経路は構造的に存在しない。`LockTimeoutException` は `RuntimeException` で Job retry へ（握り潰さない）。
- `enabledNow` のときのみ `notifyAutoEnabled()`（通知失敗は `report()` で握り、Job を失敗させない = `applySetupCompletion` と同型）。

**(4) 「処理中」表示の窓（`settingsFor().setupPending`。P8a の (a) に (b) を OR で追加）**

```php
$setupPending = ! $hasPm && (
    $this->hasRecentCompletedSetup($org)                                   // (a) P8a: カード登録 Checkout 完了
    || ($pendingAutoEnable && $this->hasRecentAutoRechargeFundedSignup($org))  // (b) T1004: PM 流用 Job の収束待ち
);
```
`hasRecentAutoRechargeFundedSignup()` = `intent=subscription_start` + `funding_choice=auto_recharge` + `status=completed` + **`pm_reuse_dispatched_at >= now()->subMinutes(config('billing.auto_recharge.setup_pending_window_minutes'))`**（既定 30）。**基準は `pm_reuse_dispatched_at`**（`updated_at` / `completed_at` は完了後の別更新・未決済 completed で窓が誤って開くため使わない）。**(b) は `pendingAutoEnable=true` のときだけ**（v1 失効・再同意が必要な org で 30 分間カード登録 CTA / 再同意導線を隠さない）。

**(5) 着地 flash（`resolveAutoRechargeLanding`。`?session_id` 分岐のみ）**

`?session_id` を `$organization->checkoutSessions()` の **org スコープ**で引き、`intent=subscription_start` + `status=completed` + `funding_choice=auto_recharge` を検証できたときだけ **`billing.index?highlight=auto-recharge` への 303 + `with('info', …)`** へ変換する（それ以外の `session_id` は従来どおり `resolveBillingFeedback` に委ねる）。文言は 2 分岐（verbatim）:

- `pm_reuse_dispatched_at !== null && isAutoEnablePending($org)` → `'お支払いを受け付けました。オートリチャージは、ご契約のお支払いカードで自動的に有効になります。反映されない場合は、この画面から設定できます。'`
- それ以外（同意失効・未決済 completed 等） → `'お支払いを受け付けました。オートリチャージの設定はこの画面から確認できます。'`（**確定表現を避けた fail-closed な誘導文言**）

**(6) 同意版（D29-b）**: `config('billing.auto_recharge.consent_version') = 'v2'`。`BillingCheckoutRequest` が **checkout 開始前に現行版との完全一致を検証**（不一致・欠落は 422 = `recordPreConsent` にも Stripe にも到達しない）。Controller は `Gate::authorize('manageBilling')` の後・`startCheckout()` の前に `recordPreConsent($org, $user, new AutoRechargeConsentDto($consentVersion))` を呼ぶ（`CheckoutInProgressException` → `back()->with('error', …)`）。**Checkout が後段で失敗・放棄されても同意 row は無害**（`enabled=false` = 課金は一切発生しない）。

##### Controller の実行順（要件 7 = セキュリティ不変条件 #2「不整合は認可より前に 404」）

```php
public function checkout(BillingCheckoutRequest $request, SubscriptionService $subscriptions, AutoRechargeService $autoRecharge): SymfonyResponse|RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $user = $request->user();  Assert::isInstanceOf($user, User::class);
    $attemptToken = $request->validated('subscription_attempt_token');  Assert::string($attemptToken);

    // (1) 他 org / 他 user の token は 404 (403 にしない = 存在オラクル封じ)。Gate より前。
    abort_if($subscriptions->attemptTokenIsForeign($attemptToken, $organization, $user), 404);
    // (2) 認可
    Gate::authorize('manageBilling', $organization);
    // (3) T1004: funding=auto_recharge は事前同意 (enabled=false) を Checkout 開始前に記録する。
    $fundingRaw = $request->validated('funding_choice');
    $funding = is_string($fundingRaw) ? SignupFundingChoice::from($fundingRaw) : null;
    if ($funding === SignupFundingChoice::AutoRecharge) {
        $consentVersion = $request->validated('consent_version');  Assert::stringNotEmpty($consentVersion);
        try {
            $autoRecharge->recordPreConsent($organization, $user, new AutoRechargeConsentDto($consentVersion));
        } catch (CheckoutInProgressException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
    // (4) plan 解決 → 冪等開始
    $planCode = $request->validated('plan_code');  Assert::string($planCode);
    $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

    try {
        $result = $subscriptions->startCheckout(
            $organization, $user, $plan,
            route('billing.index').'?session_id={CHECKOUT_SESSION_ID}',
            route('billing.plans'),
            $attemptToken,
            $funding,
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

**禁止事項 #7**（`redirect()->intended()`）は使わない。**禁止事項 #8**: `Billing/Plans` / `Onboarding/Checkout` の申込ボタンは token / plan / 同意の状態で disabled にせず、押下時に上記のエラー・422 を表示する。

##### 着地 feedback（`resolveBillingFeedback`。verbatim。UI は raw query を見ない）

`index` は **`resolveAutoRechargeLanding()` を先に評価**し（該当時は 303 = C-3(5)）、非該当なら以下の feedback を返す。

| query | 条件 | kind / 文言 |
|---|---|---|
| `?portal` | **`session('error')` が文字列なら `null`**（成功偽装の抑止。aigenba F-2-03 verbatim） | `portal_returned` /「お支払い管理画面から戻りました。」 |
| `?session_id=` | `$organization->checkoutSessions()->where('stripe_session_id', …)` で **org スコープ**（未知 / 他 org は `null` = 偽 success 排除）。**`intent !== subscription_start` も `null`**（fail-closed。P8a の `setup_payment_method` 行が同テーブルに実在するため必須） | `Completed` → `purchase_received` /「お支払いを受け付けました。プランへの反映には数分かかる場合があります。」<br>`Pending` → `purchase_processing` /「お支払いを確認しています。プラン反映までしばらくお待ちください。」<br>`Failed` / `Expired` → **`null`**（verbatim） |
| `?replayed` | — | `purchase_already_received` /「この内容のお支払いは既に受け付け済みです。」 |
| `?retry` | — | `checkout_retry_required` /「お手続きの有効期限が切れました。画面を再読み込みして再試行してください。」 |

##### aigenba からの非 verbatim 点と根拠（5 点。他は verbatim。PII / DS / disabled 禁止は §横断決定 v2 の既決事項）

| # | 点 | aigenba | AI-CUE (P9) | 根拠 |
|---|---|---|---|---|
| N-1 | **同 token・別 plan** | `replayCheckout()` で**保存済み session の plan** の Checkout URL へ収束 | **422**（`SubscriptionAttemptPlanMismatchException`） | `Billing/Plans` は 1 render = 1 token のため「Starter を押して戻り Standard を押す」が同 token・別 plan として実在する。verbatim だと**押した plan と違う plan の Checkout に着地**する。AI-CUE 先例（`TicketCheckoutService:108-121`）とも整合。**ユーザー指示（P9 要件 6）による明示決定** |
| N-2 | **`initiated_by_user_id` の actor scope** | subscription 経路は org スコープ（actor scope は `TicketService` = T905 R1/R2 Critical で採用済み） | **token 所有者判定（要件 7 の 404）にのみ actor scope を適用**。dedup は org-wide のまま | aigenba 自身が同一の replay 機構に対し T905 で下した結論を、P2 が移植済みの `initiated_by_user_id` 列に適用する。**dedup を actor scope にはしない**（subscription の org singleton 性を壊すため） |
| N-3 | **`idempotency_key` 列の値** | `sprintf('sub_start:%d:%s:%d:%d', org, priceId, seatOverflow, floor5min(now))`（T680 で dedup 用途からは外れた**遺物**） | **Stripe へ渡した key と同値**（`'sub_start:'.$attemptToken`） | 5 分バケット key は同 org・同 price の別 token が同バケットに入ると UNIQUE 衝突し、`isUniqueViolation()` に拾われず **500** になる死角がある。**seat 引数は AI-CUE に存在しない**（原則 4）ため 5 分バケット式はそのままでは移植不能でもある |
| N-4 | **live 判定の共有（C-1）** | 閾値 `subDay()` は `BillingAccess::state()` と `ReconcileSubscriptionSchedules::expireStaleCheckouts()` に**別々の literal** で置かれ、`startCheckout()` の dedup / replay は **`status=Pending` + URL のみ**で live を判定する | **`BillingCheckoutSession::staleThresholdAt()` / `isLivePending()` を単一出典にし、`state()` / `startCheckout()` の段 2・3・4 / sweeper の 4 経路が共有** | **Codex Critical (1)**。aigenba は daily sweeper に依存して整合を保つが、判定の正しさを sweeper の実行タイミングに依存させないために述語を共有する（値は `subDay()` のまま = 原則 3 を侵さない）。AI-CUE 先例 `TicketCheckoutSession::isLivePending(CarbonImmutable $now)` |
| N-5 | **遅延成功の受理（C-2）** | `markLocalCheckoutCompleted()` は **`Pending` 以外は触らない**。`Failed` / `Expired` は終局 | **`Completed` 以外は payload の判定結果へ遷移**（`Failed` / `Expired` → `Completed` を受理）。**帰結として T1004 dispatch も「遷移が起きた呼び出し」限定**になる（再送で marker 窓が延びない。Job の `isAutoEnablePending` guard により**結果は同一**） | **Codex Critical (2)**。AI-CUE は**日次 sweeper が全 stale pending を `Expired` にする**ため verbatim だと「支払ったのに feedback が恒久 null」「決済済みなのに PM 流用が走らない」が現実に起きる。金銭は D7 の `invoice.paid` が真実源のため台帳は動かない。**aigenba へ報告し、先方が Pending-only を維持するなら差分として保持する**（原則 5 の運用） |

**T1004 の読み替え 1 点（非 verbatim ではない）**: aigenba の `intent=SignupFunding` を **`intent=SubscriptionStart`** に読み替える（`hasRecentAutoRechargeFundedSignup` / dispatch 分岐 / 着地 flash）。`SignupFunding` intent は **P2 が原則 4 で非移植**（AI-CUE の契約 checkout は `SubscriptionStart` の 1 本）と決定済みであり、**新 intent を作らず `funding_choice` 列を additive に足すだけで T1004 が成立する**（D29 の根拠列に明記済み）。列名・値・窓・文言・fail-closed 条件はすべて verbatim。

##### PII（不変条件 #6。email だけでなく name も閉じる）

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
            ->addBlindIndex('billing_contact_email', new BlindIndex('organization_billing_contact_email_index', [new Lowercase]));
        // billing_contact_name は blind index を張らない (等値検索の要求が無い = 検索が必要な項目だけ whereBlind)。
    }

    /** 請求通知の宛先: billing_contact_email 正本 → owner email fallback (aigenba IV-1/IV-N1) */
    public function routeNotificationForMail(Notification $notification): ?string;

    /** @return HasMany<BillingCheckoutSession, $this> */
    public function checkoutSessions(): HasMany;
}
```

- **検索契約**: `billing_contact_email` の検索は **`Organization::whereBlind('billing_contact_email', 'organization_billing_contact_email_index', $value)` のみ**。保存値は `EmailNormalizer::normalize()` 済みのため検索入力も**同一正規化を通す**。
- **`billing_contact_name` の検索は契約として存在しない**（blind index 行を作らない）。
- **一意制約は張らない**（複数組織が同一請求先メールを持つのは正当）。
- **cast**: `casts()` に `billing_contact_*` を**追加しない**（CipherSweet が row-level で暗号化/復号する。`encrypted` cast を重ねると二重暗号化）。
- **soft delete**: `Organization` は `SoftDeletes` のため blind index 行は残る（hard delete しない）。
- **更新経路**: `PATCH /billing/contact` → `Gate::authorize('manageBilling', $organization)` + **current-org scope**（route parameter を持たないため cross-org 指定が構造的に不能）→ `UpdateBillingContactAction`。

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

##### DTO 形状（PHP `@phpstan-type` と `resources/js/types/billing.ts` を exact 対で保守）

```
BillingFeedbackShape  = { kind: 'purchase_received'|'purchase_processing'|'purchase_already_received'
                                |'checkout_retry_required'|'portal_returned',
                          message: string }
BillingContactShape   = { email: string|null, name: string|null, fallbackEmail: string|null }  // fallbackEmail = owner email
BillingDashboardShape = { …P8b の全項目 (billingState / plan / balance / quota), …P8a の autoRecharge (無変更),
                          feedback: BillingFeedbackShape|null, billingContact: BillingContactShape }
BillingPlansPageShape = { plans: list<PricingPlanShape>, billingState: BillingStateValue, currentPlanCode: string|null,
                          canManage: bool, subscriptionAttemptToken: string }
OnboardingCheckoutShape = { …P3 の全項目, …P8a の consentTerms, subscriptionAttemptToken: string }
```

**UI**: `Billing/Index.svelte` は `templates/PageContainer` / `molecules/PageHeaderSection` / `templates/PageContent`（T071 primitive）配下に feedback バナーと `BillingContactForm` を置く。DS token のみ（hex 直書き禁止）。アイコンは `@lucide/svelte`（`CircleCheck` / `Clock` / `Receipt`）。**判定源は `page.billingState`**。`EffectivePlan` は存在しない。

#### PHPStan 適合チェック

- `BillingCheckoutSession::$status` / `$intent` / **`$funding_choice`** は **P2 verbatim の plain string 列**（enum cast ではない）。比較は `$row->status === CheckoutSessionStatus::Pending->value` / `$row->funding_choice === SignupFundingChoice::AutoRecharge->value` の**文字列比較**で書く（cast 前提の enum 比較は `alwaysFalse`）。
- `BillingCheckoutSession::$created_at` / **`$pm_reuse_dispatched_at`** は `Carbon|null`（`'pm_reuse_dispatched_at' => 'datetime'` cast + `@property Carbon|null`）。`isLivePending()` は `=== null ||` で null を明示分岐（`?->` で握り潰さない）。`staleThresholdAt()` は `CarbonImmutable` を受けて返す純関数（`now()` を内部で呼ばない = テストが時刻を注入できる）。
- `Cache::lock()->block()` は `mixed` を返すため、`TicketCheckoutService` と同じく `Assert::isInstanceOf($result, CheckoutSessionDto::class)`（`applyReusedPaymentMethod` は `/** @var bool $enabledNow */` = aigenba verbatim の再表明）で絞る。
- `attemptTokenIsForeign()` は `->exists()` を返す `bool`。`where(fn (Builder $q) => …)` の closure 引数に `@param Builder<BillingCheckoutSession>` を付す。
- **`SignupFundingChoice` は enum で比較**（`$funding === SignupFundingChoice::AutoRecharge`）。`$request->validated('funding_choice')` は `mixed` → `is_string()` 判定後に `::from()`（P8a の `ActivatePersonalController` と同一様式 = 分岐網羅を PHPStan に見せる）。`?SignupFundingChoice` 引数は `$funding?->value` で `string|null` に落とす。
- `StripeWebhookProcessor::subscriptionIdFrom(array $object): ?string` — `$object['subscription']` は `mixed` → `is_array()` なら `['id']` を取り出し、最後に `is_string($v) && $v !== ''` で narrow（既存 `stringAt()` と同じ様式）。`payment_status` は `in_array($status, ['paid','no_payment_required'], true)`（Stripe 値集合は enum 化しない = payload 由来の外部語彙）。
- `AutoRechargeGatewayInterface::resolveSubscriptionPaymentMethod(): ?string` は `@return non-empty-string|null`。実装の `CashierAutoRechargeGateway::resolvePaymentMethodFromSubscription(\Stripe\Subscription $s): ?string` は `$s->default_payment_method` の `string|PaymentMethod|null` を `instanceof` で分岐し、`is_string($c) && $c !== ''` で `non-empty-string` へ narrow（fallback の `latest_invoice.payments.data[].payment.payment_intent` は `instanceof Invoice` / `instanceof PaymentIntent` で明示分岐）。
- `ReuseSubscriptionPaymentMethodJob` は **`public readonly int $organizationId` / `public readonly string $stripeSubscriptionId`** のみを持つ（`SerializesModels` は付けるが Model 参照は保持しない = verbatim）。`handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge): void` の DI 解決は container 型で確定。`Organization::query()->find()` の戻り値は `! $org instanceof Organization` で narrow。
- `Log::warning` / `Log::info` の context は `array<string, scalar|null>`。
- `BillingFeedbackDto::toArray()` は `@phpstan-type SimpleBillingFeedbackKind` + `@return BillingFeedbackShape`。`$this->kind->value` は `string` に広がるため `/** @var SimpleBillingFeedbackKind $kindValue */` で literal union へ narrow（型の widen ではなく enum → literal の再表明）。
- `resolveBillingFeedback()` / `resolveAutoRechargeLanding()` の `$request->query('session_id')` は `mixed` → `is_string($x) && $x !== ''` で narrow。`$request->session()->get('error')` も `is_string()` で判定（verbatim）。
- `Organization::routeNotificationForMail(): ?string` — `EmailNormalizer::normalize(string): string` は非 null 引数を要求するため `is_string() && trim() !== ''` で narrow してから渡す（AI-CUE の既存 `EmailNormalizer` を改変しない）。
- `UpdateBillingContactData::fromRequest()` は `$request->string(…)->toString()` + `Assert::stringNotEmpty()`、name は `mixed` を `is_string() && trim() !== ''` で narrow（verbatim）。
- `StripeGatewayInterface::createSubscriptionCheckout()` の `array $metadata` は `@param array<string, string>`。`buildSubscriptionSessionPayload()` は `@return array{mode: 'subscription', customer: string, line_items: …, subscription_data: array{metadata: array<string, string>, payment_settings: array{save_default_payment_method: 'on_subscription'}}, success_url: string, cancel_url: string}` で固定。
- `isUniqueViolation(QueryException $e)`: `$e->getCode()` は `mixed` → `in_array($e->getCode(), ['23000','23505'], true)`（strict 比較で型不一致は false）。**INSERT は `UniqueConstraintViolationException` で catch** し driver 差の判定は `isUniqueViolation()` に委ねる。
- `ReconcileSubscriptionSchedules::expireStaleCheckouts()` の `->update()` 戻り値は `int`。
- 型を緩めた回避・baseline 化は行わない（禁止事項 2）。

#### テスト計画

**テストファースト**。`RefreshDatabase` グローバル + `--parallel`（個別 `DatabaseTransactions` 禁止）。テストデータは Factory のみ。時刻依存は `travelTo()` / Factory の `stale()` / `pmReuseDispatched()` state で固定。Stripe は `FakeStripeGateway` / `FakeAutoRechargeGateway` を bind（実 API を撃たない）。

新規 `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php`（**要件 1-7**）:
1. 同一 `subscription_attempt_token` + 同一 plan の 2 連投で **`billing_checkout_sessions` が 1 行**、2 回目は**既存 `checkout_url` へ収束**し fake の作成呼び出しが **1 回**（要件 1 / 4）。
2. 同一 token + **別 plan_code** → **422**（`assertInvalid(['plan_code'])`）。行は増えず Stripe 呼び出しも増えない（要件 6 / N-1）。
3. `idempotency_key === 'sub_start:'.$attempt_token`、かつ同 key の再呼び出しで fake が**同一 sessionId** を返す（要件 5）。ticket 側 `purchase:{token}` / **P8a の `auto-recharge-setup:{token}`** と**衝突しない**（key 空間分離）。
4. **他 org の token** → **404**（`Gate` 到達前。`manageBilling` を持つ owner でも 404）。**同 org の他 user の token** → **404**（要件 7 / 2）。いずれも**行が作られない**。
5. `completed()` 行の token 再送 → `billing.index?replayed=1`、Stripe 呼び出し 0（要件 4）。
6. `expired()` / `failed()` 行の token 再送 → `billing.index?retry=1`。
7. **別 token・同 plan の live pending** → `back()->with('warning')`、**新規行なし・Stripe 呼び出しなし**（org-wide dedup）。**同 org の別 user が別 token で申し込んでも 1 本に収束**（要件 2）。
8. **別 token・別 plan の live pending**: `expireCheckoutSession` が `'complete'` → `CheckoutInProgressException` → `back()->with('error')`、**新規行なし**。throw → 停止し local 行は `Pending` のまま。`'expired'` → 旧行が `Expired` になり新規発行が続行。
9. `UniqueConstraintViolationException` 注入（並行 race 模擬）→ **500 にならず** replay / stale へ収束。**attempt_token 以外の unique 違反は rethrow**。
10. 既に `valid()` な subscription を持つ org → `'既に有効なサブスクリプションがあります。…'` で停止（行なし）。
11. `initiated_by_user_id` が**必ず非 null** で記録される（要件 2）。
11b. **P8a の `intent=setup_payment_method` 行が同 org に live pending で在っても**、段 2/3/4 に一切干渉しない（同 `attempt_token` の setup 行があっても subscription checkout は新規発行する = `intent` による token 空間分離の回帰）。

新規 `tests/Feature/Billing/CheckoutStaleThresholdTest.php`（**C-1**）:
12. **`created_at` を 2 日前にした pending 行があるとき、新 token の POST が新規 Checkout を作る**（行 2 行・Stripe 作成 1 回・`Inertia::location`）。**warning に落ちない**。
13. 同 token + **stale pending** の再送 → **`?retry=1`**。`created_at` が**境界内**（23h59m 前）なら既存 URL へ replay（境界の両側を固定）。
14. **`state()` と `startCheckout()` の同値**: `PendingCheckout` のとき新規作成しない / `ExpiredCheckout`（stale pending のみが理由）のとき新 token は新規作成できる、を `travelTo()` で 23h / 25h の 2 点固定。
15. `billing:reconcile-schedules` 実行で **stale pending のみが `Expired`、live pending は `Pending` のまま**（`ReconcileSubscriptionSchedulesTest` に追加。既存 2 工程の期待は不変）。**stale な `setup_payment_method` 行も `Expired` になる**（intent 無しフィルタの verbatim）。**sweeper 未実行でも 12/13/14 が成立する**。

新規 `tests/Architecture/CheckoutLiveThresholdSingleSourceTest.php`（**C-1 の構造的封じ**）:
16. `BillingAccess.php` / `SubscriptionService.php` / `ReconcileSubscriptionSchedules.php` のソースに **`subDay(` / `subDays(` が出現しない**（閾値 literal は `staleThresholdAt()` にのみ存在する）。

新規 `tests/Feature/Billing/SubscriptionCheckoutWebhookRaceTest.php`（**要件 8 / C-2**）:
17. `checkout.session.completed`（purpose=subscription_start / mode=subscription / payment_status=paid）→ 行 `Completed` + `completed_at`。**チケット付与も `plan_code` 書き換えも起きない**（`ticket_ledger_entries` 0 件 / `organizations.plan_code` 不変 = D7 境界）。
18. 同一 event の**再送** → 冪等（`Completed` のまま `completed_at` 不変 = 終局 no-op）。
19. **`Expired` 行への遅延 completed（paid）→ `Completed`** / **`Failed` 行への paid 再送 → `Completed`** / **`Completed` 行への unpaid → 遷移しない**。
20. `payment_status=unpaid` → `Pending`→`Failed` / `Expired`→`Failed`。`payment_status=null` → **遷移しない**。
21. **cancel 相当** → 行は `Pending` のまま。`created_at` を 2 日前にすると `state()` が **`ExpiredCheckout`** を返し、**新 token で新規 Checkout が作れる**。`state()` 実行で**行が書き換わらない**。
22. 行不在の completed → throw = retryable failure（**silent 付与しない**）。
23. `customer` / `metadata.org_ref` 不一致 → throw（tenant キー不信）。
24. **purpose ディスパッチの排他**: `purpose=ticket_purchase` は `settleSubscriptionCheckout` に入らず既存 `grantPurchasedTickets` が動く（`TicketPurchaseWebhookTest` が**無改変で green**）。**`mode=setup`（P8a）も入らない**（`SetDefaultPaymentMethodJob` 分岐が従来どおり = `AutoRechargeWebhookTest` が無改変で green）。

新規 `tests/Feature/Billing/SubscriptionPmReuseTest.php`（**T1004 = Codex Round 14 Critical (1)**。移植元 `/tmp/aigenba/tests/Feature/Billing/SubscriptionPmReuseTest.php`）:
47. `funding_choice=auto_recharge` + `payment_status=paid` の completed → **`pm_reuse_dispatched_at` が立ち `ReuseSubscriptionPaymentMethodJob` が dispatch される**（`Queue::fake()`）。
48. `payment_status` が `unpaid` / null → **dispatch されず marker も立たない**（契約未確定ガード）。
49. `funding_choice=later` / `null`（Plans 経路） → dispatch されない。
50. `subscription` が null → dispatch されない。**expanded object（`['subscription' => ['id' => 'sub_x']]`）は id で dispatch**。
51. **事前同意あり（v2）** → `setDefaultPaymentMethod` 呼び出し + snapshot + `enabled=true` + 通知 1 通（`applyReusedPaymentMethod`）。
52. **中核 fail-closed**: 同意失効（v1 残存）では **customer default PM もローカル snapshot も一切変更されない**（gateway 呼び出し 0 / `enabled=false` のまま）。
53. `config` なし / `disabled_reason` あり → **完全 no-op**（gateway 呼び出し 0）。
54. 再実行（`enabled` 遷移済み）→ no-op で**通知も再送されない**。
55. 空文字 PM → `InvalidArgumentException`（fail-fast）。
56. **Job 一気通貫**（事前同意 → PM 解決 → `enabled=true`）/ **軽量 guard**（`isAutoEnablePending=false` なら `resolveSubscriptionPaymentMethod` を**呼ばない**）/ **PM 解決不能（null）→ no-op**（warning ログ + カード登録 CTA で回復可能）/ **org 不在は例外なしで return**。
57. **部分適用の顕在化**: default PM 更新後に適格性が失われたら **`RuntimeException`**（silent no-op にしない）。
58. **`setupPending`**: 契約完了 + 有効な事前同意の待機中 → **true** / 同意失効（v1）→ **false**（再同意フォールバック UI を隠さない）/ `funding=later` の契約完了 → **false** / dispatch から **30 分超で false**（`pm_reuse_dispatched_at` 基準の窓）/ **marker なし（未決済 completed）→ false**。
59. **着地 flash**: `?session_id` が自 org の `subscription_start` + `completed` + `auto_recharge` 行 → **`?highlight=auto-recharge` へ 303**。marker あり + `isAutoEnablePending` → 「自動的に有効になります」/ それ以外 → 確定表現を避けた誘導文言。**他 org / `intent=setup_payment_method` の session_id は 303 しない**（IDOR 防御 = feedback と同じ org スコープ）。
60. **同意 fail-closed（Request 層）**: `billing.checkout` に `funding_choice=auto_recharge` + `consent_version` 欠落 → **422**（`'自動購入への同意が必要です。'`）/ 旧版 `v1` → **422**（`'自動購入の同意内容が更新されています。…'`）。いずれも **`ticket_auto_recharges` 行も `billing_checkout_sessions` 行も増えず Stripe 呼び出し 0**（`recordPreConsent` 到達前）。
61. **`consent_version='v2'` 改定の効果**: P8a 期の v1 同意行を持つ org は `pendingAutoEnable=false` / `requiresReconsent=true` になり、**PM 流用でも自動有効化されない**（`reconsentRequiredFor` による自動失効 = fail-closed）。
62. **C-2 との結合**: `Expired` 行への遅延 completed（paid）でも **marker が立ち Job が dispatch される**（遅延成功が PM 流用へ届く）。**同一 event の再送では marker が更新されない**（終局 no-op）。
63. **同意記録の順序**: `funding_choice=auto_recharge` の POST は **`recordPreConsent`（`enabled=false` + 同意 4 列） → `startCheckout`** の順で走り、Checkout 作成が失敗しても同意 row は残り**課金は発生しない**（`ticket_auto_recharge_attempts` 0 件）。

新規 `tests/Feature/Billing/BillingFeedbackTest.php`:
25. `?session_id=` が自 org の `Completed` 行 → `feedback.kind === 'purchase_received'`。`Pending` → `purchase_processing`。**`Failed` / `Expired` → `null`**（verbatim）。
26. **他 org / 未知の `session_id`** → `null`（偽 success 排除）。**`intent=setup_payment_method`（P8a の実在行）→ `null`**（fail-closed）。
27. `?portal` + `session('error')` あり → `null`。error 無し → `portal_returned`。
28. `?replayed` → `purchase_already_received` / `?retry` → `checkout_retry_required`。
29. **C-2 との結合**: `Expired` 行が遅延 completed で `Completed` になった後の `?session_id` 着地が `purchase_received` を出す。

新規 `tests/Feature/Billing/BillingContactPiiTest.php`（**不変条件 #6**）:
30. `PATCH /billing/contact` 後、**`DB::table('organizations')` の生値が両列の平文と一致しない**。model 経由の読み出しは平文に復号される。
31. **平文 where が hit しない**（`where('billing_contact_email', $plain)->exists()` が false）。`whereBlind(…)` が該当 org を引く。
32. **`billing_contact_name` の blind index 行が存在しない**（検索契約の固定）。
33. 大文字混じり入力 → 正規化後の小文字で `whereBlind` が hit。

新規 `tests/Feature/Billing/UpdateBillingContactTest.php`:
34. **email 変更時のみ** `SyncBillingCustomerDetails` が dispatch（`Queue::fake()`）。**name のみ変更では dispatch されない**。
35. `stripe_id === null` の org では dispatch されない。transaction rollback で発火しない（`afterCommit`）。
36. **認可**: member は 403 / 未ログインは redirect。**current-org scope**: org 切替後の PATCH が切替後 org のみを更新。
37. **payload 契約**（要件 9 / 不変条件 #1）: `organization_id` / `initiated_by_user_id` / `plan_id` を混ぜると **422**。`billing_contact_email` 欠落 → 422。
38. `routeNotificationForMail()` が `billing_contact_email` 正本 → 未設定時に owner email へ fallback。

新規 `tests/Architecture/BillingContactEncryptionInvariantTest.php`:
39. `Organization` が `CipherSweetEncrypted` を実装し、`configureCipherSweet()` に**両列**が登録されている。
40. `organizations.billing_contact_*` の列型が `text`。
41. `billing_contact_*` が `$fillable` に無い。**`billing_checkout_sessions.pm_reuse_dispatched_at` も `$fillable` に無い**（webhook の `forceFill` 専用 marker）。

更新テスト:
42. `BillingPageTest` 相当 — `subscription_attempt_token` 欠落 / 非 ULID → 422。Index props に `feedback` / `billingContact`。
42b. **P8a 産出テストの期待更新（削除しない）**: `AutoRechargePreConsentTest` / `AutoRechargeEndpointTest` / `AutoRechargeServiceTest` の `consent_version` 期待を **`'v1'` → `'v2'`**（`setupPending` の (a) ケース・`pendingAutoEnable` の既存期待は不変）。
42c. **webhook 同期処理の invariant**（P8a 産出）に `settleSubscriptionCheckout` を追加 — **外向き Stripe API を撃たない**（PM 解決は Job 側のみ）。

JS（Vitest）:
43. 新規 `tests/js/pages/Billing/BillingContactForm.test.ts` — 未入力でも **submit が disabled にならない**（禁止事項 #8）。押下時にサーバ 422 の `errors.billing_contact_email` が表示される。
44. 更新 `tests/js/pages/Billing/Index.test.ts` — `feedback` の **5 kind** が対応バナーを描画し、`null` で何も描画しない。**raw query を参照しない**。`?highlight=auto-recharge` で `AutoRechargeCard` が強調される。
45. 更新 `tests/js/pages/Billing/Plans.test.ts` — POST body に `subscription_attempt_token` が載る（**`funding_choice` は載らない**）。ボタンは常に enabled。422 が `plan_code` エラーとして表示される。
45b. 更新 `tests/js/pages/OnboardingCheckout.test.ts` — 有償プランの POST body に **`subscription_attempt_token` + `funding_choice`**、`auto_recharge` 選択時のみ **`consent_version`** が載る。**同意ダイアログ未操作でも申込ボタンは enabled**（禁止事項 #8）。
46. 影響（無変更で green）: `tests/js/architecture/{page-shell-structure,ds-purity,atomic-import-graph,lucide-scoped-import}.test.ts`。

#### リスク

| リスク | 緩和 |
|---|---|
| **`CashierStripeGateway` が `newSubscription()->checkout()` を捨てることで Cashier の webhook が `subscriptions` 行を作れなくなる**（`subscription_data.metadata.{name,type}` 依存。落とすと**課金成立なのに subscription 行が無い** = `state()` が `NoSubscription` に落ち P4 後に締め出し） | `buildSubscriptionSessionPayload()` を public pure メソッドにし、**`metadata.name='default'` / `type='default'` + `payment_settings.save_default_payment_method='on_subscription'` を含むことを gateway ユニットテストの invariant として固定**（後者は T1004 の第一候補 PM が埋まる前提でもある）。テスト 17 で「completed webhook 後に `customer.subscription.created` が来ると `subscriptions` 行が作られる」ことを確認する。**この invariant テストが payload 変更の唯一の入口** |
| **T1004 が「同意していない自動課金」を作る** | 3 段の fail-closed: (1) Request 層で `consent_version` の現行版一致を **checkout 開始前**に検証（テスト 60）/ (2) `recordPreConsent` は `enabled=false` の同意 row のみ（課金経路に触れない）/ (3) `applyReusedPaymentMethod` が **適格性先行**で不適格なら Stripe にも DB にも触らない完全 no-op（テスト 52 / 53）。さらに `consent_version='v2'` により **P8a 期の v1 同意は自動失効**（テスト 61）。同意文言・版番号・既定値は **aigenba verbatim**（原則 3） |
| **決済未確定の契約カードでオートリチャージが有効になる** | dispatch 条件が `payment_status ∈ {paid, no_payment_required}` の allowlist（テスト 48）。**`pm_reuse_dispatched_at` は dispatch した事実のみを表す永続マーカー**であり、`setupPending` / 着地 flash の「自動的に有効になります」表示は**この marker + `isAutoEnablePending` の AND**（テスト 58 / 59）。`updated_at` / `completed_at` は窓の基準に使わない（verbatim） |
| **PM 流用が「勝手にカードを既定にした」と受け取られる** | `setDefaultPaymentMethod` は T1000 の課金機構（customer default PM への off-session invoice 課金）の構造上の前提であり、**setup 経路と同一の副作用**。**v2 同意文言（契約のお支払いカードをオートリチャージにも使う）で開示済み**であり、開示の版管理が `consent_version` = aigenba の消費者保護契約そのもの。適格でない org には副作用が一切及ばない（テスト 52） |
| **`applyReusedPaymentMethod` の部分適用**（Stripe だけ変更済み） | 適格性判定・Stripe 更新・DB 確定を**同一 org lock**（`billing:auto-recharge:{org}`）内で直列化し、TX 内で適格性が失われていたら **`RuntimeException` で顕在化**（silent no-op にしない。Job retry で収束 / 継続不適格は `failed_jobs` で検知）。テスト 57 |
| **`consent_version` 改定で稼働中のオートリチャージが止まる** | **意図した fail-closed**（`reconsentRequiredFor` → `requiresReconsent=true` → `createAttemptLocked` が停止）。出口は P8a の `AutoRechargeCard` の再同意 1 クリック。**救済 backfill は書かない**（版の意味を無効化するため）。テスト 61 が停止と再同意導線の両方を固定 |
| **live 判定の単一出典化が P2 の `state()` を壊す** | 変更は「同じ `subDay()` 値を述語経由で呼ぶ」だけで**挙動不変**。P2 の `BillingAccessStateTest` / 分岐表 / migration / Factory を**無変更で green** に保つことを DoD にする。テスト 16 の arch test が閾値 literal の再発明を機械検出 |
| **日次 sweeper が P8a の `SetupPaymentMethod` 行を expire する** | aigenba verbatim（intent 無しフィルタ）。1 日以上前の pending は Stripe 側で既に expire 済みであり `Expired` 化は事実の追認。C-2 により**遅延成功は `Expired` からでも `Completed` へ受理される**ため決済を取りこぼさない。テスト 15 / 19 / 62 |
| **C-2 の遷移緩和が「未決済を成功に見せる」** | 遷移条件は `payment_status` の allowlist のみ。**null / 未知値は遷移しない**。`Completed` は終局のため巻き戻しも起きない。テスト 19 / 20。金銭の付与は `invoice.paid`（D7） |
| **P9 の writer が P8a の setup 行と混線する** | 冪等マシンのクエリは**常に `intent=subscription_start` スコープ**（`UNIQUE(organization_id, intent, attempt_token)` の `intent` 軸）。feedback / 着地 flash も `intent` 検証で fail-closed（テスト 11b / 26 / 59）。逆に **sweeper だけは intent 非スコープ**（verbatim）で setup 行も収束させる（テスト 15） |
| **同 token・別 plan の 422 が aigenba からの逸脱**（N-1） | 逸脱は N-1 の 1 点に限定し、根拠を Service の docblock に明記。**aigenba へ報告し、先方が replay 継続を選ぶなら verbatim へ戻す**（原則 5）。テスト 2 がこの分岐の唯一の契約 |
| **`idempotency_key` を attempt_token 由来に変えた差分**（N-3） | 当該列は aigenba でも T680 以降 dedup に使われていない遺物で**意味論の後退はない**（5 分バケット衝突による 500 の死角が消える）。seat 引数が無い以上 verbatim 式は移植不能。テスト 3 で「列値 == Stripe へ渡した key」を固定し差分を 1 箇所に閉じる |
| **`Organization` への CipherSweet 導入が既存の org 検索・Filament を壊す** | 暗号化するのは新規 additive 2 列のみ。`name` / `slug` は平文のまま。既存行は null のため `addOptionalTextField` で素通し = backfill 不要 |
| **`billing_contact_email` を Stripe へ同期することで PII が外部へ出る** | 現行 `syncStripeCustomerDetails()` が既に owner email を送っており送信先・内容は不変。**`billing_contact_name` は Stripe へ送らない**（aigenba IV-6 verbatim）。CipherSweet は保管時の保護であり境界は変わらない |
| **feedback バナーが「成功」を偽装する** | `session_id` は**自 org の DB 行と照合できたときのみ** feedback を出し、行の `status` を文言の唯一の根拠にする（`Pending` は「確認しています」）。任意 query（`?replayed` / `?retry`）は状態を主張しない中立文言のみ |
| **`Failed` 着地が無言**（aigenba verbatim の性質） | **既知の性質として意図的に継承する**（原則 5: 先回り修正しない = v1 で `PurchasePaymentFailed` を発明して parity を壊した失敗の再発防止）。出口は `Billing/Plans` からの新規 token 発行（1 クリック）で常に存在する（テスト 6 / 12 / 21）。**aigenba へ報告し、先方が文言を足したら取り込む** |
| **live pending dedup の `expireCheckoutSession` 失敗で checkout が詰む** | fail-closed は二重 live session を作らないための **aigenba verbatim の意図的挙動**。出口は (a) 同 token 再送 → 元の `checkout_url`、(b) **1 日経過で新 token で再開**（C-1 により sweeper を待たない）。テスト 8 / 12 / 21 |
