Round 2 の Critical 5 / Warning 4 を**全て対応**した。**Critical 1 と 3 は私の設計の実バグ**で、指摘が無ければ実装時に
F-07 再発 or 金銭事故になっていた。感謝する。

1) **NoPlan variant 追加 (D23)**: 指摘どおり `GrandfatheredLegacyFreePlan` が「backfill 済み既存 org」と「新規未契約 org」を
   畳んでいた。4 variant に分離し解決順を正規契約として明文化 (paid → ActivatedPersonal → GrandfatheredLegacyFree → NoPlan)。
   **P4 の変更は `NoPlan::grantsAccess()` を false にする 1 点のみ**。`EffectivePlanKind` に `no_plan` 追加、PHP/TS shape 更新、
   `EffectivePlanResolutionTest`(解決順 + 4 variant の grantsAccess) を P2 に必須化。
2) **D21 の全面適用**: P3 の route を **route parameter 無しの current-org スコープ**へ全面変更 (`onboarding.*`)。
   `{organization:slug}` バインド / `isCurrentOrganization` prop / 組織切替 CTA / org-slug 非対称リスク / cross-org 課金リスク行を
   **削除**(current-org 解決により構造的に発生しないため)。P4 の `state()` を全て `effectivePlan()` へ。
   P7 の continuation は **組織 ID を保持しつつ membership 確認後に引数なしの `route('onboarding.checkout')` を生成**する形へ。
3) **debt 数式の確定 (D24)**: 指摘どおり「付与時に相殺」は二重回収だった。**grant 行の delta は変更しない**。相殺は残高計算で
   一度だけ: `debt = min(purchasedRaw - purchasedHold, 0)` / `availableTrueBalance = max(monthlyPositive + purchasedPositive + debt, 0)`。
   `totalAvailable()` も債務控除後の非負値。**DTO 境界では debt を正数**に固定。テストは purchased/monthly/signup/auto-recharge の
   各経路で**債務が一度だけ回収される** + **monthly grant 失効後も未回収債務が残る** を必須化。
4) **D10/D11 の実変更化**: P1 に `plans.is_active` の全波及 (migration / cast / seeder は personal・starter を is_active=false /
   PricingService の active filter / PlanActiveFilterTest) を追加し、P3 の否定記述を削除。P4 に free 撤去の実変更を明記
   (`free` 行削除 / `fallback_plan` を **personal** へ切替 = 限度値同値のため実効 limits 不変 / QuotaService 回帰 /
   Factory・既存テストの 'free' 参照更新 / SeededFreePlanBillingAccessTest の期待更新)。**grandfathered の quota キーは personal** に確定。
5) **P8b の未提供 backend 依存を解消 (D25)**: `BillingCheckoutSession` 相当 / `resolveBillingFeedback` / billing contact 列・Action /
   `BillingContactShape` を **P8b から除外し独立フェーズ P9 へ切り出す**。`BillingDashboardShape` から billingContact / feedback を除去
   (併せて D18 に従い currentPlanCode scalar → effectivePlan DTO)。実装 TODO は **10 本** (P1..P8b + P9) に更新。
Warning: P1 で activate() を完成させ P6 は「旧 grant 撤去 / paid webhook への claim+grant 追加 / claimSignupGrantMarker の
private 化」の 3 点のみと明記 / D16 の Welcome 直リンク 3 箇所変更を **P7 所管**として明記 / P3 の JS テストを D4 準拠
(押せる状態を維持し押下後に理由・validation error を表示) へ / D22 の**双方向 ID 集合完全一致**をテスト本文へ明記。

改訂後の詳細設計書を全文添付する。承認条件として挙げられた 5 点 (NoPlan variant / D21 全面適用 / debt を計算側で一度だけ /
is_active と free 撤去の実変更化 / P8b の依存解消) が閉じているか確認してほしい。残る穴があれば指摘し、無ければ APPROVED を出してほしい。

---

## 改訂後の詳細設計書（全文）

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

## 横断決定（起草時の未決事項に対する上位決定）

> 原則: **(1) AGENTS.md の禁止事項・セキュリティ不変条件は parity に優先する**（リポジトリの不変条件が上位）。
> **(2) それ以外は aigenba に寄せる**（独自実装を足さない）。**(3) additive のみ**（台帳・既存行の書き換えをしない）。
> **(4) AI-CUE に対象が存在しない aigenba 機能は移植しない**（席課金・encounter 等）。

| ID | 論点 | 決定 | 根拠 |
|---|---|---|---|
| **D1** | `PlanCode` の case 集合 | **Personal / Starter / Standard の 3 case**。`isSeatFixed()` は移植しない | AI-CUE に Business/Enterprise の Plan 行も価格体系も無く、席概念も無い（原則 4）。存在しない case を作ると PHPStan level 10 が `identical.alwaysFalse` を出す（禁止事項 2 の baseline 化を招く） |
| **D2** | P7 `normalizeRaw` の Enterprise 除外 | **除外分岐を落とす**（非 verbatim） | D1 により Enterprise case が存在せず、除外対象が無い。分岐を残すと PHPStan alwaysFalse |
| **D3** | `CreditSource` 移植可否 | **移植しない。既存 `TicketSource`（monthly/purchased）を使う** | 意味論は同一（plan_monthly ≡ monthly）。改名は `ticket_ledger_entries.source` 全行の書き換え = **台帳の置換**であり P5 の additive 前提（原則 3）に反する |
| **D4** | aigenba の disabled ボタン（PlanCard「変更不可」/ PurchaseTickets submit） | **移植しない**。押下時にエラー表示 + 理由は可視 caption | **AGENTS.md 禁止事項 #8 が parity に優先**（原則 1）。DESIGN.md L399-401 と正面衝突するため |
| **D5** | reserve プリミティブ | **amount ベースを維持**（aigenba の 1 encounter=1 枚は移植しない） | AI-CUE の消費は解析/レンダの可変コスト（`reserve(org,$cost)`）。機械移植すると単価差の前提が壊れ課金が壊れる。概念設計で確定済み・Codex 合議で妥当と判定 |
| **D6** | route スコープ（current-org vs org-slug） | **current-org スコープに統一**（`onboarding.{checkout,activate-personal,billing-required}`） | AI-CUE の業務 route は current-org スコープ（`routes/web.php:349`）。org-slug 化は AI-CUE 全体の route 規約変更になり本設計のスコープを超える。P3/P4/P7 はこの前提で整合させる |
| **D7** | paid 経路の grant 契機 | **`invoice.paid`（billing_reason=subscription_create）を維持** | aigenba の `customer.subscription.created` へ寄せると trial/incomplete にも付与され「**paid 成立で付与**」という意味論が崩れる（金銭の後退）。marker が真実源である限り parity の本質は保たれる |
| **D8** | past_due の entitlement | **現行維持（遮断）**。aigenba の「PM 登録済み past_due は継続」は**別 TODO** | 寄せるには `subscriptions.has_payment_method` 列の追加が要り、P4 の「ゲート反転のみ」を超える。与信方針の変更でもある |
| **D9** | `BillingPermissionService` の付与導線 | **service + Policy の OR 参照のみ移植し、付与 UI は別 TODO** | 監査で「委譲を許すか自体は人が決める」と product decision に分類済み。P2 は挙動不変が DoD |
| **D10** | `/pricing` への personal/starter 即時露出 | **aigenba の `plans.is_active` を P1 で移植し、購入導線が揃うまで非公開** | 購入できないプランを料金表に出すのは H8/H12 型の UX 破綻。aigenba に存在する機構なので独自実装ではない |
| **D11** | 既存 `free` Plan 行と `fallback_plan='free'` | **P1〜P3 は personal と併存 → P4 の grandfathering と同時に撤去** | 併存中に料金表へ 2 行出さないことは D10 で担保。撤去を P4 に置くのは、free fallback の消滅とゲート反転が同一の意味変更だから |
| **D12** | `config/quota.php` に `personal`/`starter` の limits | **P1 で必ず追加**（personal は free と同値） | `QuotaService.php:33` が未知キーを `?? []` = **無制限に silent 退行**させる（重大な後退。起草で発見） |
| **D13** | 移行期の `claimSignupGrantMarker()` public 化 | **許容**（P6 で private へ戻す。移行専用 API と docblock に明記） | 概念設計の移行期規約 5（付与と marker を同一 tx）を成立させるために必要 |
| **D14** | `PlanPriceService` への `?string $lookupKey` 追加 | **許容**（adaptation） | AI-CUE の `SyncStripePrices.php:78-87` が current 行の `lookup_key` 一致を要求するため。verbatim 移植すると既存 sync 契約が壊れる |
| **D15** | JSON/XHR への 402 応答 | **維持**（aigenba は常に redirect） | 既存 API/XHR クライアントの後退を避ける。UI 導線の parity には影響しない |
| **D16** | `Welcome.svelte` の `/register` 直リンク | **P7 で `/pricing` 誘導へ寄せる**（aigenba の Landing は `/register` 直リンクを持たない） | F1 でプラン選択が必須関門になる以上、直リンクは「選ばせない導線」で矛盾する |
| **D17** | チケット単位（枚 vs 回） | **「枚」を維持** | AI-CUE 全体の既存語彙。per-bucket ラベルは aigenba の意味（プラン付与残/購入済み残/次の失効）を「枚」語彙で表現する |
| **D18** | **判定モデルの単一化**（P2↔P4 の二重化解消） | **`EffectivePlan` を唯一の判定源に固定**。`OnboardingBillingState` は**導入しない**。`BillingAccess` / middleware / Controller は全て `EffectivePlan` を参照し、P4 は **`GrandfatheredLegacyFreePlan` variant の `grantsAccess()` の扱いだけを変更**する | 並列起草で `EffectivePlan` 系と `OnboardingBillingState` 系が混在していた（Codex 詳細レビュー Critical）。二重化すると P4 の「反転を 1 箇所に閉じる」が成立せず `grantsAccess()` の責務が分散して **F-07 再発余地**が残る。aigenba は 2 段（`OnboardingBillingState` + `SubscriptionEntitlementDto`）だが、AI-CUE には subscription checkout session テーブルが無く Pending/ExpiredCheckout 状態が表現できないため、2 段構成は**移植対象が存在しない**（原則 4） |
| **D19** | **返金過多の負残高**（旧 U1。金銭） | **債務保全で確定（clamp しない）**。判定用の `availableTrueBalance()` は非負を維持しつつ、**会計残高 DTO に `debt` を明示**する | aigenba の per-source `max(…,0)` clamp を移植すると、購入→消費→全額返金の債務が以後の付与から回収されず **タダ乗り経路**になる（`TicketRefundClawbackTest:147` の `-2` が `0` に）。**金銭の後退は parity に優先しない**。表示 clamp と判定値を分離することで aigenba の「表示に balance()、判定に使うと負残高で誤判定する」規約とも整合する |
| **D20** | `billing:reconcile-auto-recharge` の停止 | **監視アラートの実装 / 既存監視への接続確認を P8a の DoD に必須化**（設計項目として明文化。「注意喚起」で終わらせない） | 本コマンドは webhook が恒久 drop した「**課金済み・チケット未付与**」を回収する唯一の経路。静かに止まると資金回収済み・未付与が長期滞留し、ユーザー被害 + 会計不整合になる |
| **D23** | **`NoPlan` variant の追加**（D18 の完成） | `EffectivePlan` を **4 variant** に分離: `PaidSubscriptionPlan` / `ActivatedPersonalPlan` / `GrandfatheredLegacyFreePlan`(declarer-less = P4 backfill 済) / **`NoPlan`**(未契約)。**P4 の変更は `NoPlan::grantsAccess()` を false にする 1 点のみ** | 3 variant では「backfill 済み既存 org」と「新規未契約 org」が同一 variant に畳まれ、P4 で false にすると**既存 org も遮断(F-07 再発)**、true なら**未契約を遮断できない**（Codex Round 2 Critical） |
| **D24** | **debt の相殺は「書込み」でなく「残高計算」で一度だけ** | grant 行の `delta` は変更しない。`availableTrueBalance = max(monthlyPositive + purchasedPositive + debt, 0)`（`debt = min(purchasedRaw - purchasedHold, 0)`）。DTO 境界では **debt を正数**で表現 | 付与時に相殺すると、台帳合計が自然に債務控除済みなのに更に grant を減額して **二重回収**になる。また source 別 clamp のみだと monthly grant がある時に purchased 債務が回収されない（Codex Round 2 Critical） |
| **D25** | サブスク checkout の冪等・着地 feedback / 請求先情報 | **P8b から除外し独立フェーズ P9 へ切り出す** | `BillingCheckoutSession` 相当・`resolveBillingFeedback`・billing contact 列/Action・`BillingContactShape` は **P2 の非スコープで存在しない**ため、P8b が前提にすると実装不能（Codex Round 2 Critical） |
| **D22** | **P4 backfill の集合同値検証** | migration テストに「**SQL で更新された ID 集合 == 分類表で grandfather 対象と判定された ID 集合**」の同値アサートを**必須**で置く（DoD） | 分類表と実 SQL がズレると、条件漏れで **free org 締め出し（F-07 再発）** か **遮断中 org の誤救済（収益後退）** が起きる。分類表を「文書」で終わらせず機械検証に落とす（Codex 詳細レビュー Critical） |
| **D21** | onboarding の route 名 | **`onboarding.{checkout,activate-personal,billing-required}` の 1 系統に統一**（current-org スコープ = D6）。`organizations.onboarding.*` 表記は**使わない** | 並列起草で 2 表記が揺れていた。AI-CUE の既存 `onboarding.{mcp,cli}` は `Organizations/Onboarding` 配下の CLI/MCP セットアップで**別物**のため、名前衝突が無いことを確認済み |

### ユーザー判断を要する残件（実装着手前に確認する）

| ID | 論点 | 選択肢 | 本設計の既定 |
|---|---|---|---|
| **U1** | **オートリチャージ既定値**（製品） | aigenba の `default_threshold=5 / default_max=50` は「1 encounter=1 枚」前提の値。AI-CUE は可変コスト消費のため妥当性が異なる | aigenba 値を暫定採用し、**実装前にユーザー確認** |
| **U2** | 低残高通知とオートリチャージの併存 | 補充成功時に通知を抑制するか / 両立 | **両立**（既定 off の opt-in で既存挙動を変えないため） |
| **U3** | reserve TTL 30 分とオーバーセル窓 | (a) 現状維持（aigenba 同等の既知窓） / (b) TTL 延長 / (c) release 側の変更 | **(a) 現状維持**（P5 のスコープを会計精緻化に閉じる） |

## 施策一覧

| # | 施策名 | 主な変更ファイル | 優先度 | 単独マージ時の安全性 |
|---|--------|------------|--------|---|
| **P1** | プラン基盤（PlanCode / free plan・marker 列 / PersonalPlanService / seeder / backfill） | `app/Enums/PlanCode.php`, `app/Services/Billing/{PersonalPlanService,PlanPriceService}.php`, migrations ×2, `database/seeders/PlanSeeder.php`, `config/quota.php` | Critical | 挙動不変（ゲート未反転・列は additive） |
| **P2** | サブスク層（SubscriptionService / Snapshot / EffectivePlan DTO へ判定集約） | `app/Services/Billing/{SubscriptionService,SubscriptionSnapshot,BillingCustomerSynchronizer,BillingPermissionService}.php` | Critical | 挙動不変（判定の集約のみ） |
| **P3** | Onboarding 最小導線（**ゲート反転より前**に導線を実在させる = F-07 条件 A） | `app/Http/Controllers/Onboarding/*`, `resources/js/pages/Onboarding/{Checkout,BillingRequired}.svelte`, `routes/web.php` | Critical | 安全（導線が増えるだけ） |
| **P4** | **ゲート反転 + grandfathering 移行**（山場） | `app/Services/Billing/BillingAccess.php`, `app/Http/Middleware/RequireActiveSubscription.php`, backfill migration | Critical | 条件 A（P3）+ 条件 B（backfill）を満たして初めて安全 |
| **P5** | チケット残高会計の精緻化（per-bucket / per-source 失効 / 消費優先 / commit-wins） | `app/Services/Billing/TicketLedgerService.php`, `app/DataTransferObjects/Billing/TicketBalanceDto.php`, additive 列 | High | 安全（additive 列 + 読み取り計算） |
| **P6** | signup grant 契機変更（F2）+ **LP 文言** | `app/Actions/Fortify/CreateNewUser.php`, `app/Services/Billing/{PersonalPlanService,StripeWebhookProcessor}.php`, `resources/js/pages/Welcome.svelte` | High | 安全（marker は P1 で導入済み） |
| **P7** | 新規登録経路（IntendedPlanResolver / continuation / `?plan=` handoff） | `app/Services/Onboarding/*`, `app/Support/Auth/EmailVerificationContinuation.php`, `app/Providers/FortifyServiceProvider.php` | Medium | 安全（導線の質向上） |
| **P8a** | 裏チャージ（オートリチャージ + リコンサイル） | `app/DataTransferObjects/Billing/AutoRecharge*`, `app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php`, migration | Medium | 安全（opt-in・既定 off） |
| **P8b** | 課金 UI parity + 監査の判断不要 15 件（**D25: checkout feedback / billingContact は除く**） | `resources/js/pages/{Guest/Pricing,Billing/Plans,Billing/Index,Billing/PurchaseTickets}.svelte`, `_helpers/PlanCard.svelte` | Medium | 安全（UI のみ） |
| **P9** | サブスク checkout の冪等・着地 feedback + 請求先情報（**D25 で P8b から切り出し**） | `BillingCheckoutSession` 相当（model + migration）, `resolveBillingFeedback`, billing contact 列 + 更新 Action, `Billing/Index` の feedback バナー | Low | 安全（追加機能） |

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


### P1 プラン基盤 (PlanCode / PlanPriceService / free plan・signup marker 列 / PersonalPlanService / Plan seeder / marker backfill)

**DoD**: ゲートは反転しない。`BillingAccess::hasActiveAccess()` は無改変で、既存の業務ルート到達性・付与枚数は不変。列追加は additive、`PersonalPlanService::activate()` はまだどの route からも呼ばれない (配線は P3)。

#### 変更箇所 (ファイルパス + 何をするか。移植元 aigenba のパスを併記)

| AI-CUE | 内容 | 移植元 |
|---|---|---|
| `app/Enums/PlanCode.php` (新規) | `Personal='personal' / Starter='starter' / Standard='standard'`。`requiresStripeCheckout()` は verbatim (Personal のみ false)。**`isSeatFixed()` は移植しない** (AI-CUE に席概念・`plans.included_seats` が無く、席課金は概念設計でスコープ外)。Business/Enterprise は AI-CUE に Plan 行が無いため case を作らない | `/tmp/aigenba/app/Enums/PlanCode.php` |
| `database/migrations/2026_07_17_000100_add_free_plan_and_signup_grant_marker_to_organizations.php` (新規) | **verbatim**: `free_plan_code`(string 32 nullable)・`free_plan_activated_at`・`personal_declared_at`・`personal_declared_by_user_id`(FK users nullOnDelete)・`signup_tickets_granted_at` + `index('free_plan_code')` + raw partial unique index。`down()` も verbatim | `/tmp/aigenba/database/migrations/2026_07_08_113500_add_free_plan_and_signup_grant_marker_to_organizations.php` |
| `database/migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php` (新規) | **verbatim**。AI-CUE の実スキーマと完全一致 (`ticket_ledger_entries` / `organization_id` / `idempotency_key` / `granted_at` が全て実在。`create_ticket_tables.php:37-61`)。`down()` は no-op | `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php` |
| `app/Services/Billing/PersonalPlanService.php` (新規) | `eligibility()` / `activate()` / `retireForPaidSubscription()` を移植。AI-CUE 差分は下記「移植時の adaptation」のみ | `/tmp/aigenba/app/Services/Billing/PersonalPlanService.php` |
| `app/DataTransferObjects/Billing/PersonalPlanActivationResultDto.php`・`PersonalPlanEligibilityDto.php` (新規) | verbatim (`toArray()` の `PersonalPlanEligibilityShape` phpstan-type 込み) | 同名 aigenba ファイル |
| `app/Enums/Billing/PersonalPlanIneligibleReason.php` (新規) | verbatim (`label()` の日本語文言含む) | 同 |
| `app/Exceptions/Billing/PersonalPlanNotEligibleException.php` (新規) | verbatim (`userMessage()`)。**500 にしない**根拠 | 同 |
| `app/Services/Billing/PlanPriceService.php` (新規) | `replaceCurrent()` を移植。AI-CUE の `plan_prices` は `lookup_key`/`livemode`/`synced_at` と CHECK (`is_current ⇔ active_to IS NULL`) を持つため **`?string $lookupKey` 引数を追加**して sync 契約 (`SyncStripePrices.php:78-87` が `lookup_key` 一致の current 行を要求) を壊さない | `/tmp/aigenba/app/Services/Billing/PlanPriceService.php` |
| `database/seeders/PlanSeeder.php` | `personal` (Price 無し・`monthly_ticket_grant=0`・sort_order=0) と `starter` (base ¥980) を `updateOrCreate` 追加。既存 `free` / `standard` 行は**残す** (fallback_plan='free' は P4/P6 まで生きている) | `/tmp/aigenba/database/seeders/PlanSeeder.php` (SPECS の personal/starter) |
| `app/Support/Billing/StripePriceLookupKeys.php` + `stripe/fixtures/plan_starter.json` (新規) | `CATALOG` に `'starter' => [PlanPriceKind::Base]` を追加し fixture を同時追加 (`StripePriceCatalogFixtureInvariantTest` が集合一致を強制)。personal は Checkout 非対象のため追加しない | aigenba の Personal=Price skip 規約 |
| `config/quota.php` | `plans` に `personal` (free と同値: max_projects=1/max_members=3/1GiB)・`starter` の limits を追加。**未追加だと `QuotaService.php:33` が `?? []` で無制限に silently 退行する** | — |
| `database/migrations/2026_07_17_000120_add_is_active_to_plans.php` (新規) | **D10 の実変更**: `plans.is_active`(boolean, default true) を追加 | aigenba `plans.is_active` |
| `app/Models/Plan.php` | `is_active` を `casts()` に追加 (bool) | 同 |
| `database/seeders/PlanSeeder.php` | **`personal`/`starter` は `is_active=false` で seed**（購入導線が揃う P3/P8b まで料金表に出さない）。既存 `free`/`standard` は `is_active=true` | 同 |
| `app/Services/Billing/PricingService.php` | `listPublicPlans()` に **`where('is_active', true)` filter を追加** | 同 |
| テスト | `PlanActiveFilterTest`（新規）: `is_active=false` のプランが `/pricing` に出ない。`tests/Feature/Marketing/PricingPageTest.php` は**件数不変**を期待（personal/starter は非公開のため） | — |
| `app/Models/Organization.php` | 新 5 列の `casts()` 追加 (`immutable_datetime`)。`$fillable` は不変 (書き込みは `forceFill` 経由のみ)。docblock に free entitlement は `free_plan_code` 側で表現される旨を追記 (`plan_code=null=free` の記述は P4 まで有効なので消さない) | aigenba Organization |
| `app/Support/Security/MassAssignmentProtectedKeys.php` | actor キーとして `'personal_declared_by_user_id'` を追加 | — |
| `app/Services/Billing/TicketLedgerService.php` | `grantSignupGrant(Organization $organization, string $idempotencyKey): void` へ**シグネチャ変更** (内部生成キーをやめ、呼び出し側が `signup_grant:org:{id}` / `signup_grant:personal:{id}` を渡す)。付与枚数・期限・`insertOrIgnore` は不変 | aigenba `TicketService::grantSignupGrant(Organization, string)` (L261) |
| `app/Actions/Fortify/CreateNewUser.php` (L106) | **移行期規約**: 既存の登録 tx 内で `PersonalPlanService::claimSignupGrantMarker($org)` を呼び、先取できたときのみ `grantSignupGrant($org, "signup_grant:org:{$id}")`。org 行 `lockForUpdate()` 下・同一 tx | 概念設計 §signup grant の冪等移行 規約 5 |
| `app/Services/Billing/StripeWebhookProcessor.php` (L266-271) | 同様に marker claim 経由へ (paid 経路も marker が真実源。付与結果は現行と同値) | aigenba の paid webhook 対称実装 |

**移植時の adaptation (aigenba → AI-CUE。いずれも名前解決のみで意味論は不変)**
- `TicketService` → `TicketLedgerService`、`SubscriptionService` 依存を落とす。
- `Role::OrganizationOwner` → `App\Enums\OrganizationRole::Owner`（値 `organization_owner`。laratrust pivot は `role_user.team_id` で AI-CUE も同一。`config/laratrust.php:151`)。
- `hasEntitledSubscription()`: P2 の `SubscriptionService::deriveEntitlement()` が未着のため、P1 は `subscription('default')?->stripe_status ∈ {active,trialing}` (= `BillingAccess::GRANTING_STATUSES` と同値) で実装し、docblock に **P2 で `deriveEntitlement` へ差し替える seam** と明記。
- `MAX_MEMBERS = 3` (`config/quota.php` の `free.max_members=3` と一致。invariant テストで固定)。
- `claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool` を **public 化** (aigenba では `activate()` 内 private)。移行期に登録経路・webhook から共用するための**移行専用 API で、P6 で private へ戻す**。

#### 波及変更

- **TypeScript 型定義**: なし (P1 は Inertia props を一切変えない。`PersonalPlanEligibilityShape` の TS 対応は P3 で `Onboarding/*` と同時)。
- **DTO / JsonResource**: 新規 `PersonalPlanActivationResultDto` / `PersonalPlanEligibilityDto` (どちらも P1 時点では Controller から返さない。Service 戻り値のみ)。既存 `PricingPlanDto` は**形状不変**だが、`PricingService::listPublicPlans()` が全 Plan 行を列挙するため **/pricing の出力行数が personal/starter の 2 行分増える**(下記リスク)。
- **Inertia props**: なし (props の形は不変。`Pricing.svelte` の `page.plans` 配列長のみ変わる)。
- **テストファイル (更新)**:
  - `tests/Feature/Billing/TicketGrantTest.php` (L82-117 `grantSignupGrant` 呼び出しに idempotencyKey 引数を追加)
  - `tests/Feature/Billing/WebhookIdempotencyTest.php` (L138 の「内部生成キー」前提コメント/期待を marker 経由へ更新)
  - `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` (`seededFreePlan()` が「Price 無しの最初の Plan」を拾うため personal 追加で対象が非決定になる → `code='free'` 固定か Price 無し全プランの dataset 化へ更新。**削除しない**)
  - `tests/Feature/Billing/PlanSeederPriceInvariantTest.php` (starter=current base Price あり / personal=Price 無し を追加。既存 free/standard の期待は維持)
  - `tests/Feature/Marketing/PricingPageTest.php`、`tests/js/pages/Pricing.test.ts`、`tests/js/pages/Welcome.test.ts` (プラン件数・カード期待の更新)
  - `tests/Feature/Billing/SyncStripePricesCommandTest.php`、`tests/Feature/Billing/VerifyStripePricesCommandTest.php` (starter fixture / lookup_key 追加の影響)
  - `tests/Feature/Auth/RegistrationTest.php` (登録時付与に加え `signup_tickets_granted_at` が同一 tx で立つ期待を追加)
  - `tests/Architecture/StripePriceCatalogFixtureInvariantTest.php` (コード変更不要。fixture 追加で自動的に集合一致)
- **テストファイル (新規)**: `tests/Feature/Billing/PersonalPlanServiceTest.php` / `tests/Feature/Billing/SignupGrantOncePerOrgTest.php` / `tests/Architecture/FreePlanCodeWriteInvariantTest.php` (aigenba 同名を移植)。
- **Factory**: `database/factories/OrganizationFactory.php` に `freePersonal(User $declarer)` / `signupGranted()` state を追加 (テストデータ手組み禁止のため)。

#### 主要な契約

```php
enum PlanCode: string { case Personal='personal'; case Starter='starter'; case Standard='standard';
    public function requiresStripeCheckout(): bool; }               // Personal のみ false

final class PersonalPlanService {
    public const FREE_PLAN_CODE = 'personal';  public const MAX_MEMBERS = 3;
    public function eligibility(Organization $org, User $user): PersonalPlanEligibilityDto;
    /** @throws PersonalPlanNotEligibleException */
    public function activate(Organization $org, User $declarer): PersonalPlanActivationResultDto;
    public function retireForPaidSubscription(Organization $org): void;
    /** 移行期専用 (P6 で private 化)。org 行 lockForUpdate 下で marker を先取できたら true */
    public function claimSignupGrantMarker(Organization $org): bool;
}
final readonly class PersonalPlanActivationResultDto { public function __construct(public bool $granted) {} }
final readonly class PersonalPlanEligibilityDto {  // eligible() / ineligible(reason) / toArray()
    public bool $eligible; public ?PersonalPlanIneligibleReason $reason; }
enum PersonalPlanIneligibleReason: string { HasEntitledSubscription | TooManyMembers | AlreadyHasFreePersonalOrg; label(): string }

class TicketLedgerService { public function grantSignupGrant(Organization $o, string $idempotencyKey): void; }  // 引数追加
class PlanPriceService { public function replaceCurrent(Plan, PlanPriceKind, string $stripePriceId, int $amount,
    ?string $lookupKey = null, string $currency='jpy', ?CarbonImmutable $activeFrom=null): PlanPrice; }
```

**DB (organizations, 全て nullable / additive)**: `free_plan_code varchar(32)` + `index`、`free_plan_activated_at ts`、`personal_declared_at ts`、`personal_declared_by_user_id → users.id (nullOnDelete)`、`signup_tickets_granted_at ts`。
**partial unique index (verbatim・改変禁止)**: `organizations_personal_free_declarer_unique ON organizations (personal_declared_by_user_id) WHERE free_plan_code='personal' AND personal_declared_by_user_id IS NOT NULL` → declarer NULL 行 (P4 の grandfathered) は対象外。
**冪等キー**: `signup_grant:org:{orgId}` (登録経路・移行期) / `signup_grant:personal:{orgId}` (activate)。既存 partial unique `ticket_ledger_entries_signup_grant_unique ON (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'` (`2026_07_13_180622`) が**キー種別を跨いで org 生涯 1 回を DB 強制済み** → marker と 1:1 の二重防御。
**ルート**: 追加・変更なし (P3)。

#### PHPStan 適合チェック (level 10)

- `Organization::query()->lockForUpdate()->findOrFail($id)` は `Builder<Organization>` generics から `Organization` を返す (`@var` 不要)。`$org->id` は int|null になるため `Assert::integer()` で絞る (`TicketLedgerService::grantSignupGrant` 現行と同じ作法)。
- `DB::table('organizations')->…->update([...])` は `int` 戻り → `$claimed === 1` は型安全。
- `QueryException::getCode()` は `Throwable::getCode()` の戻り型が PHPStan 上 `int` 扱いだが PDO 由来は string を返す。aigenba の `in_array($e->getCode(), ['23000','23505'], true)` は level 10 で「常に false」判定を招くため **`in_array((string) $e->getCode(), ['23000','23505'], true)`** とする (意味論は不変)。
- `PersonalPlanEligibilityDto::toArray()` は `@phpstan-type PersonalPlanEligibilityShape` を付けて配列形状を固定 (aigenba verbatim)。
- `config()` 参照は `config()->string()/->array()` か `Assert::integer()` 経由 (既存 `grantSignupGrant` / `PricingService` の作法を踏襲)。`response()->json()` 直書きは無し (P1 は Controller を触らない)。
- `hasOtherActiveFreePersonalOrg()` のクロージャ引数は `Builder $q` (`Illuminate\Database\Eloquent\Builder`) を型注釈。`Organization::query()` の generics で戻りは `bool` (`exists()`)。
- 新 casts は `protected function casts(): array` の `array<string, string>` 契約内。

#### テスト計画 (テストファースト)

**先に red を作る (新規)**
1. `tests/Feature/Billing/PersonalPlanServiceTest.php`
   - `activate()` が `free_plan_code='personal'` / `free_plan_activated_at` / `personal_declared_at` / `personal_declared_by_user_id` を埋め、`signup_tickets_granted_at` を立て、config 枚数を 1 回だけ付与し `granted=true` を返す。
   - 同一 org の再 `activate()` は `granted=false` かつ**残高不変** (marker 先取が 0 件)。
   - `eligibility()` の 3 理由: active subscription あり / メンバー 4 名 / 同一 declarer の別 free personal org。
   - **並行 activate の後着**: 同一 declarer で別 org を activate → partial unique 違反が `PersonalPlanNotEligibleException(AlreadyHasFreePersonalOrg)` に変換され、**QueryException が漏れない (=500 にしない)**。
   - `retireForPaidSubscription()` の冪等 (2 回目 no-op・declared_* は監査証跡として残る)。
2. `tests/Feature/Billing/SignupGrantOncePerOrgTest.php` (**P1 の要**)
   - **free activate ↔ paid webhook の競合で二重付与しない**: activate 済み org に `invoice.paid (subscription_create)` → 付与 0 / 逆順 (webhook 先行 → activate) は `granted=false` で付与 0。marker と `ticket_ledger_entries_signup_grant_unique` の両方が働くことを ledger 行数で検証。
   - **移行期回帰 (必須)**: 移行期に `CreateNewUser` 経由で登録された org (marker 済み) を **P6 後相当の `activate()` に掛けても再付与されない** (`granted=false`・残高不変)。
   - **backfill migration**: 既存 `signup_grant:org:{id}` 履歴のある org は marker が `min(granted_at)` で立ち、履歴の無い org は null のまま。再実行しても値が動かない (冪等)。
3. `tests/Architecture/FreePlanCodeWriteInvariantTest.php` — `app/` 内の `free_plan_code` 書き込みは `PersonalPlanService` に限定 (aigenba verbatim)。
4. invariant: `PersonalPlanService::MAX_MEMBERS === config('quota.plans.personal.max_members')`。

**既存テストの更新 (削除しない)**: `tests/Feature/Billing/TicketGrantTest.php` / `WebhookIdempotencyTest.php` / `SeededFreePlanBillingAccessTest.php` / `PlanSeederPriceInvariantTest.php` / `SyncStripePricesCommandTest.php` / `VerifyStripePricesCommandTest.php` / `tests/Feature/Marketing/PricingPageTest.php` / `tests/Feature/Auth/RegistrationTest.php` / `tests/js/pages/Pricing.test.ts` / `tests/js/pages/Welcome.test.ts`。

**挙動不変の固定 (回帰)**: `RequireActiveSubscriptionMiddlewareTest.php` / `SeededFreePlanBillingAccessTest.php` は **P1 で期待値を変えない** (ゲート未反転の証明)。`Welcome.svelte:349` の文言も P1 では触らない (付与契機は登録時のまま = 文言は依然事実。修正は P6)。

#### リスク

| リスク | 緩和 |
|---|---|
| **`/pricing` に personal/starter が即時露出**する (`PricingService::listPublicPlans()` は全 Plan を列挙。aigenba の `plans.is_active` 相当が AI-CUE に無い)。starter は P3/P7 まで購入導線が無いのに ¥980 で表示される | Open question 3。案: (a) P1 では Plan 行のみ入れ `PricingService` に露出制御を入れない代わりに starter の seeder 投入を P7 へ後倒し / (b) aigenba の `is_active` を移植 |
| `config/quota.php` に personal/starter の limits を入れ忘れると `QuotaService.php:33` の `?? []` で**無制限に silently 退行** | 同 PR で limits 追加 + 「全 Plan.code が quota.plans に存在する」invariant テストを追加 (`QuotaKeyConfigInvariantTest` と同格) |
| `grantSignupGrant` のシグネチャ変更で呼び出し漏れ | 呼び出し元は 2 箇所のみ (`CreateNewUser.php:106` / `StripeWebhookProcessor.php:270`)。引数必須化でコンパイル (PHPStan) が漏れを検出 |
| 移行期の marker claim を入れ忘れた org が P6 後に再付与される | 上記テスト 2 の移行期回帰 + `ticket_ledger_entries_signup_grant_unique` の DB 二重防御 (キー種別を跨いで org 生涯 1 回) |
| backfill 対象 org に `signup_grant:%` が複数行あると min(granted_at) が曖昧 | `2026_07_13_180622` が既に「重複あれば fail-closed」で入っており、本番に重複行は存在し得ない |
| `PlanPriceService` が P1 時点で呼び出し元なし (dead code) | 移植方針上は許容 (P2/価格改定運用で使用)。`replaceCurrent` の単体テストで最低限の生存を確保。lookup_key 引数を落とすと `SyncStripePrices.php:78-87` が current 行を見失う → 引数追加を必須とする |
| `free` プラン行と `personal` が並存し、seeder 由来の組織 (`ManualTestSeeder` は全 Plan に 1 org 生成) が 2 つ増える | 手動テスト用途のみ。`SeededFreePlanBillingAccessTest` の対象非決定性は上記更新で解消 |
| partial unique index は pgsql/sqlite 前提 (MySQL 非対応) | 既存 `2026_07_13_180622` が同じ前提を明示済み (driver チェックで fail-closed)。本番/CI とも該当ドライバのみ |

##### 起草時の未決事項（上位決定は冒頭 §横断決定 / §ユーザー判断を要する残件 を参照）

- PlanCode の case 集合: aigenba は Personal/Starter/Standard/Business/Enterprise の 5 case だが AI-CUE には Business/Enterprise の Plan 行も価格体系も無い。Personal/Starter/Standard の 3 case に絞る案でよいか (verbatim からの意図的な縮小)。また席概念が無いため isSeatFixed() を落とす判断でよいか。
- starter の Stripe Price を P1 で投入するか: PlanSeederPriceInvariantTest の『有償プランは current base Price を持つ』不変条件により、starter を seed するなら stripe/fixtures/plan_starter.json + StripePriceLookupKeys::CATALOG への追加が同時に必要。金額は aigenba 準拠の ¥980 でよいか。それとも starter 投入自体を P7 (登録経路) まで後倒しするか。
- /pricing (公開 LP) への即時露出: PricingService は全 Plan 行を列挙するため、P1 で personal/starter を seed すると購入導線が無いまま料金表に 2 行増える。→ **D10 で確定: aigenba の `plans.is_active` を移植し、購入導線が揃うまで非公開**。
- 既存 'free' Plan 行と config/quota.php の fallback_plan='free' の去就: P4/P6 まで personal と並存させる前提でよいか (並存中は『Free』『パーソナル』の 2 プランが料金表・seeder に並ぶ)。撤去タイミングを P4 の grandfathering と同時にするか。
- PlanPriceService の AI-CUE schema 適応: aigenba の replaceCurrent は lookup_key を書かないが、AI-CUE の SyncStripePrices は current 行の lookup_key 一致を要求する。?string $lookupKey 引数を足す adaptation を許容するか (verbatim からの逸脱)。また P1 時点で呼び出し元が無い状態で移植してよいか。
- PersonalPlanService::hasEntitledSubscription の P1 実装: SubscriptionService::deriveEntitlement (P2) が未着のため Cashier の stripe_status 直読み (BillingAccess::GRANTING_STATUSES と同値) で暫定実装し、P2 で差し替える段取りでよいか。
- 移行期の marker claim API: aigenba に存在しない claimSignupGrantMarker() を PersonalPlanService に public で一時的に置き、CreateNewUser / StripeWebhookProcessor から呼ぶ形でよいか (P6 で private 化して撤去)。代替として TicketLedgerService 側に置く案もある。

---

### P2 サブスク層: SubscriptionService 移植と EffectivePlan への判定集約

前提: P1 で `PlanCode` enum / `PersonalPlanService`(`FREE_PLAN_CODE='personal'`) / `organizations.{free_plan_code, free_plan_activated_at, personal_declared_at, personal_declared_by_user_id, signup_tickets_granted_at}` + partial unique index が入っている。P2 は**列の読み方(判定)だけを集約**し、ゲートの結論は現行 `BillingAccess::hasActiveAccess`(`plan_code === null` → 許可 / 非 null → `stripe_status ∈ {active,trialing}`)と同値に固定する。

#### 変更箇所

| ファイル (AI-CUE) | 何をするか | 移植元 (aigenba) |
|---|---|---|
| `app/DataTransferObjects/Billing/EffectivePlan.php` (新規) | `abstract readonly class EffectivePlan`。判定 API (`grantsAccess()` / `planCode()` / `kind()` / `deniedReason()` / `toArray()`) を定義する唯一の判定型 | 対応クラス無し (概念設計 §詳細設計で確定する事項 (3) の指示。aigenba は `OnboardingBillingState` + `SubscriptionEntitlementDto` の 2 段で表現) |
| `app/DataTransferObjects/Billing/PaidSubscriptionPlan.php` (新規) | variant: `plan_code` 非 null。`SubscriptionEntitlementDto` を内包し `grantsAccess() = entitlement->entitled` | `BillingAccess::state()` の `Subscribed` 分岐 (`/tmp/aigenba/app/Services/Billing/BillingAccess.php:28-40`) |
| `app/DataTransferObjects/Billing/ActivatedPersonalPlan.php` (新規) | variant: `free_plan_code='personal'` かつ `personal_declared_by_user_id !== null`。常に許可 | 同 `ActiveFreePlan` 分岐 (`BillingAccess.php:42-49`) |
| `app/DataTransferObjects/Billing/GrandfatheredLegacyFreePlan.php` (新規) | variant: **`free_plan_code='personal'` かつ declarer なし**(= P4 backfill で救済済みの既存 org)。**P2/P4 とも `grantsAccess()=true`**(締め出さない) | 概念設計「grandfathering の定義」(declarer-less) |
| `app/DataTransferObjects/Billing/NoPlan.php` (新規) | variant: **`free_plan_code IS NULL` かつ paid なし**(= 未契約)。**P2 では true(現行同値)、P4 でここ*だけ* false にする** | **D23**(Codex Round 2 Critical: 両者を同一 variant にすると P4 で「既存 org も遮断(F-07 再発)」か「未契約を遮断できない」の二択になる) |
| `app/Enums/Billing/EffectivePlanKind.php` (新規) | `paid_subscription` / `activated_personal` / `grandfathered_legacy_free` / **`no_plan`**。**PHP 側は型で分離し、境界(props/JSON)でのみ tag に落とす** | — |

**variant 解決順（正規契約。D23。この順で最初に一致したものを返す）**

```text
paid（active/trialing）                              → PaidSubscriptionPlan      grantsAccess: P2=true  P4=true
free_plan_code='personal' かつ declarer あり          → ActivatedPersonalPlan     grantsAccess: P2=true  P4=true
free_plan_code='personal' かつ declarer なし          → GrandfatheredLegacyFreePlan grantsAccess: P2=true  P4=true
free_plan_code なし（未契約）                          → NoPlan                    grantsAccess: P2=true  P4=**false**
```

**P4 の変更は `NoPlan::grantsAccess()` を false にする 1 点のみ**（他 variant は不変 = 既存ユーザーは締め出されない）。
解決順・4 variant の `grantsAccess()` を検証する解決テスト（`EffectivePlanResolutionTest`）を P2 に必須で置く。
| `app/Enums/Billing/SubscriptionState.php` (新規) | `Active` / `PastDue` / `Paused` / `Inactive` + `fromSubscription()` + `grantsAccess()`。aigenba の `ScheduledForUpgrade` / `UpgradeRecovery` は `pending_plan_code` / `upgrade_recovery_required` 列が AI-CUE に無い(`database/migrations/2026_06_11_091200_create_subscriptions_table.php`)ため移植しない | `/tmp/aigenba/app/Enums/Billing/SubscriptionState.php` |
| `app/Enums/Billing/EntitlementDeniedReason.php` (新規) | `NoActiveSubscription` / `Paused` の 2 case。`TrialEndedWithoutPaymentMethod` は `subscriptions.has_payment_method` が無いため移植しない | `/tmp/aigenba/app/Enums/Billing/EntitlementDeniedReason.php` |
| `app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php` (新規) | `entitled` / `state` / `reason` + `granted()` / `denied()` / `toArray()` を verbatim 移植 | `/tmp/aigenba/app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php` |
| `app/DataTransferObjects/Billing/BillingStatusDto.php` (新規) | aigenba から `pendingPlanCode` / `pendingPhaseStartsAt` (列不在) を除いた形で移植 | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingStatusDto.php` |
| `app/Services/Billing/SubscriptionSnapshot.php` (新規) | Stripe subscription の値オブジェクト。`seatItemQuantity` は AI-CUE に席概念が無いため除く(概念設計スコープ外) | `/tmp/aigenba/app/Services/Billing/SubscriptionSnapshot.php` |
| `app/Services/Billing/SubscriptionService.php` (新規) | サブスク層のドメイン中枢。`effectivePlan()` / `deriveEntitlement()` / `getStatus()` / `applySubscriptionSnapshot()` / `startCheckout()` / `createPortalSession()`。Stripe I/O は Gateway 経由のみ | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:82-205` (getStatus/deriveEntitlement/assertCheckoutReady/applySubscriptionSnapshot), `:1095` createPortalSession |
| `app/Services/Billing/BillingAccess.php` (改修) | 中身を `return $this->subscriptions->effectivePlan($org)->grantsAccess();` に差し替え。`GRANTING_STATUSES` 定数と `plan_code === null` 分岐を撤去。docblock の「plan_code null = fallback free」記述を EffectivePlan 参照へ更新 | `/tmp/aigenba/app/Services/Billing/BillingAccess.php:26-30` (`state($org)->grantsAccess()` 委譲の形) |
| `app/Services/Billing/Contracts/StripeGatewayInterface.php` (新規 / 置換) | 既存 `SubscriptionCheckoutGateway` を aigenba の namespace・命名へ移設。**メソッドは現行 2 本 + `syncCustomerDetails()` に限定**(`createSubscriptionCheckout` / `createPortalSession` / `syncCustomerDetails`)。戻り値は AI-CUE の `ExternalBillingRedirect` を維持(aigenba の `array{id,url}` は DTO 返却規約に反するため採らない) | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php` |
| `app/Services/Billing/CashierStripeGateway.php` (新規 / 旧 `CashierSubscriptionCheckoutGateway.php` を rename) | 実装本体は現行のまま(`newSubscription('default',…)->checkout()` / `billingPortalUrl(…, PortalConfigurationSpec::sessionOptions(...))`) + `syncCustomerDetails()` に `$org->syncStripeCustomerDetails()` を追加 | `/tmp/aigenba/app/Services/Billing/CashierStripeGateway.php:43,326,406` |
| `app/Services/Billing/Fakes/FakeStripeGateway.php` (旧 `Fakes/FakeSubscriptionCheckoutGateway.php`) | interface 変更に追随(中立帰還 URL 契約は不変)。`syncCustomerDetails()` は no-op | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php:204,211` (fake で Stripe を叩かない契約) |
| `app/Services/Billing/BillingCustomerSynchronizer.php` (新規) | `dispatchFor()` を verbatim 移植 (`stripe_id === null` は no-op / `afterCommit()`) | `/tmp/aigenba/app/Services/Billing/BillingCustomerSynchronizer.php` |
| `app/Jobs/Billing/SyncBillingCustomerDetails.php` (新規) | `handle(StripeGatewayInterface $gateway)` → `syncCustomerDetails()`。Cashier 標準 job を使わない理由(PHPStan level10 の trait-as-type)は移植元コメントごと持ち込む | `/tmp/aigenba/app/Jobs/Billing/SyncBillingCustomerDetails.php` |
| `app/Actions/Organizations/RenameOrganizationAction.php` (新規) | `OrganizationController::update` の内部を抽出し、`isDirty('name')` のときだけ `dispatchFor()`。**laratrust team の rename は AI-CUE に無いので移植しない**(独自追加をしない/挙動不変) | `/tmp/aigenba/app/Actions/Organizations/RenameOrganizationAction.php` |
| `app/Services/Billing/BillingPermissionService.php` (新規) | `grant` / `revoke` / `hasDirectPermission` / `canEdit(WithKnownRoles)` / `getDirectManageBillingMap`。AI-CUE の同型先例 `app/Services/ApiKey/ApiKeyPermissionService.php` の構造(`ensureTeamId` / `ensureMembership` / `permission_user` 一括引き)に合わせる。permission 名は AI-CUE 規約(kebab)に従い `manage-billing` | `/tmp/aigenba/app/Services/Billing/BillingPermissionService.php` |
| `app/Policies/OrganizationPolicy.php:37 manageBilling` (改修) | `manageApiKeys` と同型に: role `canManage()` OR `BillingPermissionService::hasDirectPermission()`。**付与 UI/route は P2 に含めない**ため直接付与は 0 件 = 挙動不変 | `/tmp/aigenba/routes/web.php:365` (付与 route は P2 スコープ外) |
| `app/Http/Controllers/Billing/BillingController.php` (改修) | `SubscriptionCheckoutGateway` 直注入をやめ `SubscriptionService` に委譲。`index` の `currentPlanCode` raw 読みを `effectivePlan` DTO prop へ。`checkout` の price 不在分岐は `StripePriceNotSyncedException` catch → 現行と同一文言の `back()->with('error', …)` | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php` (Service 委譲の層構成) |
| `app/Exceptions/Billing/StripePriceNotSyncedException.php` (新規) | `SubscriptionService::assertPriceSynced()` が投げる | `/tmp/aigenba/app/Exceptions/Billing/StripePriceNotSyncedException.php` |
| `app/Services/Billing/StripeWebhookProcessor.php` (改修) | `syncPlanCode` / `clearPlanCode` / `syncSubscriptionPeriod` の**書込ロジックを SubscriptionService::applySubscriptionSnapshot へ移設**。Processor の責務は payload → `SubscriptionSnapshot` の写像 + 組織解決に縮む。ACTIVE_SUBSCRIPTION_STATUSES による反映条件・未知 Price は受理のみ、は不変 | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:204-370` |
| `app/Providers/AppServiceProvider.php:110` / `FakeExternalsServiceProvider` (改修) | bind 先を `StripeGatewayInterface → CashierStripeGateway` / fake は `FakeStripeGateway` へ | `/tmp/aigenba/app/Providers/AppServiceProvider.php:103`, `DuskFakesServiceProvider.php:60` |

**非スコープ (P2 で持ち込まない)**: `OnboardingBillingState` (Pending/ExpiredCheckout は subscription 用 `billing_checkout_sessions` が必要 → P3)、`startSignupCheckout` / `changePlan` / `upgradeNow` / schedule lifecycle / seat 系 / `recordFundingSnapshot` / `grantSignupInitialTickets` (P6)、`TicketCheckoutGateway` 系の統合 (監査 ticket-charge-* の action が「4 分割の境界のまま実装する」と明示。aigenba の単一 `StripeGatewayInterface` へのチケット側統合は行わない)。

#### 波及変更

- **TypeScript 型定義**
  - `resources/js/pages/Billing/Index.svelte`: `interface Props` の `currentPlanCode: string | null` を `effectivePlan: EffectivePlan` へ。`currentPlan` の `$derived` は `plans.find(p => p.code === effectivePlan.planCode)` に変更。表示は不変。
  - `resources/js/types/billing.ts` (新規, 既存 `types/dashboard.ts` と同じ「PHP DTO と対で保守する」規約): `EffectivePlanKind` union (`"paid_subscription" | "activated_personal" | "grandfathered_legacy_free" | "no_plan"`) と `EffectivePlan { kind; planCode: string | null; grantsAccess: boolean; deniedReason: string | null }`。
  - `resources/js/types/dashboard.ts` の `BillingSummary.has_billing_access`: **変更なし**(`BillingSummaryData` の形は不変)。
- **DTO / JsonResource**: 新規 = `EffectivePlan` (+3 variant) / `SubscriptionEntitlementDto` / `BillingStatusDto` / `SubscriptionSnapshot`。既存 `ExternalBillingRedirect` は据置(Gateway 戻り値契約として維持)。`BillingSummaryData` は据置。JsonResource の新設なし。
- **Inertia props**: `Billing/Index` の `currentPlanCode` → `effectivePlan` (DTO `toArray()` 経由)。`plans` / `ticketBalance` / `canManageBilling` は不変。他ページの props 変更なし。
- **テストファイル**
  - 更新: `tests/Feature/Billing/BillingPageTest.php` (props 名)、`tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php` (期待は不変。内部差し替えの回帰網)、`tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` (期待不変 = grandfathered variant で通ることの回帰)、`tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php` / `tests/Feature/Billing/WebhookIdempotencyTest.php` (snapshot 経由化の回帰。期待は不変)、`tests/Feature/Billing/PortalConfigurationTest.php` (spec 自体は不変。Service 委譲後も `sessionOptions` が同一引数で渡ることを固定)。
  - 新規: `tests/Unit/Billing/EffectivePlanTest.php` / `tests/Feature/Billing/EffectivePlanResolutionTest.php` / `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` / `tests/Feature/Billing/BillingPermissionServiceTest.php` / `tests/Feature/Organizations/OrganizationRenameStripeSyncTest.php` / `tests/Architecture/EffectivePlanSingleSourceTest.php` / `tests/Architecture/BillingSyncDispatchInvariantTest.php`。
  - Factory: `database/factories/OrganizationFactory.php` に P1 列を使う state (`activatedPersonal()` / `grandfatheredFree()` / `paid(string $planCode)`) を追加(テストデータ手組み禁止のため)。

#### 主要な契約

```php
// App\DataTransferObjects\Billing
abstract readonly class EffectivePlan {
    abstract public function kind(): EffectivePlanKind;
    abstract public function grantsAccess(): bool;
    /** quota/plan 解決キー。grandfathered は null (= config('quota.fallback_plan') 適用 = 現行同値) */
    abstract public function planCode(): ?string;
    public function deniedReason(): ?EntitlementDeniedReason { return null; }
    /** @return array{kind: string, planCode: string|null, grantsAccess: bool, deniedReason: string|null} */
    public function toArray(): array;
}
final readonly class PaidSubscriptionPlan extends EffectivePlan {   // plan_code 非 null
    public function __construct(public string $planCode, public SubscriptionEntitlementDto $entitlement) {}
}
final readonly class ActivatedPersonalPlan extends EffectivePlan {  // free_plan_code='personal' + declarer 有
    public function __construct(public int $declaredByUserId, public CarbonImmutable $declaredAt) {}
}
final readonly class GrandfatheredLegacyFreePlan extends EffectivePlan {} // declarer-less free (P4 backfill 済)
final readonly class NoPlan extends EffectivePlan {}                       // 未契約。**P4 でここだけ grantsAccess()=false** (D23)

final class SubscriptionService {
    public function __construct(private readonly StripeGatewayInterface $gateway) {}
    public function effectivePlan(Organization $org): EffectivePlan;              // 判定の唯一の生成経路
    public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto;
    public function getStatus(Organization $org): BillingStatusDto;
    public function applySubscriptionSnapshot(Organization $org, SubscriptionSnapshot $snap, bool $terminated): void;
    public function startCheckout(Organization $org, Plan $plan, string $successUrl, string $cancelUrl): ExternalBillingRedirect;
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect;
}
final class BillingAccess {                                          // 判定は委譲のみ
    public function hasActiveAccess(Organization $org): bool { return $this->subscriptions->effectivePlan($org)->grantsAccess(); }
}
```

**`effectivePlan()` の解決順 (P2 = 現行同値を満たす唯一の順序)**

| 条件 | variant | grantsAccess |
|---|---|---|
| `plan_code !== null` | `PaidSubscriptionPlan(deriveEntitlement(sub))`。`subscription('default')` 不在は `denied(Inactive, NoActiveSubscription)` (現行の fail-closed と同値) | `stripe_status ∈ {active,trialing}` のときのみ true |
| `plan_code === null` かつ `free_plan_code === PersonalPlanService::FREE_PLAN_CODE` かつ `personal_declared_by_user_id !== null` | `ActivatedPersonalPlan` | true |
| それ以外 (`plan_code === null`) | `GrandfatheredLegacyFreePlan` | **true (P2)。P4 でここが false になる** |

**`deriveEntitlement()`**: `entitled = SubscriptionState::fromSubscription($sub)->grantsAccess()` かつ `SubscriptionState::Active` のみ true。`paused` → `denied(Paused, Paused)`、`past_due` / その他 → `denied(state, NoActiveSubscription)`。**aigenba の「PastDue かつ PM 有りは entitled=true」は `subscriptions.has_payment_method` が無く、かつ現行 AI-CUE が past_due を遮断しているため P2 では採らない**(挙動不変。open question 参照)。

**DB 列 / index / ルート**: **P2 での追加・変更なし**(migration ゼロ。P1 の列を読むだけ)。ルート変更なし(`/billing`, `/billing/checkout`, `/billing/portal` のまま)。`manage-billing` permission 行は seeder (`database/seeders/PermissionSeeder` 相当、`manage-api-keys` の隣) に追加する。

#### PHPStan 適合チェック (level 10)

- `EffectivePlan` は abstract readonly + final variant。呼び出し側は `match(true)` ではなく `instanceof` narrowing で扱い、`grantsAccess()` / `planCode()` の抽象宣言により網羅漏れがコンパイル時に潰れる。`kind()` の enum 化で props 側の string も型付け。
- `Organization::subscription('default')` は Cashier 由来で `Subscription|null` (自前 `App\Models\Billing\Subscription` に `Cashier::useSubscriptionModel` で差し替え済み)。`deriveEntitlement` に渡す前に `$sub instanceof Subscription` で narrow (aigenba `BillingAccess.php:31` と同型)。**`?->` で握り潰さない**(不在は明示的に `denied(Inactive, NoActiveSubscription)` を返す)。
- `personal_declared_by_user_id` / `personal_declared_at` は `?int` / `?CarbonImmutable`。variant 構築時に `Assert::integer` / `Assert::notNull` で narrow してから `ActivatedPersonalPlan` の非 null プロパティへ渡す(DTO 側は null を受けない = 型で variant 不変条件を保証)。
- `toArray()` は各 DTO に `@phpstan-type ...Shape` を宣言し `@return ...Shape` で固定(既存 `BillingSummaryData` / aigenba `BillingStatusDto` と同じ様式)。Inertia props は DTO の `toArray()` のみを渡す(`response()->json()` 直書きなし)。
- `getDirectManageBillingMap(Organization $org, array $userIds): array` は `@param list<int>` / `@return array<int, bool>`。`DB::table('permission_user')->pluck()` の `mixed` は `Assert::integerish` 後に cast(`ApiKeyPermissionService::getDirectMap` と同一実装)。
- `SubscriptionSnapshot` の日時は `?CarbonImmutable` に正規化してから渡す(webhook payload の `data_get` は `mixed` → 既存 `stringAt()` / 新設 `epochAt()` helper で narrow)。
- config 読みは `config()->string('quota.fallback_plan')` 等の typed accessor を維持(`QuotaService` は P2 で触らない)。

#### テスト計画

**先に red を作る (テストファースト)**
1. `tests/Unit/Billing/EffectivePlanTest.php` — variant ごとの `grantsAccess()` / `planCode()` / `kind()` / `toArray()` 形状 (dataset)。クラス不在で red。
2. `tests/Feature/Billing/EffectivePlanResolutionTest.php` — **挙動同値表**を固定する回帰:
   - `plan_code=null` / `free_plan_code=null` → `GrandfatheredLegacyFreePlan` + `hasActiveAccess()=true`
   - `plan_code=null` / declarer 付き personal → `ActivatedPersonalPlan` + true
   - `plan_code=null` / declarer-less personal (P4 backfill 相当) → `GrandfatheredLegacyFreePlan` + **true (P2 時点)**
   - `plan_code='standard'` × `stripe_status ∈ {active,trialing,past_due,canceled,incomplete,paused}` × subscription 行不在 → `PaidSubscriptionPlan` + `entitled` が現行 `GRANTING_STATUSES` と一致 / `deniedReason` が付く
   (Factory state から生成。手組み禁止)
3. `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` — webhook payload → `SubscriptionSnapshot` → `applySubscriptionSnapshot` で `plan_code` / `current_period_end` が現行と同一に落ちる。`deleted` (terminated=true) で `plan_code=null`。未知 Price は無変更。非 active/trialing status は無変更。
4. `tests/Architecture/EffectivePlanSingleSourceTest.php` — `app/` 配下で `plan_code` / `free_plan_code` / `personal_declared_*` を**直接読む**のは allowlist (`StripeWebhookProcessor`=writer / `SubscriptionService`=判定生成 / `QuotaService`=map lookup / `Organization` model / `Filament/Resources/OrganizationResource`=admin 表示) のみ。新規の raw 分岐を CI で構造的に禁止。
5. `tests/Architecture/BillingSyncDispatchInvariantTest.php` — `SyncBillingCustomerDetails::dispatch` の呼び出し元は `BillingCustomerSynchronizer` のみ (aigenba IV-2 の移植)。

**既存テストの更新 (削除しない)**
- `tests/Feature/Billing/BillingPageTest.php`: `where('currentPlanCode', null)` → `where('effectivePlan.planCode', null)` + `where('effectivePlan.kind', 'grandfathered_legacy_free')` / `where('effectivePlan.grantsAccess', true)`。fake gateway の bind 名を `StripeGatewayInterface` → `FakeStripeGateway` に更新(中立帰還 URL の期待は不変)。
- `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php` / `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`: **期待を 1 行も変えずに green を維持**(P2 の DoD = 挙動不変の証明)。
- `tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php` / `WebhookIdempotencyTest.php`: 期待不変。内部が Service 経由になっても event_id 冪等・plan_code 同期条件が保たれること。
- `tests/Feature/Billing/PortalConfigurationTest.php`: `PortalConfigurationSpec` の期待は不変。Service 委譲後も `sessionOptions(config('cashier.portal_configuration_id'))` が Gateway に渡ることを 1 ケース追加。

**新規 (機能追加分)**
- `tests/Feature/Billing/BillingPermissionServiceTest.php`: grant/revoke → `hasDirectPermission`、非メンバーは `DomainException` (grant) / false (has)、`canEditWithKnownRoles` のロールマトリクス、`getDirectManageBillingMap` の N+1 なし一括取得。加えて **Policy 回帰: 直接付与ゼロの状態で `manageBilling` の結論が現行 (owner/admin のみ) と同一**、直接付与された member は `/billing/checkout` が 403 にならない。
- `tests/Feature/Organizations/OrganizationRenameStripeSyncTest.php`: `Queue::fake()` で (a) name 変更時のみ `SyncBillingCustomerDetails` が dispatch、(b) 同名 save では dispatch なし、(c) `stripe_id === null` は no-op、(d) transaction rollback 時に発火しない (`afterCommit`)。

#### リスク

| リスク | 緩和 |
|---|---|
| **判定の集約で結論がずれる**(特に `plan_code` 非 null + subscription 行不在の fail-closed、`past_due`) | 上記 (2) の同値表テストで現行 `GRANTING_STATUSES` を dataset として固定。`SeededFreePlanBillingAccessTest` / `RequireActiveSubscriptionMiddlewareTest` を無改変で green に保つことを DoD にする |
| **P2 で `GrandfatheredLegacyFreePlan` が「未契約(free_plan_code なし)」も飲み込む** → P4 で false にすると 2 状態が同時に反転する | variant を P4 でさらに分けるのではなく、**P4 は本 variant の `grantsAccess()` を false にするだけ**にし、backfill 済み org は `ActivatedPersonalPlan` ではなく declarer-less の別 variant で救う必要がある。→ **P4 で `GrandfatheredLegacyFreePlan` (= backfill 済み declarer-less personal) と `NoPlan` (= free_plan_code すら無い) を分離する**前提を P2 の解決順コメントに明記し、EffectivePlanResolutionTest に「declarer-less personal」ケースを P2 時点から入れておく (P4 の diff を 1 行にする) |
| `StripeWebhookProcessor` からの書込移設で webhook の順序逆転耐性が退行 | 既存 `WebhookEventSubscriptionInvariantTest` / `WebhookIdempotencyTest` を無改変で維持。`applySubscriptionSnapshot` に現行の反映条件 (active/trialing のみ・未知 Price は受理のみ) をそのまま持ち込み、条件判定は Processor ではなく Service 側に一元化 |
| **rename 時に Stripe API 呼び出しが増える**(現行は customer 同期なし) = 外部副作用の新規発生 | job 化 + `stripe_id === null` no-op + fake 環境は `FakeStripeGateway::syncCustomerDetails()` で no-op。名前が実際に変わったときのみ dispatch (`isDirty('name')`)。bughunt/fake_externals で実 Stripe に到達しないことを arch/feature テストで固定 |
| `manageBilling` への直接付与 OR 追加で認可が緩む | 付与経路 (route/UI/action) を P2 に含めない = 直接付与は生成されない。Policy 回帰テストで「付与ゼロなら結論は現行と同一」を固定。非メンバーは role null で早期 false (`manageApiKeys` と同型) |
| Gateway rename で fake bind 漏れ (bughunt 環境が実 Stripe を叩く) | `AppServiceProvider` / `FakeExternalsServiceProvider` の bind を同一 PR で更新し、`BillingPageTest` の fake 経由 happy path 2 本 (checkout / portal) が中立帰還 URL を返すことで検出 |
| `EffectivePlan` の props 露出で UI が先走る (UI parity は P8b) | P2 は prop 名の差し替えのみ。`Billing/Index.svelte` の描画・分岐は追加しない (kind による出し分けは P3/P8b) |

##### 起草時の未決事項（上位決定は冒頭 §横断決定 / §ユーザー判断を要する残件 を参照）

- Gateway 系の「置換」範囲: 監査 billing-subscription-4 / ticket-charge-* の action は「AI-CUE の Gateway 抽象 + Fake は廃止せず、SubscriptionService から Gateway を呼ぶ形に寄せる」「チケット系 4 分割の境界は維持する」と明記している。本設計はこれに従い、aigenba の巨大な単一 StripeGatewayInterface (30+ メソッド、seat/schedule/auto-recharge を含む) へは統合せず、namespace と命名 (Contracts\StripeGatewayInterface / CashierStripeGateway / createPortalSession) のみ aigenba 形に寄せ、メソッドは現行 2 本 + syncCustomerDetails に限定した。この「形だけ parity・粒度は AI-CUE 維持」で良いか (完全 verbatim を求めるなら P5/P8 のチケット・裏チャージ側の interface 統合まで一括で決める必要がある)。
- past_due の entitlement: aigenba は「PM 登録済みの past_due は利用継続 (grantsAccess=true)」だが、AI-CUE 現行は past_due を遮断し、subscriptions テーブルに has_payment_method 列も無い。P2 = 挙動不変のため現行 (遮断) を維持したが、aigenba parity としては差分が残る。列追加 + 挙動変更を後続フェーズ (P4 と同一 PR か、独立 TODO) に置くかの判断が要る。
- BillingPermissionService の付与導線: aigenba は routes/web.php:365 organizations.members.update-billing-permission + メンバー管理 UI を持つ。監査 billing-subscription-9 は「委譲を許すか自体は人が決める」と product decision に分類。P2 では service + Policy の OR 参照のみ (付与ゼロ = 挙動不変) とし、付与 route/UI をどのフェーズに置くか (P8b か、独立か、そもそも入れないか) は未決。
- BillingCustomerSynchronizer の発火点: aigenba は UpdateBillingContactAction と RenameOrganizationAction の 2 経路だが、AI-CUE には billing_contact_email/name 列も更新 UI も無い (監査 billing-subscription-10 = medium/未フェーズ割当)。P2 では rename 経路のみを配線した。請求先列 + BillingContactForm の parity をどのフェーズに入れるか未決 (入れないなら Synchronizer の存在価値は rename 同期のみに縮む)。
- QuotaService と 'personal' プランキー: QuotaService は config('quota.plans')[$planCode] を引き、未知キーは「limits 無し = 無制限」に倒れる。P1 で PlanCode::Personal / Plan seeder が入るとき config/quota.php にも 'personal' の limits を追加しないと、ActivatedPersonalPlan の org が無制限になる。この config 追加は P1 の責務という前提で P2 は QuotaService を触っていないが、P1 詳細設計に含まれるか要確認 (含まれないなら P2 か P4 に持つ)。
- Billing/Index の props: currentPlanCode (scalar) を effectivePlan (DTO) に置換して既存 BillingPageTest の期待を更新する方針にしたが、UI parity は P8b の担当。P8b で aigenba の Billing/Plans + PlanCard 構成へ差し替えるなら、P2 で props を一度変えてから P8b で再度変えることになる (2 度手間)。P2 は currentPlanCode を据置き、props 差し替えを P8b に寄せる選択肢もある。

---

### P3 Onboarding 最小導線（ゲート反転より前に導線を実在させる = F-07 再発防止の条件 A）

> 位置づけ: **導線を足すだけ**。`BillingAccess` / `RequireActiveSubscription` は一切触らない（P4 の責務）。
> 単独マージ後も既存挙動は不変（新 route を誰も踏まないため）。

#### 変更箇所

| AI-CUE (新規/変更) | 移植元 aigenba | 何をするか |
|---|---|---|
| `routes/web.php`（auth group 内、`require-active-subscription` group の**外**。既存 `organizations.onboarding.{mcp,cli}`(L293-296) の直後） | `/tmp/aigenba/routes/web.php:442-449` | **D21/D6 適用（route parameter を持たない current-org スコープ）**: `GET /onboarding/checkout` → **`onboarding.checkout`** / `POST /onboarding/activate-personal`（`throttle:10,1`）→ **`onboarding.activate-personal`** / `GET /billing-required` → **`onboarding.billing-required`**。組織は既存 `ResolvesCurrentOrganization` から解決し、**`{organization:slug}` バインドは使わない**（AI-CUE の課金 route は current-org スコープ = `GET /billing`・`POST /billing/checkout` と対称にするため。aigenba の org-slug 化は AI-CUE 全体の route 規約変更になりスコープ外） |
| `app/Http/Controllers/Onboarding/OnboardingController.php`（新規名前空間） | `app/Http/Controllers/Onboarding/OnboardingController.php` | プラン選択画面 render。`show(Organization, Request)` |
| `app/Http/Controllers/Onboarding/ActivatePersonalController.php` | 同名 | `__invoke(ActivatePersonalRequest, Organization)` → P1 `PersonalPlanService::activate()` |
| `app/Http/Controllers/Onboarding/BillingRequiredController.php` | 同名 | 未申告 + manage-billing なし member 向け説明画面 |
| `app/Http/Requests/Onboarding/ActivatePersonalRequest.php` | 同名 | `declaration` 自己申告のみ（`funding_choice`/`consent_version` は P7/P8a で追加）。`ProhibitsProtectedKeys` を配線（`FormRequestProhibitedKeyTest` 対応） |
| `app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php` | 同名 | Checkout の pageData |
| `app/DataTransferObjects/Onboarding/BillingRequiredDto.php` | 同名 | ownerName / ownerEmail / contactUrl |
| `app/DataTransferObjects/Billing/PlanDto.php`（**新規**。AI-CUE に PlanDto は不在） | `app/DataTransferObjects/Billing/PlanDto.php` | `fromModel(Plan)`。**AI-CUE の `Plan` 列に合わせる**（後述） |
| `resources/js/pages/Onboarding/Checkout.svelte` | 同名(643行) | P3 部分のみ移植（plan grid + personal 自己申告 step）。funding 2 択 / 折りたたみ確認 / intended バッジは P7・P8a |
| `resources/js/pages/Onboarding/BillingRequired.svelte` | 同名(53行) | ほぼ verbatim。`GuestLayout` は AI-CUE 版が `appName` 必須のため shared props 由来で渡す |
| `resources/js/types/onboarding.ts`（新規） | — | PHP `@phpstan-type` shape と exact 対（`types/billing.ts` の既存規約に従う） |

**名前空間衝突の回避（aigenba の docblock と同一の理由）**: 既存 `App\Http\Controllers\Organizations\OrganizationOnboardingController`（MCP/CLI 手順）と `resources/js/pages/Organizations/Onboarding/{Mcp,Cli}.svelte` は**触らない**。課金オンボーディングは `App\Http\Controllers\Onboarding\*` / `resources/js/pages/Onboarding/*` に分離する（aigenba と同じ階層分離）。route name も `organizations.onboarding.{mcp,cli}` と衝突しない。

#### 波及変更

- **TypeScript 型定義**: `resources/js/types/onboarding.ts` 新規（`OnboardingCheckoutShape` / `BillingRequiredShape` / `PlanShape` / `PersonalPlanEligibilityShape`）。`resources/js/types/billing.ts` は変更なし（P8b で `ticketCount.ts` 等と併せて整理）。
- **DTO**: 新規 `OnboardingCheckoutDto` / `BillingRequiredDto` / `PlanDto`。P1 産出物 `PersonalPlanEligibilityDto` を再利用（新規作成しない）。**JsonResource は使わない**（Inertia ページのため DTO→`toArray()`。`response()->json()` 直書きなし）。
- **Inertia props**: `Onboarding/Checkout` = `{ organization: {id,name,slug}, pageData: OnboardingCheckoutShape }`、`Onboarding/BillingRequired` = `{ organization, pageData: BillingRequiredShape }`。organization props は既存 `OrganizationOnboardingController::organizationProps()` と同形（AI-CUE に `OrganizationDto` は不在。P3 で新設しない）。
- **テストファイル（新規）**: `tests/Feature/Onboarding/OnboardingCheckoutTest.php` / `tests/Feature/Onboarding/ActivatePersonalTest.php` / `tests/Feature/Onboarding/BillingRequiredTest.php` / `tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php` / `tests/js/pages/OnboardingCheckout.test.ts`。
- **テストファイル（更新）**: **なし**（`RequireActiveSubscriptionMiddlewareTest` / `SeededFreePlanBillingAccessTest` は P4 の更新対象。P3 では期待が変わらない）。arch テストは inventory 追加不要 — `NestedRouteIdorDefenseTest` は route param 2 個以上が対象で本 route は 1 個、`OrganizationRouteParamWebOnlyInvariantTest` は web+auth group 内なので自動 pass、`page-shell-structure` は `AppLayout` を import するページのみ対象で本 2 ページは `GuestLayout`（aigenba と同一）のため対象外。
- **Factory**: `Plan` に factory が無い（`database/seeders/PlanSeeder.php` が真実源）。テストは PlanSeeder + P1 の `PlanFactory`（P1 が `personal`/`starter` を追加）に依存する。P1 が factory を作らない場合は P3 で `database/factories/Billing/PlanFactory.php` を新設する（テストデータ手組み禁止のため）。

#### 主要な契約

```php
// App\Http\Controllers\Onboarding\OnboardingController
public function show(Organization $organization, Request $request): Response|RedirectResponse|SymfonyResponse
//  1. Gate::authorize('view', $organization)            ← IDOR 防御を最優先 (aigenba R1 Critical #F-3)
//  2. 申告済み (paid active/trialing || free_plan_code != null) → Inertia::location(route('billing.index'))
//  3. ! Gate::allows('manageBilling', $organization)    → redirect onboarding.billing-required   (D21)
//  4. Inertia::render('Onboarding/Checkout', [...])

// App\Http\Controllers\Onboarding\ActivatePersonalController
public function __invoke(ActivatePersonalRequest $request, Organization $organization): RedirectResponse
//  Gate::authorize('manageBilling', ...) → PersonalPlanService::activate($org, $user)
//  PersonalPlanNotEligibleException → ValidationException::withMessages(['plan_code' => $e->userMessage()])
//  成功 → redirect()->route('dashboard')->with('success', $result->granted ? '…無料チケット N 枚…' : '…')

// App\Http\Controllers\Onboarding\BillingRequiredController
public function show(Organization $organization): Response|RedirectResponse
//  Gate::authorize('view') → 申告済み → redirect dashboard / manageBilling 保持 → redirect checkout
//  owner = $organization->users()->get()->first(fn (User $u) => $u->organizationRole($organization) === OrganizationRole::Owner)
//         ※ Organization::routeNotificationForMail() (app/Models/Organization.php:164-172) と同一の解決パターン
```

**重要な接地上の逸脱（意図的・P3 の中核設計判断）**: aigenba は入口ガードに `BillingAccess::hasActiveAccess()` / `state()->grantsAccess()` を使うが、**AI-CUE でこれを機械移植すると P3 で導線が到達不能になる**。理由: 現行 `app/Services/Billing/BillingAccess.php:41`「`plan_code === null` → true」により、**P3 時点の全未契約 org が `hasActiveAccess()=true`** → `show()` が常に `billing.index` へ redirect し、checkout 画面を誰も踏めない（= 条件 A が実質未達のまま P4 に入る）。aigenba では entitlement と「プラン申告済みか」が同値なため差が出ないが、AI-CUE の暗黙 free 期にはこの 2 つが乖離する。
→ **入口ガードは entitlement ではなく「明示的プラン申告の有無」で判定する**: `paid subscription(active/trialing) が有る || organizations.free_plan_code !== null`。P2 の `EffectivePlan` DTO にこの述語（例 `EffectivePlan::isDeclared()`）を置き、P3 は `BillingAccess` を**読まない**（`BillingAccess` は P4 で反転され、その後は両者が同値に収束する = P4 後にこの分岐は自然に aigenba と一致する）。

**DTO 形状（P3 スコープ。フィールド名は aigenba と同一にし、後続フェーズは additive に足すだけにする）**

```
OnboardingCheckoutShape = {
  plans: PlanShape[], recommendedPlanCode: string, defaultPlanCode: string,
  contactUrl: string,                  // ContactUrl::forSource(InquirySource::Onboarding)->url  (既存)
  attemptToken: string,                // Str::ulid() / render ごと固定
  personalEligibility: {eligible, reason, reasonLabel}|null,   // P1 PersonalPlanService::eligibility
  signupGrantTickets: int,             // config('billing.signup_grant_tickets') (既定 10)
}
PlanShape = { code, name, monthlyTicketGrant: int, currentBaseAmount: int|null, currency: string|null, sortOrder: int }
BillingRequiredShape = { ownerName: string|null, ownerEmail: string|null, contactUrl: string }
```

- **PlanDto は aigenba の列を機械移植しない**: aigenba `PlanDto` の `includedSeats` / `includedMonthlyTickets` / `scenarioLimit` / `courseLimit` / `currentSeatAmount` は **AI-CUE の `app/Models/Billing/Plan.php` に存在しない列**（AI-CUE は `code / name / monthly_ticket_grant / sort_order`、能力は `config/quota.php` の値で表現するのが既存規約）。席課金は概念設計でスコープ外。→ AI-CUE の実列にマップする。
- **`currentSeatCount` / `starterAutoMigrationDays` は持たない**（席概念なし / Starter 自動移行は aigenba 固有機能で本設計のスコープ外）。
- **`intendedPlanCode` / `preselectFunding`（P7）、`autoRechargeTerms` / `funding_choice`（P8a）は P3 では持たない**。

**DB 列 / index**: **P3 は 0**（P1 が `free_plan_code` 等と partial unique index を追加済み）。

~~**route scoping の非対称**~~ → **D21/D6 で解消**。onboarding route は **route parameter を持たない current-org スコープ**とし、既存 `billing.checkout`（current org に課金）と**同一の組織解決**（`ResolvesCurrentOrganization`）を使う。したがって「URL の org ≠ current org」という状態が**構造的に発生せず**、cross-org 課金バグの余地が無い。`isCurrentOrganization` prop・組織切替 CTA・org-slug 非対称リスクは**すべて削除**する。

**UI 契約**: 両ページとも `GuestLayout`（aigenba verbatim）。`PricingPlanCard`（`resources/js/components/molecules/PricingPlanCard.svelte` — 既に aigenba から移植済み・DTO 非依存 primitive props）を再利用し、**新規 molecule を作らない**。lucide は `@lucide/svelte` からの named import のみ（`lucide-scoped-import`）、色は token のみ・hex 直書き禁止（`ds-purity`）、import 方向は pages → templates/molecules/atoms のみ（`atomic-import-graph`）。

#### PHPStan 適合チェック（level 10）

- Controller 戻り値: `show(): Response|RedirectResponse|SymfonyResponse`（`Inertia::location()` は `SymfonyResponse`）、`__invoke(): RedirectResponse`。**`response()->json()` 不使用**。
- `$request->user()` は `Assert::isInstanceOf($user, User::class)`（既存 `BillingController` と同パターン）で narrowing。`abort_if`/`abort_unless` に頼らない。
- `config('billing.signup_grant_tickets')` は `mixed` → `Assert::integer()` で narrowing（`TicketLedgerService.php:93-95` と同一パターン。P1 の `TicketService::signupGrantTicketCount(): int` が既にあるならそれを注入し、config 直読みを増やさない）。
- DTO は全て `final readonly` + `@phpstan-type ...Shape` + `toArray(): ...Shape`。`PlanDto` は他 DTO から `@phpstan-import-type PlanDtoShape` 可能な形にする。
- `list<PlanDto>` を保つため `->map(...)->values()->all()`（aigenba と同一。`values()` を省くと `array<int,PlanDto>` に落ちて level 10 で落ちる）。
- `Plan::query()->where('is_active'...)` は AI-CUE の `Plan` に `is_active` 列が無い → **移植しない**（`orderBy('sort_order')` = 既存 `BillingController::index` と同じ）。存在しない列を書くと PHPStan larastan の model property 解決で落ちる。
- owner 解決の `->first(fn (User $u): bool => ...)` は `?User` → `$owner instanceof User ? $owner->name : null`（aigenba と同形）。
- `PersonalPlanNotEligibleException::userMessage(): string`（P1 産出）を前提。**baseline / widen 禁止**を遵守。

#### テスト計画（先に red を作る）

**先に red（新規 Feature）**

1. `tests/Feature/Onboarding/OnboardingCheckoutTest.php`
   - 非メンバーは **404**（`MembershipScopedOrganizationBinder` = 存在秘匿。403 にしない）
   - 未申告 + `manageBilling` なし member → `onboarding.billing-required` へ redirect（D21）
   - 未申告 + owner → `Onboarding/Checkout` を render / `pageData.plans` が PlanSeeder の並び（`sort_order`）で来る / `attemptToken` 非空 / `personalEligibility` 非 null / `signupGrantTickets === config('billing.signup_grant_tickets')`
   - **申告済み（`free_plan_code='personal'` / active subscription）→ `billing.index` へ**
   - **F-07 条件 A の直接検証（本フェーズの要）**: `plan_code IS NULL` かつ `free_plan_code IS NULL` の org（= P3 時点の既存全 org）で checkout が **200 で render される**（= `BillingAccess::hasActiveAccess()` を入口ガードに使っていたら必ず red になるテスト。上記「意図的な逸脱」を機械固定する）
   - （D21 により `isCurrentOrganization` は廃止。current-org 解決のため当該ケースは発生しない）
2. `tests/Feature/Onboarding/ActivatePersonalTest.php`
   - `declaration` 未チェック → redirect-back + `errors.declaration`（422 相当。手組みデータなし = Factory）
   - `manageBilling` なし member → **403**（`view` は通るが activate は不可）
   - 非メンバー → 404
   - 成功 → `organizations.free_plan_code='personal'` / `personal_declared_by_user_id` が declarer / `dashboard` へ redirect + success flash / signup grant 付与（P1 の marker 経路）
   - **二重 POST 冪等**: 2 回目は `granted=false` 文言 + `ticket_ledger_entries` の `signup_grant:%` が 1 行のまま（AGENTS.md #7 と P1 marker の回帰）
   - eligibility 不成立（別 free personal org 保有）→ redirect-back + `errors.plan_code` に**サーバー確定文言**（500 にしない）
   - `throttle:10,1` が効く（11 回目 429）
3. `tests/Feature/Onboarding/BillingRequiredTest.php`
   - 非メンバー 404 / 申告済み → `dashboard` / `manageBilling` 保持者 → checkout（**離脱ガード = F-3-02 の「行き先のない詰み」回避**）
   - owner 不在（owner が抜けた org）で `ownerName`/`ownerEmail` が null でも 200（`Organization::routeNotificationForMail` と同じ null 許容）
4. `tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php`: `fromModel()` が `monthly_ticket_grant` / 現行 base price（`PlanPriceKind::Base` かつ `is_current`）をマップ / base price 不在 → `currentBaseAmount === null`（= 無料表示契約）
5. `tests/js/pages/OnboardingCheckout.test.ts`（**D4 適用: disabled でブロックしない**）: `personalEligibility.eligible=false`
   のとき personal CTA は**押せる状態を維持**し、**押下後に `reasonLabel` を表示**する（文言はサーバ由来。フロントで組み立てない） /
   `declaration` 未チェックでも submit は**押せ**、押下後に validation error を表示する / （`isCurrentOrganization` は D21 により廃止）

**既存テストの更新対象**: なし（削除も一切なし）。arch テスト（`page-shell-structure` / `ds-purity` / `atomic-import-graph` / `lucide-scoped-import` / `FormRequestProhibitedKeyTest` / `NestedRouteIdorDefenseTest` / `OrganizationRouteParamWebOnlyInvariantTest`）は**追加なしで green のままであること**を DoD にする（allowlist 追加が必要になった時点で設計を疑う）。

#### リスク

| リスク | 緩和 |
|---|---|
| **入口ガードを aigenba 機械移植すると P3 の導線が到達不能**（`plan_code null → hasActiveAccess=true` で常に billing.index へ）。気付かず P4 に進むと**条件 A が未達のまま反転 = F-07 再発** | 「未申告 org で checkout が 200」を Feature テストで先に red 化（テスト計画 1 の 5 番目）。入口ガードは `EffectivePlan::isDeclared()` を使い `BillingAccess` を読まない |
| ~~**cross-org 課金**~~ → **D21 で構造的に解消**（onboarding route を route parameter 無しの current-org スコープにしたため「URL の org ≠ current org」が発生しない） | — |
| P1 未マージ（`PersonalPlanService` / `PlanCode` / `free_plan_code` / `personal` プラン seed）だと P3 が実装できない | **依存を明示**: P3 は P1 マージ後に着手。`activate-personal` は P1 の `activate()` を呼ぶだけで**付与ロジックを再実装しない**（二重付与源を作らない） |
| 名前空間衝突（`organizations.onboarding.*` に MCP/CLI が既存） | route name が `{mcp,cli}` と `{checkout,activate-personal,billing-required}` で非衝突。Controller/ページも `Onboarding\*` と `Organizations\*` で階層分離（aigenba と同一の分離理由） |
| `Onboarding/Checkout.svelte` を 643 行 verbatim 移植すると P7/P8a の未実装機能（funding 2 択・同意・intended バッジ）を先取りして壊れる | P3 は plan grid + personal 自己申告 step のみ。**フィールド名は aigenba と同一**にし、P7/P8a は DTO/TS に additive に足すだけにする（再設計コストゼロ） |
| Personal 有効化後の着地が `dashboard` 固定（aigenba は `OnboardingReturnResolver` で復帰） | P7 で `OnboardingReturnResolver` を移植し着地を差し替える。P3 では gate が未反転 = 「gate に奪われた destination」がまだ存在しないため機能欠落にならない |
| `Plan` factory 不在でテストデータ手組みの誘惑 | P1 の `PlanFactory` に依存。無ければ P3 で新設（禁止事項: テストデータ手組み） |

##### 起草時の未決事項（上位決定は冒頭 §横断決定 / §ユーザー判断を要する残件 を参照）

- P2 の EffectivePlan DTO に「明示的プラン申告済みか」(paid active/trialing || organizations.free_plan_code IS NOT NULL) の述語を置けるか。P3 の入口ガードはこれに依存する。BillingAccess::hasActiveAccess() を aigenba どおり使うと、P3 時点では全未契約 org が true になり checkout 画面が到達不能 = 条件 A が実質未達のまま P4 に入る。P2 側でこの述語名 (例: isDeclared()) を確定してほしい。
- AI-CUE の課金 route は current-org スコープ (GET /billing, POST /billing/checkout)、aigenba は org-slug スコープ (/organizations/{slug}/billing/*)。P3 の onboarding route は aigenba 準拠で org-slug にしたため非対称が生じる。P3 は「current org と一致するときのみ有償 CTA を出す」で回避するが、恒久的に billing 系全体を org-slug へ寄せるか (= P7 の signup-checkout / P8b の Billing/Plans と同時) の判断が要る。
- PlanDto の aigenba 列 (includedSeats / scenarioLimit / courseLimit / currentSeatAmount / is_active) は AI-CUE の Plan モデルに存在せず、席課金は概念設計でスコープ外。AI-CUE 実列 (code/name/monthly_ticket_grant/sort_order + 現行 base price) のみにマップする方針で確定してよいか (aigenba 形の機械移植をしない例外を 1 件追加することになる)。
- OnboardingCheckoutDto の recommendedPlanCode / defaultPlanCode の値。aigenba は Standard / Starter だが、AI-CUE の P1 後のプラン集合 (現行 free/standard に personal/starter を追加) での既定値・推奨値が未定。P1 の PlanSeeder 確定内容に依存する。
- PersonalPlanService::MAX_MEMBERS = 3 (aigenba) を AI-CUE でも同値にするか。Checkout 画面の ineligible 理由文言 (TooManyMembers) に表示されるため、P1 の決定を P3 の UI 文言が引き継ぐ。
- Personal 有効化成功時の着地。aigenba は OnboardingReturnResolver で復帰先を解決するが P7 の産出物のため、P3 では dashboard 固定にする。P3 単独の UX として許容でよいか (P4 のゲート反転前は「奪われた destination」が存在しないため実害はない想定)。

---

### P4

> **D11 の実変更（Codex Round 2 Critical: 決定が変更一覧に落ちていなかった）**: 本フェーズで
> **既存 `free` Plan 行と `config/quota.php` の `fallback_plan='free'` を撤去**する（ゲート反転と同一の意味変更のため同一 PR）。
> - `database/seeders/PlanSeeder.php`: `free` 行を削除（`personal` が後継）。
> - `config/quota.php`: `fallback_plan` を **`'personal'`** へ切替（grandfathered org の quota キーは **`personal`**。
>   旧 `free` の limits 値と同値のため実効 limits は不変 = ユーザー影響なし）。
> - `app/Services/Quota/QuotaService.php`: 解決先が `personal` になることの回帰テスト。
> - `database/factories/OrganizationFactory.php` / 既存テストの `'free'` 参照を `personal` へ更新（**削除しない**）。
> - `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`: `free` 消滅に伴い **`personal` 前提へ期待を更新**。
: ゲート反転 + grandfathering 移行

前提: P1 (列 + partial unique index + `PersonalPlanService`) / P2 (`BillingAccess::state()`) / P3 (onboarding 導線) がマージ済み。
P4 は **判定の結論を変える最初のフェーズ**であり、`BillingAccess` の暗黙 free 許可撤去・`RequireActiveSubscription` の分岐反転・
既存 org の declarer-less backfill の 3 点のみを行う (新機能を足さない)。

#### 変更箇所 (ファイルパス + 何をするか。移植元 aigenba のパスを併記)

| ファイル | 変更 | 移植元 |
|---|---|---|
| `/workspace/app/Services/Billing/BillingAccess.php` | `hasActiveAccess()` の `plan_code === null → true` を**撤去**し `state($org)->grantsAccess()` へ委譲するだけの薄い façade にする。クラス docblock の「意図的な書き換え (devnotes/20260712-0927-bugfix-billing-free-access)」節を**反転記録**(本設計を正とする旨 + 旧 devnote は歴史として保持)へ差し替え | `/tmp/aigenba/app/Services/Billing/BillingAccess.php:26-29` |
| `/workspace/app/Http/Middleware/RequireActiveSubscription.php` | 遮断分岐を反転。`state()->grantsAccess()` で通過判定 → 不許可時は `manageBilling` 保持者を `onboarding.checkout`、非保持者を `onboarding.billing-required` へ。`billing.index` + `error` flash の誘導を廃止。`resolveOrganization()` (route binding 優先 → `currentOrganization` → null は素通し) と非メンバー 404 defense-in-depth と `session()->reflash()` は**現行のまま維持** (AI-CUE は current-org スコープ route のため aigenba の `{organization}` 前提を機械移植できない) | `/tmp/aigenba/app/Http/Middleware/RequireActiveSubscription.php:60-91` |
| `/workspace/database/migrations/2026_07_1X_XXXXXX_backfill_grandfathered_free_plan_code.php` (新規・data migration) | 分類表に従い `free_plan_code='personal'` / `free_plan_activated_at=now()` / `personal_declared_by_user_id=NULL` / `personal_declared_at=NULL` を chunk 更新。**grant は発火しない** (`signup_tickets_granted_at` に触れない)。末尾で残余件数を検証し 0 でなければ `throw` (= migrate 失敗 = 新リリース非活性化) | 構造は `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php` (分離 data migration + `down()` no-op) に倣う。aigenba 自体は gate 有り前提のスタートのため grandfather backfill 相当は無く、**移行データ固有の追加** |
| `/workspace/database/seeders/ManualTestSeeder.php` | Free (Price 無し) プラン組織を `PersonalPlanService::activate($org, $owner)` 経由で有効化する (`plan_code` は null のまま)。手動テスト環境が反転後に締め出されないため | — (AI-CUE 固有 fixture) |
| `/workspace/database/factories/OrganizationFactory.php` | `freePersonal()` state を追加 (`free_plan_code='personal'` / `free_plan_activated_at` / `personal_declared_at`。declarer は afterCreating で個別設定) | `/tmp/aigenba/database/factories/OrganizationFactory.php:66-73` (verbatim) |
| `/workspace/tests/Pest.php:127-133` | `createOrganizationWithOwner(string $name, bool $activateFreePlan = true)` に拡張し、既定で `PersonalPlanService::activate($org, $owner)` を実行。106 テストファイルの業務ルート到達を反転後も維持する。ゲート/onboarding テストは `activateFreePlan: false` で未契約 org を作る。docblock の「生成される組織は Free (未契約 = plan_code null)」を更新 | — |

**P4 に含めない** (フェーズ境界の明示): `OnboardingReturnResolver` による遷移先記憶 (aigenba middleware L79-81 = P7)、`IntendedPlanResolver`、`QuotaService` の free_plan_code 解決 (grandfathered org は `plan_code` が null のままなので `quota.fallback_plan` が今日と同じ限度を返す = 無変更が正)。

#### 波及変更

- **TypeScript 型定義**: なし (P4 は Inertia ページを新設・改変しない。遮断先ページは P3 成果物)。
- **DTO / JsonResource**: なし (`BillingAccess::effectivePlan()` が返す `EffectivePlan` は P2 成果物。**D18: 判定源は `EffectivePlan` に単一化し `OnboardingBillingState` は導入しない**)。
- **Inertia props**: なし。ただし遮断理由の提示は P3 の `Onboarding/Checkout` / `Onboarding/BillingRequired` の props に依存する (F-07 テスト (c) の assert 対象)。P4 は middleware から `->with('error', ...)` を渡さない (aigenba 同様、理由は着地ページが持つ)。
- **テストファイル (更新)**:
  - `/workspace/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php` (**削除せず反転後の期待へ更新**)
  - `/workspace/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` (同上。「free 組織が素通りする」→「activate 済み free 組織が到達する」)
  - `/workspace/tests/Pest.php` (helper 既定の変更)
  - `Organization::factory()` を直接使い業務ルートを叩く 7 ファイルの棚卸し: `tests/Feature/Organization/OrganizationSwitchTest.php` / `tests/Feature/Auth/ApiKeyGuardTest.php` / `tests/Feature/Organization/DefaultTeamInvariantTest.php` / `tests/Feature/Billing/BillingNotificationDispatchTest.php` / `tests/Feature/Billing/SendBillingRemindersTest.php` / `tests/Feature/Filament/UserResourceTest.php` / (上記 middleware テスト)。ゲート対象 route を叩くものだけ `freePersonal()` を付与する。
- **テストファイル (新規)**: 下記「テスト計画」。

#### 主要な契約

```php
// BillingAccess: 判定は state() に一本化 (aigenba verbatim)
public function hasActiveAccess(Organization $org): bool  // = $this->effectivePlan($org)->grantsAccess()   (D18)
public function effectivePlan(Organization $org): EffectivePlan  // P2 成果物 (D18: 唯一の判定源)。P4 は GrandfatheredLegacyFreePlan variant の grantsAccess() のみ変更する

// RequireActiveSubscription (差分のみ)
private const string BLOCKED_MESSAGE = 'ご利用にはプランの選択が必要です。'; // JSON 402 用 (文言は着地ページと整合させる)
public function handle(Request $request, Closure $next): Response
// 通過: $this->access->effectivePlan($organization)->grantsAccess()   (D18)
// 遮断: expectsJson() → abort(402, BLOCKED_MESSAGE)
//       それ以外  → reflash() + redirect()->route(
//           Gate::forUser($user)->allows('manageBilling', $organization)
//               ? 'onboarding.checkout' : 'onboarding.billing-required')
```

- 認可 ability は AI-CUE 既存の `manageBilling` (`app/Policies/OrganizationPolicy.php:37`) を使う (aigenba の `manage-billing` は名前空間差。ability を増やさない)。
- ルート名は P3 が定義する current-org スコープ (`onboarding.checkout` / `onboarding.activate-personal` / `onboarding.billing-required`) を参照する。これらは **gate group 外**に置く (`routes/web.php:349` の `require-active-subscription` group に入れない = 構造的 allowlist。入れると遮断→遮断の無限ループ = 詰み)。
- **DB 列/index の追加は無い** (すべて P1)。P4 は既存列への UPDATE のみ。partial unique index `organizations_personal_free_declarer_unique` は `WHERE free_plan_code='personal' AND personal_declared_by_user_id IS NOT NULL` のため declarer NULL の backfill 行は対象外 = 衝突しない。
- backfill migration は `'personal'` リテラルを直書きする (aigenba の index 定義と同じ流儀。migration がアプリ定数に依存しない)。ドリフトは invariant テストで固定する。

**backfill 分類表 (effective entitlement snapshot ベース)**

判定の基準は raw な `plan_code IS NULL` ではなく、**「今日アクセスできているか (旧 `BillingAccess`)」×「反転後 backfill 無しでアクセスできるか (新 `state()`)」** の 2 値。`sub` = `subscription('default')`。

| # | snapshot | 旧 gate | 新 gate (backfill 前) | 処置 |
|---|---|---|---|---|
| 1 | `plan_code` 非 null + sub `active`/`trialing` (cancel_at_period_end の grace 含む = Cashier は status `active` のまま `ends_at` を持つ) | 許可 | Subscribed | **何もしない** |
| 2 | `plan_code` null + sub `active`/`trialing` (webhook 同期ラグ) | 許可 (fallback free) | Subscribed | **何もしない** (実効 entitlement は paid) |
| 3 | `plan_code` null + sub 行なし | 許可 (fallback free) | 遮断 | **grandfather** |
| 4 | `plan_code` null + sub が `canceled`/`incomplete`/`unpaid` のみ (= subscription.deleted 後に webhook が plan_code を null 化した paid→free 経路) | 許可 (fallback free) | 遮断 | **grandfather** |
| 5 | `plan_code` 非 null + sub `past_due`/`unpaid`/`incomplete`/`canceled` | **遮断** | 遮断 | **何もしない** (今日遮断中の org に free entitlement を与えない = 支払い健全性ゲートを緩めない) |
| 6 | `plan_code` 非 null + sub 行なし (webhook 順序逆転の壊れ状態) | **遮断** (fail-closed) | 遮断 | **何もしない** |
| 7 | 上記のうち owner ロール保持者が 0 の org / メンバー数 > `PersonalPlanService::MAX_MEMBERS` | (同上) | (同上) | 分類 3・4 なら **grandfather** (declarer-less のため owner 不在・人数超過でも `eligibility()` を評価しない = 移行分岐を作らない) |
| 8 | 既に `free_plan_code` 非 null (P3〜P4 間に自発 activate した org) | 許可 | ActiveFreePlan | **何もしない** (`whereNull('free_plan_code')` ガード = 冪等) |
| 9 | 付与履歴 (`ticket_ledger_entries.idempotency_key LIKE 'signup_grant:%'`) / `signup_tickets_granted_at` の有無 | — | — | **分類に影響しない**。backfill は grant を発火せず marker にも触れないため、未付与 org は将来 activate/paid 時に 1 回だけ付与される (P1 の marker が真実源) |

→ 実効述語は **`free_plan_code IS NULL AND plan_code IS NULL AND NOT EXISTS(sub type='default' AND stripe_status IN ('active','trialing'))`** に縮退する (分類 3・4・7・8 を 1 本の SQL で表現)。
これは集合として `{ org : 旧gate=許可 ∧ 新gate(backfill前)=遮断 }` と一致し、**「誰もアクセスを失わない」「今日遮断中の誰もアクセスを得ない」**の両不変条件を同時に満たす。

**デプロイ順序 (DoD)**: (1) 列 + index は P1 で適用済み → (2) 本 backfill migration が `php artisan migrate` で完了し、末尾の残余件数検証 (`plan_code IS NULL AND free_plan_code IS NULL AND active sub 無し` の件数 = 0) を通る → (3) ゲートコードのリリースが活性化。migration が throw した場合はデプロイが中断し**旧リリース (ゲート未反転) が生き続ける**。これが「backfill 失敗時はゲートを反転しない」の実現機構。rollback はコード revert のみ (backfill 済み `free_plan_code` は旧コードから無視されるだけで無害)。

#### PHPStan 適合チェック (level 10)

- `BillingAccess::hasActiveAccess(): bool` — `effectivePlan()` は非 nullable な `EffectivePlan` を返すため null 分岐が消え、`?->` も不要 (D18)。
- `RequireActiveSubscription::handle()` の `$request->route('organization')` は `mixed`。既存の `instanceof Organization` narrowing を維持 (`resolveOrganization(): ?Organization`)。`$user` も既存の `instanceof User` narrowing を維持。
- `Gate::forUser($user)->allows('manageBilling', $organization)` は `bool`、`route(string): string`、`redirect()->route()` は `RedirectResponse` (⊂ `Response`)。`@param Closure(Request): Response $next` docblock を維持。
- 分岐追加により `handle()` の全経路が `Response` を返すことを型で保証 (`abort()` は `never`)。
- migration は `DB::table()` クエリビルダのみ (Eloquent モデル・アプリ定数に依存しない)。件数は `->count(): int` で比較し、`Assert`/例外は `RuntimeException` を直接 throw。
- Factory の `freePersonal(): static` は `state(fn () => [...])` で `array<string, mixed>` 推論。generics 追加なし。
- baseline / widen は使わない。

#### テスト計画

**先に red で書く (F-07 回帰。新規 `tests/Feature/Billing/GateInversionF07RegressionTest.php`)**
- (a) **既存 `plan_code IS NULL` 組織が移行後も業務ルートに到達**: 未 activate の org (`createOrganizationWithOwner(activateFreePlan: false)`) を作り、backfill 相当 (`free_plan_code='personal'` + declarer NULL) を適用 → `/projects` `assertOk()` + `assertInertia(component 'Projects/Index')`、`/projects` POST でプロジェクト作成到達、`/app` 到達。declarer NULL でも通ることを固定。
- (b) **新規登録者が遮断されても詰まない**: 未 activate org の owner → `/projects` が `onboarding.checkout` へ redirect、その着地が 200 で、そこから `onboarding.activate-personal` を POST → 再度 `/projects` が `assertOk()` (= 導線が閉じている)。manageBilling 非保持 member は `onboarding.billing-required` へ redirect し、着地が 200。
- (c) **遮断理由が画面に出る (H1 再発検知)**: 遮断 redirect を follow した着地ページの Inertia props に理由文言 (P3 の props) が存在すること。**遮断先が `billing.index` でないこと**を明示 assert (旧 H1 症状の固定)。JSON は 402 + `message` 一致。
- (d) **無限ループ不在**: onboarding.* / billing.* が gate group 外である構造的 allowlist (redirect 先を再度叩いて 302 が返らないこと)。

**backfill migration テスト (新規 `tests/Feature/Billing/GrandfatherFreePlanBackfillTest（**D22: expected ID 集合と実更新 ID 集合の双方向完全一致をアサートする**）.php`)**
- 分類表 9 行を Factory で組み、migration 実行後の `free_plan_code` / declarer / `signup_tickets_granted_at` を検証。特に (i) 分類 5・6 が**救われない** (支払い不健全が free に落ちない)、(ii) 分類 2 が救われない (paid 実効)、(iii) **grant が 1 枚も発火しない** (`ticket_ledger_entries` 件数不変・marker 不変)、(iv) 2 回実行して結果不変 (冪等)、(v) declarer-less 行が partial unique index に衝突せず**同一 user が複数 org を持っていても全件救われる**、(vi) backfill 後に当該 org の owner が別 org で `activate` しても index 違反にならない。
- 残余件数検証が 0 でないとき migration が throw すること。

**既存テストの更新 (削除しない)**
- `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`: 冒頭コメントの gate 方針を反転記述へ。「Free (未契約) 組織は業務 route に到達できる (F-07 再現)」3 本 → 「**未契約組織は onboarding.checkout へ遮断される**」+「**activate 済み free 組織は到達できる**」へ期待を更新。「有償契約 + 支払い不健全は billing へ redirect + 理由 flash」→ **`onboarding.checkout` へ redirect** (flash なし) へ。`BillingAccess` 単体マトリクスを `state()` ベースへ更新 (`plan_code` null + sub なし → 遮断 / `free_plan_code='personal'` → 許可 / active sub → 許可)。「route bound organization が有償不健全なら redirect」「非メンバー 404 defense-in-depth」「billing ページは遮断中でも到達できる」「free プランは Stripe Price を持たない」は**維持** (遮断先の期待のみ更新)。
- `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`: 「seeder の free 組織が**素通り**する」→「seeder が `PersonalPlanService::activate` 済みのため全ロールが `/projects` に到達する」へ。`expect($organization->plan_code)->toBeNull()` は維持し `expect($organization->free_plan_code)->toBe('personal')` を追加 (F-C3 の不変条件は残す)。
- 新規 arch/invariant テスト: backfill migration の `'personal'` リテラルが `PersonalPlanService::FREE_PLAN_CODE` と一致すること (ドリフト検知)。

**Factory 必須 / `RefreshDatabase` グローバル / `--parallel` 前提を維持** (個別 `DatabaseTransactions` を追加しない)。

#### リスク

| リスク | 緩和 |
|---|---|
| **既存ユーザー締め出し (F-07 再発)** = backfill 漏れ or 順序逆転 | 述語を「旧 gate 許可 ∧ 新 gate 遮断」の集合と一致させ、migration 末尾の残余 0 件検証で throw → デプロイ中断 (ゲート非活性)。分類表 9 行の migration テスト。 |
| **106 テストファイルの一斉 red** | `createOrganizationWithOwner` 既定を activate 済みに変更して吸収。`Organization::factory()` 直呼び 7 ファイルのみ手当。1 ユーザーが 2 org を declarer として activate するテストは partial unique index に触れて 23000 になりうる → 該当箇所は `activateFreePlan: false` か declarer 差し替えで回避 (helper は毎回新 User を作るため既定では衝突しない)。 |
| **past_due の paid org が Personal(free) へ自主降格できてしまう** (遮断先が checkout = activate-personal 到達可、`eligibility()` の `hasEntitledSubscription` が false になるため) | **aigenba も同挙動**であり独自ガードを足さない (方針)。ただし収益影響があるため下記 open question として上申。 |
| **grandfathered org が declarer-less のまま滞留** (濫用防止が既存 org に効かない) | 概念設計で受容済み (自然収束しない旨を明記)。P4 は主張を広げない。 |
| **遮断先ページ (P3) の欠落・rename** | P3 のルート名 3 本を P4 のテストが直接叩くため、欠落は red で検知。P4 単独マージ不可の依存として DoD に明記。 |
| **`error` flash 廃止による理由喪失** | 着地ページ props に理由を持たせる (aigenba 方式)。テスト (c) が固定。`reflash()` は招待受諾等の直前 flash 延命のため維持。 |
| **backfill の長時間ロック** (大量 org) | chunk 更新 (`whereNull('free_plan_code')` ガードで再実行安全)。additive な UPDATE のみで index 再構築を伴わない。 |

##### 起草時の未決事項（上位決定は冒頭 §横断決定 / §ユーザー判断を要する残件 を参照）

- ~~判定モデルの二重化~~ → **D18 で確定: `EffectivePlan` を唯一の判定源とし `OnboardingBillingState` は導入しない**。(以下は起草時の記述) 概念設計は P2 で『判定を EffectivePlan DTO へ集約』としているが、aigenba の実体は `App\Enums\Billing\OnboardingBillingState` + `BillingAccess::state()` (/tmp/aigenba/app/Services/Billing/BillingAccess.php:31, /tmp/aigenba/app/Enums/Billing/OnboardingBillingState.php)。『独自実装を足さない』方針に照らし P4 は OnboardingBillingState を前提に書いた。EffectivePlan DTO を別途新設するのか、OnboardingBillingState の別名として扱うのかを P2 と確定させたい。
- ~~aigenba の state() の PendingCheckout/ExpiredCheckout~~ → **D18 で確定: `OnboardingBillingState` を導入せず `EffectivePlan` 単一化**。AI-CUE に subscription checkout session テーブルが無く当該 2 状態は**移植対象が存在しない**。(以下は起草時の記述) aigenba の state() は BillingCheckoutSession を読んで PendingCheckout / ExpiredCheckout を分ける (BillingAccess.php:58-92) が、AI-CUE には subscription checkout session テーブルが無い (ticket_checkout_sessions のみ)。P4 は grantsAccess() しか参照しないため実害は無いが、遮断先の出し分け (checkout 再開 UI) に影響しうる。
- 支払い不健全 (past_due/unpaid) の paid org が遮断先の onboarding/checkout から activate-personal を実行して free へ自主降格できる。aigenba も同挙動 (PersonalPlanService::eligibility の hasEntitledSubscription が false になる) のため独自ガードを足さなかったが、収益・与信の観点で許容するかの上位判断が要る。
- P1 が Plan seeder を Personal/Starter へ揃える際、AI-CUE 既存の plan code ('free' / 'standard'。database/seeders/PlanSeeder.php:45,54、config quota.fallback_plan='free'、tests/Pest.php:157 の contractPaidPlan('standard')) を rename するのか併存させるのかが未確定。P4 の backfill は free_plan_code='personal' リテラル (aigenba verbatim) で書くため直接の依存は無いが、SeededFreePlanBillingAccessTest / QuotaService の解決に波及する。
- P3 の onboarding ルートが current-org スコープ (onboarding.checkout 等) になる前提で書いた。aigenba は {organization} セグメント付き (organizations.onboarding.*)。AI-CUE は業務 route が current-org スコープ (routes/web.php:349) のため current-org 側に寄せたが、P3 が org セグメントを採る場合は middleware の route() 引数と F-07 テストを合わせる必要がある。
- JSON/XHR に対する 402 (Payment Required) は AI-CUE 独自 (aigenba の middleware は常に RedirectResponse)。既存 API/XHR クライアントの後退を避けるため維持したが、『aigenba に全面一致』の厳密解釈では撤去対象になりうる。

---

### P5: チケット残高会計の精緻化 (台帳の置換ではない)

前提: `TicketLedgerService::balance()` は docblock (`app/Services/Billing/TicketLedgerService.php:217-225`) 自身が
「失効は未消費分も含めた全額失効として保守的に働く」と近似を認める単一 int。これを aigenba の per-bucket 会計へ寄せる。
**追加は additive 列 + 読み取り計算のみ**。reserve→commit/release の 2 フェーズ (AGENTS.md #7) と amount ベース reserve
(`AnalysisPipeline.php:121` / `RenderPipeline.php:177` の `reserve($organization, $cost)`) は維持する。

#### 変更箇所

| ファイル | 内容 | 移植元 (aigenba) |
|---|---|---|
| `database/migrations/2026_07_1x_xxxxxx_add_consume_columns_to_ticket_reservations.php` (新規) | `ticket_reservations` へ additive 3 列: `consume_source` (string nullable) / `consume_expires_at` (timestamp nullable) / `consume_monthly_amount` (unsignedInteger nullable)。**データ backfill はしない** | `ticket_reservations.consume_source` / `consume_expires_at` (`TicketService.php:425-437` の insert 列) |
| `app/DataTransferObjects/Billing/TicketBalanceDto.php` (新規) | `monthlyRemaining` / `purchasedRemaining` / `activeReservations` / `nextExpireAt` + `totalAvailable()` + `toArray()` | `app/DataTransferObjects/Billing/TicketBalanceDto.php` **verbatim** |
| `app/Enums/Billing/TicketCommitResult.php` (新規) | `Committed` / `AlreadyCommitted` / `ReleasedExpired` | `app/Enums/Billing/TicketCommitResult.php` **verbatim** |
| `app/Models/Billing/TicketReservation.php` | 3 列の `@property` + `casts()` (`consume_source` => `TicketSource::class`, `consume_monthly_amount` => `integer`, `consume_expires_at` => `immutable_datetime`)。`$fillable` は引き続き持たない | `app/Models/Billing/TicketReservation.php` |
| `app/Services/Billing/TicketLedgerService.php` | 中核。`balance()` を DTO 化 / `availableTrueBalance()` 追加 / `reserve()` に per-source 配賦と `consume_*` 固定を追加 / `commit()` を commit-wins + `TicketCommitResult` 化 / private `sumBucket()` `heldBySource()` `nearestMonthlyExpiry()` `isExpiredMonthlyHold()` `expiredMonthlyHoldCondition()` 追加 | `TicketService.php:312-342` (balance) / `:349-453` (reserve) / `:465-588` (commit) / `:596-624` (失効述語) / `:1045-1083` (sumBalance/countActiveReservations/nearestMonthlyExpiry) / `:1024-1043` (availableTrueBalance) |
| `app/Http/Controllers/Billing/BillingController.php:63` | `$tickets->balance($organization)` → `$tickets->balance($organization)->totalAvailable()` (props 形状は int のまま維持) | — |
| `app/Http/Controllers/Billing/TicketPurchaseController.php:66` | `balance:` へ `->totalAvailable()` | — |
| `app/Services/Dashboard/DashboardService.php:221` | `$balance = $this->tickets->balance($organization)->totalAvailable()` (`isLowBalance` の判定も同値) | — |
| `app/Services/Manual/AnalysisJobService.php:81` / `RenderJobService.php:90` | 入口 fail-fast の残高は判定値 `availableTrueBalance()` へ (表示 clamp を判定に使わない) | `TicketService.php:1024`「UI 表示には balance() を使うこと — 判定に使うと負残高で誤判定する」 |
| `app/Services/Manual/AnalysisPipeline.php:219-223` / `RenderPipeline.php:293-297` | commit の docblock/コメント更新 (「非 Reserved は LogicException → rollback」→ commit-wins)。**戻り値は分岐に使わない** (job 成否は既存 guard が決める) | `TicketService.php:465-478` |
| `database/factories/Billing/TicketReservationFactory.php` / `TicketLedgerEntryFactory.php` (新規) | 新規テストの Factory (手組み禁止)。state: `legacy()` (`consume_*` = null) / `monthlyHold()` / `purchasedHold()` / `monthlyGrant()` / `purchasedGrant()`。Model へ `HasFactory` 追加 | — |

**移植しない判断 (二重実装を作らないため)**: `app/Enums/CreditSource.php` は移植せず、既存 `App\Enums\Billing\TicketSource`
(`monthly` / `purchased`) を使う。値・意味が 1:1 で、DB 既存列 `ticket_ledger_entries.source` がこの値を持つため
(aigenba の `plan_monthly` へ寄せると既存台帳の書き換え = 台帳の置換になり、本フェーズの前提に反する)。
`ensureSufficient()` も移植しない (1 encounter=1 枚前提。AI-CUE は `InsufficientTicketsException::forReserve($cost, $balance)`)。

#### 波及変更

- **TypeScript 型定義**: **なし**。`resources/js/types/billing.ts:14 (balance: number)` / `types/dashboard.ts:36 (ticket_balance: number)` /
  `pages/Billing/Index.svelte:35 (ticketBalance: number)` は int のまま維持する (per-bucket の UI 露出は P8b の課金 UI parity)。
  この「props 形状不変」が P5 の revert 安全性 (概念設計「旧コードは新列を無視する」) の根拠。
- **DTO**: 新規 `TicketBalanceDto`。既存 `PurchaseTicketsPageDto.balance` / `Dashboard/BillingSummaryData.ticketBalance` は
  **形状不変** (供給値の算出元のみ変更)。JsonResource は不使用 (Inertia props)。
- **Inertia props**: `Billing/Index` の `ticketBalance` / `Billing/PurchaseTickets` の `page.balance` /
  `Dashboard` の `dashboard.billing.ticket_balance` — いずれも**キー・型は不変**。
- **テストファイル (更新対象・全列挙)**:
  `tests/Feature/Billing/TicketLedgerTest.php` (:26,:38,:43,:56,:61,:95,:103,:115 の `balance()->toBe(int)`、:92 の再 commit 期待) /
  `tests/Feature/Billing/TicketRefundClawbackTest.php` (:142,:147 — 特に **:147 の `-2`**) /
  `tests/Feature/Billing/WebhookIdempotencyTest.php` (:94,:112,:123,:137,:150,:164,:214,:227) /
  `tests/Feature/Organization/InvitationTest.php:387` / `tests/Feature/Database/BughuntBillingSeederTest.php:50,61,83,87` /
  `tests/Feature/Auth/RegistrationTest.php:29` / `tests/Feature/Auth/RegistrationInvitationPrefillTest.php:178` /
  `tests/Feature/Projects/AnalysisPipelineTest.php:166` (+ :294 の不変条件コメント) /
  `tests/Feature/Manual/RenderPipelineTest.php:143,164` / `tests/Feature/Manual/RenderStaleRecoveryTest.php:92` /
  `tests/Feature/Manual/RenderTriggerTest.php:254` / `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php:88,117`。
  **`tests/Feature/Billing/TicketVolumeTierTest.php` / `TicketGrantTest.php` / `BillingPageTest.php` / `tests/Feature/DashboardTest.php`
  は更新不要 (grep 済: `balance()` を直接呼ばず props 形状が不変)**。
- **削除するテストは無い** (期待の更新のみ)。

#### 主要な契約

```php
// 表示用 (aigenba verbatim。activeReservations の意味だけ amount ベースへ一般化)
public function balance(Organization $organization): TicketBalanceDto;
/** 与信・判定用の真値 (source ごとに max(生残高 − hold, 0) してから合算 → 常に 0 以上) */
public function availableTrueBalance(Organization $organization): int;
public function reserve(Organization $organization, int $amount): TicketReservation; // シグネチャ不変 (amount ベース維持)
public function commit(TicketReservation $reservation): TicketCommitResult;          // void → enum
public function release(TicketReservation $reservation): void;                       // 不変 (非 Reserved は LogicException 維持)
public function releaseStale(): int;                                                 // 不変 (TTL のみ。理由は下記)
```

**バケット定義 (backfill を不要にする中核)**
- `monthly` バケット = `source = 'monthly'` の未失効行 (`expires_at IS NULL OR expires_at > now`)。
- `purchased` バケット = **`source = 'purchased' OR source IS NULL`** の未失効行。
  → 既存の消費行 (`kind=reserve_commit` は `source=null`)・手動 `grant()`・`adjustment` は**無期限の負債/資産**であり、
  無期限バケット (= purchased) と寿命特性が一致する。**この畳み込みにより台帳の backfill が一切不要になる**
  (per-source SUM に落とすと `source IS NULL` 行が両バケットから消え、過去消費が帳消しになる over-grant が起きる。それを閉塞する)。
- `monthlyRemaining = max(SUM(monthly), 0)` / `purchasedRemaining = max(SUM(purchased ∪ null), 0)` (**表示用**の clamp。aigenba 同型)。
- **`debt`（D19 / D24 で数式を確定）**: **台帳書き込みは一切変更しない**（grant 行の `delta` を減額しない）。
  相殺は**残高計算側で一度だけ**行う（Codex Round 2 Critical: 書込み側で相殺すると、purchased raw `-2` に `+10` を積んだ
  台帳合計が自然に `8` になるのに加えて grant を `8` へ減額し `6` になる = **債務の二重回収**）。正規契約:

```text
monthlyPositive      = max(monthlyRaw   - monthlyHold,   0)
purchasedPositive    = max(purchasedRaw - purchasedHold, 0)
debt                 = min(purchasedRaw - purchasedHold, 0)      // 内部計算は負数
availableTrueBalance = max(monthlyPositive + purchasedPositive + debt, 0)
totalAvailable()     = availableTrueBalance                       // 債務控除後の非負値
```

  - **`debt` の符号は DTO 境界で「正の債務額」に固定**する（`debt = abs(min(...,0))`。表示・props とも正数）。
  - これにより **monthly grant があっても purchased の債務が回収される**（source 別 clamp のみだと `10` が使えてしまい
    債務が回収されない、という Codex 指摘の穴を塞ぐ）。
  - テスト必須: purchased grant / monthly grant / signup grant / auto-recharge grant の**各経路で債務が一度だけ回収される**、
    **monthly grant 失効後も未回収債務が残る**。
- `nextExpireAt` = `delta > 0 AND expires_at > now` の最小 `expires_at` の ISO8601 (aigenba `:334-341` 同型。`amount` → `delta`)。
- `activeReservations` = **拘束「枚数」= SUM(amount)** (aigenba は 1 枚固定のため `count()`。amount ベースの必然的一般化。
  DTO の列名・shape は verbatim のまま、docblock で意味を明記)。

**hold (拘束) の per-source 集計** — `status = reserved` のみ。
- `heldMonthly = SUM(consume_monthly_amount)`、ただし**失効 monthly hold** (`consume_expires_at <= now`、legacy null 行は
  `expires_at <= now`) の行は monthly 分を除外 (aigenba `countActiveReservations` `:1056-1070` と同一述語を PHP 版
  `isExpiredMonthlyHold` / query 版 `expiredMonthlyHoldCondition` で共有)。
- `heldPurchased = SUM(amount − consume_monthly_amount)`。
- **reserve TTL 切れでも Reserved である限り枠は保持する** (aigenba `:1061-1066`: commit-wins と対称にし、
  30 分超ジョブ中の同枠二重予約 = オーバーセルを防ぐ)。枠の解放は `releaseStale` の Released 化に委ねる。

**reserve (amount ベース配賦。aigenba `availableMonthly > 0 ? PlanMonthly : Purchased` の amount 一般化)**
```
availableMonthly   = max(SUM(monthly)   − heldMonthly,   0)
availablePurchased = max(SUM(purchased) − heldPurchased, 0)
if (availableMonthly + availablePurchased < amount) → InsufficientTicketsException::forReserve(...)  // 現行と同じ入口
useMonthly   = min(amount, availableMonthly)          // 消費優先 monthly → purchased
usePurchased = amount − useMonthly
consume_monthly_amount = useMonthly
consume_source         = useMonthly > 0 ? Monthly : Purchased   // amount=1 なら aigenba と完全一致
consume_expires_at     = useMonthly > 0 ? nearestMonthlyExpiry() : null   // Assert::isInstanceOf で非 null を強制
```
既存の `lockOrganizationRow()` (org 行 `lockForUpdate`) 下で評価する = 直列化点は不変。
低残高クロス検知 (`:269-280`) は `$balance` を `availableTrueBalance` 相当の真値に差し替えるのみ (意味論は不変)。
**aigenba の `insertOrIgnore(encounter_id)` 冪等 reserve は移植しない** (AI-CUE の予約は job 行の
`ticketReservation()` 関連で冪等化済み = `AnalysisPipeline.php:105-118`)。

**commit (commit-wins + per-source 2 行計上)**
```
lockReservationRow()  // 行ロックは維持、ただし「非 Reserved は LogicException」guard は撤去 (commit-wins)
status = Committed                          → TicketCommitResult::AlreadyCommitted (冪等 no-op)
(m, p) = 予約行の consume_monthly_amount / (amount − consume_monthly_amount)
         ※ legacy 行 (consume_monthly_amount IS NULL) はここで再配分 (下記)
chargeMonthly = isExpiredMonthlyHold(reservation, now) ? 0 : m       // 失効 monthly は課金しない
if (chargeMonthly + p === 0) → 予約を Released 化 + Log::warning → TicketCommitResult::ReleasedExpired  // 台帳行なし
insertIdempotent(-chargeMonthly, source=monthly,   expires_at=consume_expires_at, key="consume:{$id}:monthly")   // >0 のとき
insertIdempotent(-p,             source=purchased, expires_at=null,               key="consume:{$id}:purchased") // >0 のとき
status が Reserved のときのみ Committed へ (Released は据え置き = 一方向遷移維持。課金の真実源は台帳)
→ TicketCommitResult::Committed
```
- **monthly 消費行に grant と同じ `expires_at` を載せる**のが精緻化の核心: バケット失効時に `+grant` と `−consume` が
  同時に合算から落ち、現行 docblock の「全額失効」近似が消える (aigenba `TicketService.php:520-533` 同型)。
- status guard 撤去で失われる二重課金防止は **`idempotency_key` UNIQUE (`consume:{id}:{source}`) が肩代わりする**
  (aigenba `consume:{encounterId}` と同型。既存 `ticket_ledger_entries.idempotency_key` UNIQUE をそのまま使う = 列追加なし)。
  `insertIdempotent` に `kind` / `reservation_id` を渡せるよう属性を通すだけで済む (メソッド本体は不変)。
- 混在予約 (monthly 3 + purchased 2) で monthly のみ失効 → purchased 2 のみ課金し `Committed`。
  aigenba の 3 値 enum は amount=1 で完全一致し、amount 一般化で自然に拡張される (**enum に case を足さない**)。

**既存 reserved 行 (consume_* 未設定) の扱い — 決定: 移行時固定はせず、commit 時に再配分する**
1. **migration での backfill を書かない**。理由: (a) Reserved 行は TTL 30 分で消える過渡的少数であり、正しく固定するには
   org ごとの残高大域計算が要る (無条件に purchased/monthly へ寄せると `max(…,0)` clamp と噛み合って over-grant =
   オーバーセルが発生することを検証済み)、(b) デプロイ中の並行 reserve と競合する、(c) 誤配賦の固定は revert 不能だが、
   再配分は純粋関数で revert 可能 (概念設計の rollback 前提「旧コードは新列を無視する」を保つ)。
2. **読み取り時 (balance / availableTrueBalance)**: legacy hold (`consume_monthly_amount IS NULL`) を
   `legacyHeld = SUM(amount)` として集計し、**消費優先順と同じ順序で仮配賦**する
   (`heldMonthly += min(legacyHeld, max(SUM(monthly) − heldMonthly, 0))`、残りを `heldPurchased` へ)。
   **控除は合計 1 回のみ** (二重控除 = ユーザー不利を作らない)。純粋計算で、行は書き換えない。
3. **commit 時**: org 行ロック下で **自予約の hold を除いた** availability から `reserve` と同一規則で `(m, p)` を確定し、
   予約行へ `consume_*` を書き込んでから (= 遅延固定) 課金する。monthly の期限は `nearestMonthlyExpiry()` を採用するため
   **null-expiry の不滅ゴーストは原理的に発生しない** (aigenba `:414-421` の Assert と同じ根拠)。
   再配分時に monthly grant が失効していれば生存分のみ課金 (`chargeable = 0` → `ReleasedExpired`)。

**DB 列 / index**
```
ticket_reservations:
  consume_source          string       nullable  // monthly|purchased (App\Enums\Billing\TicketSource)
  consume_expires_at      timestamp    nullable  // monthly 分の失効境界 (予約時に固定・commit は再探索しない)
  consume_monthly_amount  unsignedInt  nullable  // monthly から拘束した枚数。purchased 分 = amount − 本列。null = legacy
```
**新規 index は追加しない**: hold 集計は `where(organization_id, status)` = 既存 `['organization_id','status']` で覆われ、
`releaseStale` は既存 `['status','expires_at']` を使う。`ticket_ledger_entries` は**列追加ゼロ** (`source` / `expires_at` /
`idempotency_key` は既存)。**ルート変更なし**。

**`releaseStale` を変更しない理由 (aigenba との意図的な差)**: aigenba は失効 monthly hold も release 対象に含めるが
(`:992-1006`)、AI-CUE の予約は混在 (monthly + purchased) しうるため、monthly 失効を理由に行ごと Released 化すると
purchased 分の拘束まで解放されオーバーセル窓が開く。aigenba がそれで防いでいた「翌期間 balance の侵食」は、
本設計では **hold 集計側の除外** (`expiredMonthlyHoldCondition`) が同じ効果を達成するため、release 側の変更は不要。

#### PHPStan 適合チェック (level 10 / widen・baseline 禁止)

- `balance(): TicketBalanceDto` / `availableTrueBalance(): int` / `commit(): TicketCommitResult` と戻り値を明示。
  `commit` の呼び出し側 (`AnalysisPipeline:223` / `RenderPipeline:297`) は戻り値を捨てる (level 10 は未使用戻り値を咎めない)。
- `TicketBalanceDto` は `final readonly` + `@phpstan-type TicketBalanceShape` + `toArray(): TicketBalanceShape` (aigenba verbatim)。
  `PurchaseTicketsPageDto` の `@phpstan-type` は `balance: int` のまま (形状不変)。
- `->sum('delta')` は mixed → 既存踏襲で `(int)` キャスト。`->value('expires_at')` は mixed →
  `$v instanceof CarbonImmutable ? … : ($v instanceof Carbon ? CarbonImmutable::instance($v) : null)` で null 安全に絞る
  (AI-CUE は `immutable_datetime` cast のため `CarbonImmutable` 側を先に判定する。aigenba は `Carbon` 判定)。
- `consume_monthly_amount` は `?int`。`$reservation->consume_monthly_amount ?? $this->reassignLegacy($reservation)` の形で
  null 合体してから `int` へ確定し、以降 null 伝播させない。`consume_expires_at` は `?CarbonImmutable`、
  monthly 割当時のみ `Assert::isInstanceOf(..., CarbonImmutable::class)` で非 null を保証する。
- `TicketReservation` に `@property ?TicketSource $consume_source` / `@property ?int $consume_monthly_amount` /
  `@property ?CarbonImmutable $consume_expires_at` を追加。`casts(): array<string, string>` の既存戻り型に適合。
- query builder の closure 引数は `@param Builder<TicketReservation> $query` で generics を明示
  (aigenba `expiredMonthlyHoldCondition` `:613` 同型)。`whereNot` の 3 値論理事故を避けるため各 OR 枝を
  `whereNotNull` / `whereNull` で確定 boolean にする (aigenba `:606-611` のコメントごと踏襲)。
- Factory は `/** @extends Factory<TicketReservation> */` + `definition(): array<string, mixed>`。Model へ
  `/** @use HasFactory<TicketReservationFactory> */`。
- `TicketCommitResult` は純粋 enum → `match` は全 case 網羅 (呼び出し側で分岐しないため実質不要)。

#### テスト計画

**先に red を作る新規テスト**
1. `tests/Feature/Billing/TicketBalanceAccountingTest.php`
   - monthly grant +10 (期限あり) → reserve/commit −3 → 期限到達で **grant と消費行が同時に落ち** `monthlyRemaining = 0`
     (現行実装なら `-3` が残り red)。
   - 消費優先: monthly 4 / purchased 10 で `reserve(5)` → `consume_monthly_amount = 4`・`consume_source = monthly`・
     purchased 拘束 1、台帳 commit で `source=monthly:-4` と `source=purchased:-1` の 2 行。
   - `source IS NULL` の legacy 消費行が **purchased バケットへ畳まれる** (帳消しにならない)。
   - `nextExpireAt` が最短の未失効・正 delta の ISO8601。`activeReservations` が拘束**枚数** (SUM(amount))。
2. `tests/Feature/Billing/TicketBalanceMonotonicityTest.php` (**invariant / 最重要**)
   - 旧式 oracle (`SUM(未失効 delta) − SUM(reserved.amount)`) をテスト内へ実装し、代表シナリオ集合
     (失効あり/なし × 混在予約 × clawback × legacy 消費行 × legacy 予約) で
     `availableTrueBalance() >= oracle` を検証 = **精緻化がユーザー不利に動かない**。
3. `tests/Feature/Billing/TicketCommitWinsTest.php`
   - TTL 切れで `releaseStale` に Released 化された生存予約の commit → **課金され `Committed`** (status は Released 据え置き)。
   - 再 commit → `AlreadyCommitted` かつ台帳は 1 組のみ (`consume:{id}:{source}` UNIQUE)。
   - 全額 monthly 予約の consume_expires_at 経過 → `ReleasedExpired`・台帳行ゼロ・status Released。
   - 混在予約で monthly のみ失効 → purchased 分のみ課金 + `Committed`。
4. `tests/Feature/Billing/TicketLegacyReservationTest.php`
   - Factory `legacy()` の Reserved 行 (consume_* = null) に対し、balance の**仮配賦が合計 1 回だけ控除**すること
     (monthly 先・溢れは purchased)。commit 時再配分で `consume_*` が確定し per-source 台帳が書かれること。
     再配分時に monthly が失効済みなら生存分のみ課金。
5. `tests/Feature/Billing/TicketAmountBasedReserveTest.php` (**AGENTS.md #7 / ドメイン境界の回帰**)
   - `reserve($org, 5)` が amount=5 の予約 1 行を作る (1 枚固定に退化していない)。
   - `config('manual.analysis_ticket_cost') !== config('manual.render_ticket_cost')` の可変コストで解析/レンダが完走する。
   - reserve→commit / reserve→release の 2 フェーズが残っていること (直接デクリメントが無い = 台帳 append-only)。

**既存テストの更新 (削除しない)**
- `tests/Feature/Billing/TicketLedgerTest.php`: `balance()->toBe(int)` を `balance()->totalAvailable()` /
  `availableTrueBalance()` へ (:26,:38,:43,:56,:61,:95,:103,:115)。**:85-93「committed / released の予約は再 commit /
  再 release できない」は commit-wins へ期待更新** — 再 commit は `AlreadyCommitted`、released 予約の commit は課金 +
  `Committed`、**再 release は引き続き `LogicException`** (release の意味論は変えない)。:98-116 `releaseStale` は不変。
- `tests/Feature/Billing/TicketRefundClawbackTest.php:147`: `toBe(-2)` → per-source clamp により `0`
  (**要 openQuestion 参照**)。:142 は API 差し替えのみ。
- `tests/Feature/Billing/WebhookIdempotencyTest.php` / `tests/Feature/Organization/InvitationTest.php:387` /
  `tests/Feature/Database/BughuntBillingSeederTest.php` / `tests/Feature/Auth/{RegistrationTest,RegistrationInvitationPrefillTest}.php` /
  `tests/Feature/Projects/AnalysisPipelineTest.php:166` / `tests/Feature/Manual/{RenderPipelineTest,RenderStaleRecoveryTest,RenderTriggerTest}.php` /
  `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php`: `->balance($org)` の戻り値変更に伴う API 差し替え
  (期待値は不変であることを同時に検証 = 回帰の網)。
- `tests/Feature/Projects/AnalysisPipelineTest.php:294` 近傍の不変条件記述「succeeded ∧ released の非共存」を
  **「succeeded ∧ 無課金の非共存」へ読み替え更新** (commit-wins は Released 据え置きのまま課金するため、
  守るべきは「成果物を渡して無課金 = タダ乗り」と「失敗して課金」の排除であり、これは強化される)。
- `tests/Feature/Billing/{TicketVolumeTierTest,TicketGrantTest,BillingPageTest}.php` / `tests/Feature/DashboardTest.php`:
  **更新不要** (grep 済み。`balance()` 非依存 / props 形状不変)。
- Factory 必須のため手組み `new TicketReservation` はテストに書かない。`RefreshDatabase` グローバル・`--parallel` 前提を維持
  (個別 `DatabaseTransactions` を足さない)。

#### リスク

| リスク | 緩和 |
|---|---|
| ~~per-source clamp で負残高が消える~~ → **D19 で解決済み**。**clamp しない (債務保全)** ため `TicketRefundClawbackTest:147` の `-2` 期待は**維持**する。判定は非負の `availableTrueBalance()`、会計残高 DTO は `debt` を明示 | — (仕様確定。openQuestion #1 は closed) |
| **commit-wins により「succeeded ∧ released」が成立し得る** (status 据え置き・課金は台帳が真実源) | 既存 guard (`AnalysisPipeline:202` の job status 検査) が cron 先勝ちケースを先に弾くため、実際に到達するのは「TTL 切れだが Running」= 成果物を渡す正当な課金ケースのみ。不変条件記述を更新し、`Log::info` で可観測化 (aigenba `:568-574` 同型) |
| **reserve TTL 30 分 < 長時間レンダ**。`releaseStale` が Running 中の予約を解放 → 解放枠が別 reserve に取られ、後で commit-wins が課金 → 一時的オーバーセル (負残高) | aigenba と同じ既知窓。hold 側で TTL 切れを除外しない (枠を保持する) ことで窓を `releaseStale` の実行間隔 (5 分 cron) に限定。TTL 方針は **openQuestion #3** |
| **混在予約の `consume_expires_at` は最短 monthly 期限を採用** (`nearestMonthlyExpiry`)。実際には別バケットから消費していた場合に消費行が早く落ち残高が過大 | aigenba `:411-421` と同一近似。FIFO (最短期限先消費) が実装上の前提なので通常一致。over-grant 方向だが invariant には適合。`ReleasedExpired` の Log::warning で観測 |
| commit の status guard 撤去で二重課金 | `idempotency_key` UNIQUE (`consume:{id}:{source}`) + org 行ロックで DB 保証。`insertIdempotent` の戻り (挿入 0 行) を `Log::warning` で可観測化 (aigenba `:545-551` 同型) |
| legacy 予約の仮配賦バグ | 影響はデプロイ後 TTL 30 分の Reserved 行のみ。純粋計算 (行を書き換えない) で revert 安全。専用テスト (#4) で覆う |
| 呼び出し側 5 箇所の `balance()` 戻り値変更の取りこぼし | シグネチャ変更で PHPStan level 10 が全箇所を機械検出 (int → DTO は型エラーになる) |
| revert 可能性 | additive 列 + 読み取り計算 + props 形状不変。旧コードは `consume_*` 列と `consume:*` 台帳行を無視するだけ (台帳の置換・二重書き・差分再同期は無し = 概念設計の rollback 手順に一致) |

##### 起草時の未決事項（上位決定は冒頭 §横断決定 / §ユーザー判断を要する残件 を参照）

- ~~返金過多で生じた負残高の扱い~~ → **D19 で確定: (b) 債務保全 (clamp しない)**。`TicketRefundClawbackTest:147` の `-2` 期待は維持し、表示 clamp と判定値 (`availableTrueBalance()` 非負) を分離する。**本文の「暫定的に (a) を採用」は撤回済み**。
- commit-wins 導入により「succeeded ∧ released」(予約 status は Released のまま台帳で課金済み) が成立し得る。AI-CUE が `tests/Feature/Projects/AnalysisPipelineTest.php:294` 近傍で明文化している不変条件「succeeded ∧ released の非共存」を「succeeded ∧ 無課金の非共存」へ読み替えてよいか (aigenba は Released→Committed の一方向遷移を守るため status を据え置く)。
- reserve TTL (`RESERVATION_TTL_MINUTES = 30`) は長時間レンダで慢性的に切れる。commit-wins 後も `releaseStale` が TTL で解放し続けるため「解放 → 別 reserve が枠取得 → 元ジョブが commit-wins で課金」= 一時的オーバーセル窓が残る。(a) 現状維持 (aigenba 同等の既知窓) / (b) TTL をジョブ最大実行時間相当へ延長 / (c) releaseStale から TTL 条件を外し stale 回復 cron の release に一本化、のどれを採るか (P5 スコープ外の可能性あり)。
- `app/Enums/CreditSource.php` を移植せず既存 `App\Enums\Billing\TicketSource` (`monthly`/`purchased`) を使う判断でよいか (aigenba の値は `plan_monthly`/`purchased`。揃えると既存 `ticket_ledger_entries.source` の全行書き換え = 台帳の置換になり P5 の前提「additive のみ」に反する)。

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
| `app/Services/Billing/TicketLedgerService.php:91-111` | `grantSignupGrant(Organization $organization)` → **`grantSignupGrant(Organization $organization, string $idempotencyKey)`**。内部生成 (`signup_grant:org:{id}`) をやめ呼び出し側が鍵を渡す。冒頭で `Assert::stringNotEmpty` + **`Assert::startsWith($idempotencyKey, 'signup_grant:')`** (AI-CUE の partial index 述語の外に出る鍵を型で禁じる = index の保険が効かない鍵を作れない)。docblock を「1 組織 1 回の真実源は `organizations.signup_tickets_granted_at`。本メソッドは冪等な ledger insert のみで claim 判定は呼び出し側」に更新 | `app/Services/Billing/TicketService.php:261-279` |
| `app/Services/Billing/PersonalPlanService.php` (P1 で追加済) | `activateWithinTransaction` の org 行 `lockForUpdate()` 配下に **marker の条件付き UPDATE (`whereNull('signup_tickets_granted_at')->update(...)`) → `$claimed === 1` のときのみ `grantSignupGrant($fresh, 'signup_grant:personal:'.$fresh->id)`** を追加し、`PersonalPlanActivationResultDto(granted: $claimed === 1)` を返す | `app/Services/Billing/PersonalPlanService.php:99-129` (verbatim) |
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

- `grantSignupGrant` の追加引数は `string` 明示 + `Assert::stringNotEmpty` / `Assert::startsWith` で narrow。config 読みは
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

- `app/Actions/Fortify/CreateNewUser.php` (現行 :52-69 に plan 系なし): `IntendedPlanResolver` を DI し、validate 通過後・`DB::transaction` 前に `rememberPendingFromForm($input)` を 1 行呼ぶ。**`intended_plan` は validation rules に足さない** (aigenba `CreateNewUser` の明示規約: 無効値でも登録は通す / 422 で止めない)。既存の招待 token 解決・`MatchesInvitationEmail`・signup grant 呼び出しは触らない (grant 契機は P6 の管轄)。
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
  - `resources/js/types/plan.ts` もしくは P1 が置いた PlanCode の TS union (P1 の成果物名に追随。無ければ `export type PlanCode = 'personal' | 'starter' | 'enterprise'` を P7 で新設 → openQuestions #4)。
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

- `tests/Feature/Auth/RegistrationTest.php` — 既存 3 期待 (verification.notice / signup grant / current_organization_id) は維持し、session キーの期待を追加。
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

##### 起草時の未決事項（上位決定は冒頭 §横断決定 / §ユーザー判断を要する残件 を参照）

- ~~P3 の onboarding route の正式名とシグネチャ~~ → **D21 で確定: `onboarding.{checkout,activate-personal,billing-required}`（route parameter なし・current-org スコープ）**。continuation は組織 ID を保持し membership 確認後に引数なしで route 生成する。
- P1 の `PlanCode` に `Enterprise` case が含まれるか。`normalizeRaw` の Enterprise 除外は aigenba verbatim 移植の中核だが、case が無いと PHPStan level 10 が `identical.alwaysFalse` を出す (baseline 禁止)。含まれない場合、除外分岐を落とす (= 非 verbatim) か P1 に Enterprise を足すかの上位判断が要る。
- SSO (`SocialAuthController` + `SocialAccountService`) の `?plan=` handoff を P7 に含めるか。aigenba は `SsoController::redirectRegister` で `rememberPendingFromForm` する形を持つが、担当フェーズ記述には SocialAuthController が挙がっていない。含めないと `/register?plan=starter` から「Google で登録」を押した瞬間に plan 意図が失われる (silent dead-end)。本設計は含める前提で書いた。
- `resources/js/pages/Welcome.svelte` の `/register` CTA (3 箇所: :137 nav / :160 hero / :358 料金 CTA) の扱い。aigenba の `Guest/Landing.svelte` は `/register` 直リンクを一切持たず `/pricing` へ誘導する (プラン選択を先に通す) 構造で、AI-CUE の直リンクは parity 外。本設計では P7 は変更せず landing 導線 parity は P8b の管轄とした。P8b で拾うか、P7 で `?plan=personal` を付けるか要判断。
- aigenba `CreateNewUser` の `starter_migration_acknowledged` (intended_plan=starter 時のみ `accepted` 必須) を移植しない判断の確認。AI-CUE の Starter に「30 日後 Standard へ自動移行」という事実が無く、同意対象が存在しないため。P1 の Starter 定義が自動移行を持つなら移植が必要。
- `VerifyEmailResponse` (`VerifyEmailResponseContract` bind) の新設が P7 スコープでよいか。担当フェーズ記述には無いが、`EmailVerificationContinuation` の forget 側ライフサイクル (aigenba `VerifyEmailResponse.php:23-27`) がここにしか無く、これを欠くと continuation が session に残留して verify 後も checkout へ飛び続ける。

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

### P8b: 課金 UI parity（Billing/Plans + PlanCard + Guest/Pricing 配置 + PurchaseTickets 状態機械 + Index 情報密度）+ 監査「判断不要 15 件」の消化

前提: P1〜P7 と P8a はマージ済み（PlanCode enum / EffectivePlan DTO / per-bucket `TicketBalanceDto`(P5) / `?plan=` handoff(P7) / AutoRecharge(P8a) が既に存在する）。本フェーズは **UI 層と、それを支える Controller/DTO のみ**を触る。会計 (`TicketLedgerService`) と Stripe 境界 (`*Gateway`) には手を入れない（監査 ticket-charge-11 の 4 分割境界を維持）。

#### 監査「A. 判断不要 = 機械的に aigenba へ寄せられる (15件)」の消化台帳

| # | finding | 本フェーズでの対応 |
|---|---|---|
| 1 | registration-funnel-7 `EmailVerificationContinuation` | **P7 で完了**（概念設計 実装方針 P7）。P8b では触らない。 |
| 2 | registration-funnel-11 `OnboardingReturnResolver` | **P7 で完了**。P8b では触らない。 |
| 3 | registration-funnel-14 オンボ外枠の T071 primitive 整合 | **P3 で完了**（`Onboarding/{Checkout,BillingRequired}.svelte`）。P8b は同じ規約を新設 `Billing/Plans.svelte` に適用し、aigenba の `GuestLayout + max-w-6xl 直書き`は移植しない。 |
| 4 | pricing-plans-6 `Billing/_helpers/PlanCard.svelte` | **P8b で実施**（下記）。移植元 `/tmp/aigenba/resources/js/pages/Billing/_helpers/PlanCard.svelte`。 |
| 5 | pricing-plans-7 `PricingPlanCard` の headerBadges / contactLabel | **headerBadges snippet は P8b で追加**（Billing 側の「現在のプラン」バッジに必要）。**contactLabel は追加しない** — AI-CUE には enterprise を Plan 行として持たず（`PlanSeeder`）、監査 action 自体が「enterprise プラン採否に連動」と条件付き。既存コメント（`/workspace/resources/js/components/molecules/PricingPlanCard.svelte:7-8`「大規模利用はカード外バナーの責務」）を維持し、Guest/Pricing の `pricing-enterprise-banner` を正とする。 |
| 6 | billing-subscription-4 `SubscriptionService`/`SubscriptionSnapshot` | **P2 で完了**。P8b は `EffectivePlan` DTO を読むだけで、raw column 分岐を書かない。 |
| 7 | billing-subscription-6 料金プラン画面 `Billing/Plans` | **P8b で実施**（下記）。 |
| 8 | billing-subscription-7 サブスク checkout の冪等・着地 feedback | **P8b から除外（D25）**。`BillingCheckoutSession` 相当 / `resolveBillingFeedback` / billing contact 列・更新 Action・`BillingContactShape` は **P2 の非スコープであり存在しない**ため、P8b は実装不能になる。→ **独立フェーズ P9（サブスク checkout の冪等・着地 feedback + 請求先情報）へ切り出す**。P8b は当該 props / UI を**持たない**（`Billing/Plans` の `attemptToken`、`Billing/Index` の `feedback`・`billingContact` を除外） |
| 9 | billing-subscription-10 `BillingCustomerSynchronizer` / 請求先情報 | **backend（列 + job + synchronizer）は P2 の所管**。P8b は `Billing/Index` の請求先フォーム UI（`components/features/billing/BillingContactForm.svelte`、移植元 `/tmp/aigenba/resources/js/components/features/billing/BillingContactForm.svelte`）と `billingContact` prop の追加のみ。 |
| 10 | billing-subscription-11 Customer Portal の事前ガード | **P8b で実施**（`BillingController::portal()` の事前ガード + Index のボタン表示条件）。 |
| 11 | billing-subscription-14 Billing/Index の構造と情報密度 | **P8b で実施**（外枠は既に T071 準拠のため是正不要。プラン一覧を Plans へ移設し、Index を請求ダッシュボードへ寄せる）。auto-recharge 設定カードは **P8a 所管**（P8b は差し込み位置 `#auto-recharge` anchor のみ用意）。 |
| 12 | billing-subscription-15 `billing-required` 画面 | **P3 で完了**。P8b では触らない。 |
| 13 | ticket-charge-5 購入フォームの状態機械 + attempt_token 安定化 | **P8b で実施**（下記）。 |
| 14 | ticket-charge-8 spot 単価の出典 | **合わせない（対応不要）**。監査 action の宿題「production の livemode/synced_at 必須チェックが AI-CUE にあるか」は**実コードで確認済み = 有り**（`/workspace/app/Models/Billing/TicketVolumePrice.php:91-96` `app()->environment('production')` 下で `Assert::true($row->livemode && $row->synced_at !== null)`）。単一テーブル集約を維持。 |
| 15 | ticket-charge-11 サービス分割構造 | **合わせない（逆行しない）**。P8b は 4 分割境界を守る: 会計=`TicketLedgerService`、導線/状態=Controller+DTO、Stripe=`*Gateway`。`resolveResumablePurchase` は冪等 Checkout マシンの一部として `TicketCheckoutService` に置く。 |

#### 変更箇所 (ファイルパス + 何をするか。移植元 aigenba のパスを併記)

**(a) プラン提示の専用ページ化（bs-6 / pp-6 / pp-7）**

- 新規 `/workspace/resources/js/pages/Billing/Plans.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/Plans.svelte`
  - `AppLayout > PageContainer > PageHeader > PageContent`（T071 primitive。aigenba の `PageHeaderSection`+breadcrumbs は**採らない** — breadcrumbs 有無は監査 ticket-charge-7 でサイト共通ナビ方針の別判断とされ B 側）。
  - `plans-grid` に `PlanCard` を並べ、確認は **AI-CUE 既存の `organisms/ConfirmDialog.svelte`** を使う（aigenba の inline `Modal` + `@confirm-modal` selector は aigenba 側 browser test 都合の負債であり移植しない）。
  - 遷移先は既存 `POST /billing/checkout` のみ。**`plan-change` / `upgrade-now` は実装しない**（監査 pricing-plans-9 / bs-5 = 要プロダクト判断 = B）。
- 新規 `/workspace/resources/js/pages/Billing/_helpers/PlanCard.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/_helpers/PlanCard.svelte`
  - page-local adapter 規約（`Billing/Plans` 以外から import しない）をコメントごと踏襲。
  - **移植する**: `isCurrent`（headerBadges「現在のプラン」）/ `canSwitch` / `disabledReason` / `features` 組み立て / `formatYen` / `formatLimit`。
  - **移植しない（データ源が AI-CUE に無い）**: `includedSeats`・`currentSeatAmount`（席課金 = 概念設計スコープ外）、`isPending`（変更予約 = B）、`isStarter`/`starterMigrationText`（Starter 自動移行 = B）、`isEnterprise`/contact CTA（enterprise Plan 行なし）。features は AI-CUE の Plan 台帳（`monthlyTicketGrant` / `maxProjects` / `maxMembers` / `maxStorageGb`）で組む（`/workspace/resources/js/pages/Pricing.svelte:28-38` の `buildFeatures` と同じ出典）。
  - **DESIGN.md 適合の意図的な差**: aigenba は「変更不可」を `disabled` ボタン + `title`/`aria-label` で表現するが、AI-CUE の `/workspace/DESIGN.md:399-401` は「必須条件未充足を理由にボタンを disabled でブロックしない（disabled は理由を伝えられない）」を規約化している。**規約を優先し、`disabledReason` は常時可視の caption としてカード内に描画**する（情報は失わない）。rf-14 と同型の「aigenba の直書きを AI-CUE 既存 primitive/規約へ写す」判断。
- 変更 `/workspace/resources/js/components/molecules/PricingPlanCard.svelte`: `headerBadges?: Snippet` を追加し `<h3>` 行を `flex items-center justify-between` へ（← aigenba 同ファイル :29,:56-59）。`contactLabel` は追加しない。
- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php`: `plans()` を新設（← aigenba `BillingController::plans()` :399 相当）。`index()` からプラン一覧構築を移す。
- 変更 `/workspace/routes/web.php`: `Route::get('/billing/plans', [BillingController::class, 'plans'])->name('billing.plans');` を billing 群（課金ゲート allowlist 内）へ追加。**current-org スコープを維持**（監査 bs-13 = B。billing だけ slug 化しない）。

**(b) guest pricing の配置（pp-8 の配置部分のみ）**

- 移動 `/workspace/resources/js/pages/Pricing.svelte` → `/workspace/resources/js/pages/Guest/Pricing.svelte`（← `/tmp/aigenba/resources/js/pages/Guest/Pricing.svelte` の配置）。
- 変更 `/workspace/app/Http/Controllers/Marketing/PricingController.php:73`: `Inertia::render('Pricing', …)` → `Inertia::render('Guest/Pricing', …)`。**route path `/pricing`・route 名 `pricing`・SEO メタは不変**。
- **三層構成（personal banner / corporate grid / enterprise banner）と `?plan=` CTA は本フェーズで入れない**（前者は監査 pricing-plans-8 = B「Personal/Enterprise の実プラン存在が前提」、後者は P7 所管）。

**(c) 購入画面: per-bucket 残高 + 状態機械 + attempt_token 安定化（tc-5 / tc-2 の表示面）**

- 新規 `/workspace/resources/js/pages/Billing/ticketCount.ts` ← `/tmp/aigenba/resources/js/pages/Billing/ticketCount.ts`（`parseTicketCount` を verbatim 移植。`^-?\d+$` の符号付き整数のみ許容、clamp/floor しない）。`/workspace/resources/js/pages/Billing/PurchaseTickets.svelte:43-48` のインライン正規表現を置換。
- 変更 `/workspace/resources/js/pages/Billing/PurchaseTickets.svelte` ← aigenba 同名 :42,:205-215,:255-290,:302-347
  - 残高カードを **per-bucket 表示**へ（合計 / プラン付与残（有効期限あり）/ 購入済み残 / `balance-next-expire`）。P5 の `TicketBalanceDto` を出典にする。
  - `formState` に応じて `resume`（「決済を続ける」= 進行中 Checkout URL への `window.location.href`）/ `completed`（「もう一度購入する」= `?fresh=1`）/ `normal` を出し分け、resume/completed では `boundCount` を初期表示。
  - **単位は「枚」を維持**（aigenba の「回」は移植しない = 監査 ticket-charge-10、AI-CUE の製品語彙 = 可変コスト。概念設計の「唯一の意図的な非 parity」と同根）。
  - 既存の「購入したチケットに有効期限はありません」は **purchased バケツの説明として残す**が、per-bucket 表示の「購入済み残」直下へ移し、月次/signup grant 分と誤読されない位置にする（tc-10 の誤読リスク解消）。
  - submit ボタンは `disabled` にしない（DESIGN.md L399。既存 `/workspace/tests/js/pages/PurchaseTickets.test.ts:86` の契約を維持。aigenba の `disabled={countError !== null}` は移植しない）。
- 変更 `/workspace/app/Http/Controllers/Billing/TicketPurchaseController.php:68` ← aigenba `BillingController::showPurchase()` :461-532
  - `attemptToken: (string) Str::ulid()` の毎 render 発行をやめ、**`canManage && ! $request->boolean('fresh')` のときのみ** `TicketCheckoutService::resolveResumablePurchase()` 由来の token を再利用。
  - **非管理者には resume/completed を出さない**（`resumeUrl` は Stripe 直リンクで purchase gate を迂回する。aigenba の Codex R1 Critical と同じ罠）。
- 変更 `/workspace/app/Services/Billing/TicketCheckoutService.php`: `resolveResumablePurchase()` を追加（← aigenba `TicketService.php:1393-1417`）。
- 変更 `/workspace/config/billing.php`: `purchase_resume_window_minutes`（既定 30）を追加。

**(d) Customer Portal の事前ガード（bs-11）**

- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php:98-104` ← aigenba `BillingController::redirectToPortal()` :978-989
  - `Gate::authorize('manageBilling')` の後、**Stripe customer / 有償サブスクの事前確認**を入れ、不成立なら `back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。')`。Cashier `ManagesCustomer::billingPortalUrl()` の `assertCustomerExists()`（例外 = 500）に到達させない。
- 変更 `/workspace/resources/js/pages/Billing/Index.svelte:121`: `{#if canManageBilling}` を `{#if canManageBilling && canOpenPortal}` へ（サーバ判定を prop で受ける。UI 側で契約状態を再解釈しない）。

**(e) Billing/Index の情報密度（bs-14 / bs-10 の UI 面 / bs-7 の UI 面）**

- 変更 `/workspace/resources/js/pages/Billing/Index.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/Index.svelte`
  - `data-testid="plan-list"` のインラインプラン一覧（:139-171）を**撤去**し、「プラン比較」への導線（`billing.plans`）に置換。
  - 追加: quota 表示、`feedback` バナー（P2 由来。session_id を org スコープ照合済みの DTO のみを描画）、`BillingContactForm`、`#auto-recharge` anchor（P8a の設定カードの差し込み位置。`?highlight=auto-recharge` の着地先）。
  - 残高表示を per-bucket（P5 `TicketBalanceDto`）へ。
- 新規 `/workspace/resources/js/components/features/billing/BillingContactForm.svelte` ← aigenba 同名。

#### 波及変更

**TypeScript 型定義**
- `/workspace/resources/js/types/billing.ts`:
  - 追加 `TicketBalanceShape`（P5 の `TicketBalanceDto` と対。P5 が同ファイルへ追加済みなら再利用）
  - `PurchaseTicketsPageProps`: `balance: number` → `balance: TicketBalanceShape`、`tiers`/`minCount`/`maxCount`/`defaultCount`/`canManage`/`attemptToken`/`purchased` に加え `formState: 'normal'|'resume'|'completed'`、`boundCount: number | null`、`resumeUrl: string | null`、`newPurchaseUrl: string` を追加
  - 追加 `BillingPlanShape`（code/name/baseAmountJpy/monthlyTicketGrant/maxProjects/maxMembers/maxStorageGb）
  - 追加 `BillingPlansPageProps`（plans / currentPlanCode / canManage / attemptToken）
  - 追加 `BillingIndexPageProps`（currentPlanCode / currentPlanName / balance / canManageBilling / canOpenPortal / quotas / billingContact / feedback）
- `/workspace/resources/js/components/molecules/PricingPlanCard.svelte` の `Props` に `headerBadges?: Snippet`
- `/workspace/resources/js/types/marketing.ts`: 変更なし（Guest/Pricing は配置移動のみ）

**DTO / JsonResource**
- 新規 `/workspace/app/DataTransferObjects/Billing/BillingPlanDto.php`（`@phpstan-type BillingPlanShape`）
- 新規 `/workspace/app/DataTransferObjects/Billing/BillingPlansPageDto.php`（`@phpstan-type BillingPlansPageShape`）
- 新規 `/workspace/app/DataTransferObjects/Billing/BillingDashboardDto.php`（Index。現行 4 props の array 直書き `BillingController::index()` :60-65 を DTO 化 = 禁止事項 4 の遵守）
- 変更 `/workspace/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php`（`balance: int` → `TicketBalanceDto`、`formState`/`boundCount`/`resumeUrl`/`newPurchaseUrl` 追加）
- 新規 `/workspace/app/Enums/Billing/PurchaseFormState.php`（`Normal|Resume|Completed`。← aigenba `App\Enums\Billing\PurchaseFormState`）
- JsonResource: 追加なし（本フェーズは Inertia のみ）

**Inertia props**
- `Billing/Index`: 4 props → `page`（`BillingDashboardDto::toArray()`）1 本へ（PurchaseTickets / Pricing と同じ `['page' => $dto->toArray()]` 規約に揃える）
- `Billing/Plans`: 新規 `['page' => BillingPlansPageDto]`
- `Billing/PurchaseTickets`: `page` の shape 拡張
- `Pricing` → `Guest/Pricing`: component 名のみ変更（props 不変）

**テストファイル**
- 更新: `/workspace/tests/Feature/Billing/BillingPageTest.php`（:17 プラン一覧の期待を Plans へ移す / :93,:106 の props 名 / portal 期待）
- 更新: `/workspace/tests/Feature/Billing/TicketCheckoutTest.php`（attempt_token 安定化に伴う replay 期待）
- 更新: `/workspace/tests/Feature/Billing/PortalConfigurationTest.php`（事前ガード導入後の到達条件）
- 更新: `/workspace/tests/js/pages/Pricing.test.ts` → `/workspace/tests/js/pages/Guest/Pricing.test.ts`（import path のみ。**テストは削除しない**）
- 更新: `/workspace/tests/js/pages/PurchaseTickets.test.ts`（:57 残高 per-bucket / 新 formState ケース追加。:86,:110 の「disabled にしない」契約は維持）
- 更新: `/workspace/tests/js/components/molecules/PricingPlanCard.test.ts`（headerBadges）
- 新規: `/workspace/tests/Feature/Billing/BillingPlansPageTest.php` / `BillingPortalGuardTest.php` / `TicketPurchaseResumeStateTest.php`
- 新規: `/workspace/tests/js/pages/Billing/Plans.test.ts` / `Billing/PlanCard.test.ts` / `Billing/ticketCount.test.ts` / `Billing/Index.test.ts`
- 影響（変更なしで pass すること）: `/workspace/tests/js/architecture/{page-shell-structure,ds-purity,atomic-import-graph,lucide-scoped-import}.test.ts`

#### 主要な契約

ルート（current-org スコープ・課金ゲート allowlist 内）
```
GET  /billing        billing.index    BillingController@index
GET  /billing/plans  billing.plans    BillingController@plans   ← 新規
POST /billing/checkout / POST /billing/portal … 既存のまま
GET  /purchase-tickets?fresh=1 … 既存 route に fresh query を追加解釈
```

Controller
```php
public function plans(Request $request): Response;                       // Inertia::render('Billing/Plans', ['page' => BillingPlansPageDto])
public function index(Request $request, TicketLedgerService $tickets): Response; // ['page' => BillingDashboardDto]
public function portal(Request $request, SubscriptionCheckoutGateway $g): SymfonyResponse|RedirectResponse; // ← 戻り型に RedirectResponse 追加 (事前ガードの back())
```

Service（4 分割境界を維持）
```php
// App\Services\Billing\TicketCheckoutService
public function resolveResumablePurchase(Organization $org, int $userId, int $windowMinutes): ?TicketCheckoutSession;
// 2 段取得: (1) live pending (status=Pending かつ expires_at>now かつ checkout_url<>'') 最新 → resume
//           (2) completed (completed_at > now-window) 最新 → completed / (3) null → normal
// いずれも organization_id + initiated_by_user_id スコープ (cross-user の resumeUrl 漏洩を構造的に封じる)
```

DTO 形状（要点）
```
BillingPlanShape      = { code, name, baseAmountJpy: int|null, monthlyTicketGrant, maxProjects: int|null, maxMembers: int|null, maxStorageGb: int|null }
BillingPlansPageShape = { plans: list<BillingPlanShape>, currentPlanCode: string|null, canManage: bool, attemptToken: string }
PurchaseTicketsPageShape += { balance: TicketBalanceShape, formState: 'normal'|'resume'|'completed', boundCount: int|null, resumeUrl: string|null, newPurchaseUrl: string }
BillingDashboardShape = { effectivePlan: EffectivePlanShape, balance: TicketBalanceShape, canManageBilling: bool, canOpenPortal: bool, plansUrl: string }
// D25: billingContact / feedback は P2 に backend が無いため P8b から除外し P9 へ切り出す。D18: currentPlanCode(scalar) ではなく effectivePlan(DTO)。
```

DB 列 / index: **追加なし**（本フェーズは読み取り + UI のみ）。`ticket_checkout_sessions` の既存 `UNIQUE(organization_id, attempt_token)` / `initiated_by_user_id` / `expires_at` / `completed_at` をそのまま使う。

config: `billing.purchase_resume_window_minutes` = 30。

#### PHPStan 適合チェック (level 10)

- `plans()` / `index()` の戻り値は `Inertia\Response`、`portal()` は `SymfonyResponse|RedirectResponse`（`back()` 分岐のため union を明示。既存 `checkout()` と同型）。
- 全ページ props は `readonly` DTO の `toArray()` 経由（`response()->json()` 直書きなし）。DTO には `@phpstan-type …Shape` を付け、`@phpstan-import-type` で親 DTO へ合成（既存 `PurchaseTicketsPageDto` / `PricingPageDto` と同じ規約）。
- `resolveResumablePurchase(): ?TicketCheckoutSession` の null は controller 側 `match(true)` で `default => [PurchaseFormState::Normal, (string) Str::ulid(), null, null]` に縮退（null 安全。aigenba と同型）。分岐値の list 分解は phpstan が list shape を推論できるよう `match` の各腕で同じ arity・型順を返す。
- `config('billing.purchase_resume_window_minutes')` は `mixed` のため `Assert::integer()` を通してから `int` として渡す（`Webmozart\Assert` 既存パターン）。
- `$request->user()` は `Assert::isInstanceOf($user, User::class)`（既存踏襲）。`$request->boolean('fresh')` は `bool` 確定。
- Eloquent generics: `TicketCheckoutSession::query()` は `Builder<TicketCheckoutSession>`。`->first()` の `?TicketCheckoutSession` を widen しない。
- `Plan::query()->get()->map(...)` は `BillingPlanDto` を返し `->values()->all()` で `list<BillingPlanDto>`（`array_map` + `array_values` でも可。既存 `PricingController` と同じ）。
- **禁止**: `@phpstan-ignore` / baseline 追加 / 戻り値 widen。

#### テスト計画（テストファースト）

**先に red を作る（新規）**
1. `tests/Feature/Billing/BillingPortalGuardTest.php`（bs-11）
   - `stripe_id` null の組織の owner が `POST /billing/portal` → **302 back + `error` flash**（Stripe に到達しない = Fake gateway 未呼び出し）。現行実装では Cashier 例外に落ちる = red。
   - 有償サブスク保持 org の owner → 既存どおり `Inertia::location` で Portal URL。
2. `tests/Feature/Billing/TicketPurchaseResumeStateTest.php`（tc-5）
   - live pending session を持つ owner が `GET /purchase-tickets` → `formState='resume'`・`attemptToken` が **既存 session の attempt_token と一致**・`boundCount` = session の `ticket_count`・`resumeUrl` = `checkout_url`。現行は毎回 fresh ULID = red。
   - `?fresh=1` → `formState='normal'` かつ token が別値。
   - **完了済 session（窓内）** → `formState='completed'`・`resumeUrl` は null。
   - **非管理者（member）** → live pending が存在しても `formState='normal'` / `resumeUrl` null（resume 漏洩の回帰。aigenba Codex R1 Critical と同型）。
   - **他 user の pending は resume しない**（`initiated_by_user_id` スコープ）。
   - 二重課金回帰: resume 表示 → 同 token で `POST /purchase-tickets/checkout` → 既存 replay で同一 checkout URL へ収束し Stripe session が増えない。
3. `tests/Feature/Billing/BillingPlansPageTest.php`（bs-6）
   - owner: `GET /billing/plans` 200・`page.plans` に seeder の全プラン・`currentPlanCode` 一致・`canManage=true`・`attemptToken` 非空。
   - member: 200 だが `canManage=false`。
   - current org 無しユーザー: 404（既存 `BillingPageTest:87` と同型）。
4. `tests/js/pages/Billing/PlanCard.test.ts`
   - `isCurrent` で「現在のプラン」バッジ（headerBadges）。
   - `canSwitch=false` で **`disabledReason` が可視テキストとして描画**され、かつ `disabled` 属性の button が存在しない（DESIGN.md L399 の機械保証）。
5. `tests/js/pages/Billing/ticketCount.test.ts`
   - `parseTicketCount`: `'10'→10` / `'-5'→-5` / `'1e3'|'0x10'|'1.5'|'Infinity'|'-'|'1.'|''→null` / `10(number)→10`（防御的 `String(raw)`）。
6. `tests/js/pages/Billing/Plans.test.ts`
   - 「このプランへ変更」→ `ConfirmDialog` 表示 → 確認で `POST /billing/checkout` に `{plan_code, attempt_token}` を送る。
7. `tests/js/pages/Billing/Index.test.ts`
   - `canOpenPortal=false` で portal ボタンを描画しない。
   - `plan-list` を持たず「プラン比較」リンク（`/billing/plans`）を出す。

**既存テストの更新（削除しない）**
- `tests/Feature/Billing/BillingPageTest.php:17`「owner は /billing でプラン一覧・残高・管理フラグを見られる」→ プラン一覧の期待を `BillingPlansPageTest` へ移し、本 test は `page.currentPlanName` / `page.balance`(per-bucket) / `canManageBilling` / `canOpenPortal` の期待へ更新。:38 / :50 / :60 / :87 / :93 / :106 は props 名の追随のみ。
- `tests/Feature/Billing/PortalConfigurationTest.php`: 事前ガード導入後も spec が変わらないこと（ガードは spec でなく到達条件の変更）。
- `tests/Feature/Billing/TicketCheckoutTest.php`: 画面 render 由来の安定 token を使う経路の期待を追加（既存 replay/stale ケースは維持）。
- `tests/js/pages/PurchaseTickets.test.ts`: `:57` の `balance` fixture を `TicketBalanceShape` へ、per-bucket 3 値 + `balance-next-expire` の描画期待を追加。`:86`（範囲外でも disabled にしない）・`:102`（count + attempt_token を POST）は**契約として維持**。`formState='resume'|'completed'` の描画ケースを追加。
- `tests/js/components/molecules/PricingPlanCard.test.ts`: `headerBadges` snippet を渡した場合に header 右へ描画され、渡さない場合は既存出力が不変であること（回帰）。
- `tests/js/pages/Pricing.test.ts` → `tests/js/pages/Guest/Pricing.test.ts`: import path のみ変更（内容不変 = 配置移動が挙動を変えないことの回帰）。

**arch テスト（変更せず pass）**: `page-shell-structure`（新設 `Billing/Plans.svelte` が PageContainer/PageHeader/PageContent を使う。`_helpers/PlanCard.svelte` は AppLayout を import しないため対象外）/ `ds-purity`（hex 直書き禁止。aigenba の `bg-primary/10` は AI-CUE の `bg-primary-soft` へ写す = 既存 `Pricing.svelte:165` の先例に合わせる）/ `atomic-import-graph`（`_helpers` は pages 層のため下層規則の対象外。`features/billing` から pages を import しない）/ `lucide-scoped-import`。

#### リスク

| リスク | 緩和 |
|---|---|
| **Index からプラン一覧 (`data-testid="plan-list"`) を撤去**すると、既存 Feature / bug-hunt シナリオが参照喪失する | 撤去前に `grep -rn 'plan-list\|checkout-' tests/ devnotes/` で参照を洗い出し、期待を Plans へ移す（削除でなく移設）。Index には `billing-plans-link` を残し導線を切らない。 |
| **attempt_token 安定化で正当な追加購入を握り潰す**（completed 直後に別枚数で買えない） | `?fresh=1`（`newPurchaseUrl`）を必ず露出し、`completed`/`resume` の両状態から到達可能にする。窓は `purchase_resume_window_minutes`(30) で有限化。 |
| **resume の Stripe 直リンクが purchase gate を迂回** | `canManage` false では resume/completed へ縮退（fresh token）。Feature テストで固定（aigenba Codex R1 Critical の再演防止）。 |
| **per-bucket 残高が P5 未マージだと成立しない** | P8b は P5 の後段。`TicketBalanceDto` を DTO 境界でのみ参照し、`TicketLedgerService` の計算には触れない。P5 が遅延した場合は本フェーズの (c) 残高表示のみ切り出して後送する。 |
| **Guest/Pricing 移動で SSR / e2e の component 名参照が壊れる** | route path・route 名・SEO メタは不変。`grep -rn "'Pricing'\|\"Pricing\"" app/ tests/ resources/` で参照を全置換し、既存 `Pricing.test.ts` を移設して回帰にする。 |
| **DESIGN.md「disabled でブロックしない」と aigenba の disabled UI の衝突** | 規約優先（rf-14 と同型の primitive 適合判断）。理由文言は失わず可視 caption 化し、`PlanCard.test.ts` で「disabled 属性の button が無い」ことを機械保証。→ openQuestion で上位確認。 |
| **Index の props 一括変更 (4 props → `page`) の破壊範囲** | 同一 PR で Feature/JS 両テストを更新。DTO 化は禁止事項 4（`response()->json()` / array 直書き回避）の遵守でもあり後戻りしない。 |
| **P8a（auto-recharge カード）と Index を同時に触る競合** | P8b は `#auto-recharge` anchor と差し込みスロットのみ用意し、カード実体は P8a 所管とする（マージ順は P8a → P8b）。 |

##### 起草時の未決事項（上位決定は冒頭 §横断決定 / §ユーザー判断を要する残件 を参照）

- P1 が seed する PlanCode 集合が確定していない（概念設計は『Personal・Starter』と書くが、Starter 自動移行・早期アップグレード・enterprise の Plan 行化は監査 B 側の未判断事項）。Starter が実プランとして seed されるなら PlanCard に starter warning バレット（starterMigrationText）が要り、enterprise が Plan 行になるなら PricingPlanCard の contactLabel 分岐が要る。本設計は『Starter 自動移行なし・enterprise は Plan 行にしない（カード外バナー維持）』を前提に書いた。前提が違う場合 PlanCard / PricingPlanCard の props 集合が変わる。
- ~~billing-subscription-7 の backend 所属~~ → **D25 で確定: P8b から除外し独立フェーズ P9 へ切り出す**（P2 の非スコープであることが確定したため、P8b が抱えると UI parity フェーズの範囲を超える）。
- Billing/Index の auto-recharge 設定カードの所有フェーズ（P8a か P8b か）。本設計は P8a 所有（P8b は anchor と差し込み位置のみ）とし、マージ順を P8a → P8b としたが、概念設計は『P8a 裏チャージ / P8b UI parity』としか書いていない。
- AI-CUE の DESIGN.md L399-401『必須条件未充足を理由にボタンを disabled でブロックしない』と、aigenba の PlanCard「変更不可」disabled ボタン / PurchaseTickets の submit disabled が正面衝突する。本設計は『AI-CUE 既存規約を優先（rf-14 と同型の primitive/規約適合）し、理由文言は可視 caption として保持』と決めたが、ユーザー方針『aigenba にある物は aigenba の形で移植する』の解釈として妥当か上位確認したい。
- チケットの単位（枚 vs 回）は監査 ticket-charge-10 で B（要判断）とされ、本設計は『枚』を維持した。一方で per-bucket 表示の文言は aigenba の『プラン付与残（有効期限あり）／購入済み残／次の失効』を移植する。同一画面に aigenba 由来の文言と AI-CUE 語彙が混在するため、per-bucket ラベルの最終文言と『購入したチケットに有効期限はありません』の残置可否を確認したい。

---
