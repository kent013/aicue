Round 9 の Warning 4 件を全て対応した（Critical 0。設計ロジックの変更はなく記述統一のみ）。

- P3 Plan 集合節 / P4 非スコープ節 → **「Starter のみ P8b。Personal は P3 で公開済み」** へ統一。
- P4 一覧の矛盾 → **「`personal` は true（P3 で公開済み）/ `starter` のみ false」** へ統一。
- P7 の `CreateNewUser` → **「P7 は P6 の後に入るため grant 呼び出しは既に存在しない（P6 で撤去済み。P7 で復活させない）」**、
  `RegistrationTest` → **「登録時は未付与の期待を維持し、session キー（intended plan）の期待を追加」** へ修正。

改訂後の詳細設計書を全文添付する。残る穴があれば指摘し、無ければ APPROVED を出してほしい。

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
| **D10** | `/pricing` への personal/starter 露出制御 | aigenba の `plans.is_active` を P1 で移植し、**P1 の seed 時点では両方 `is_active=false`**。**Personal は P3 で `is_active=true` へ**（data migration。Personal の「購入導線」= `activate-personal` は P3 で揃うため、**P4 のゲート反転時点で無料導線が公開されている**必要がある）。**Starter は P8b で `is_active=true` へ**（有償のため checkout UI が要る） | 購入できないプランを料金表に出すのは H8/H12 型の UX 破綻。一方 **Personal を非公開のまま P4 で反転すると、未契約者が無料を選べず Standard しか選べない = F-07 再発の変種**になる（Codex Round 6 Critical）。`is_active` は aigenba に存在する機構なので独自実装ではない |
| **D11** | 既存 `free` Plan 行と `fallback_plan='free'` | **P1〜P3 は personal と併存 → P4 の grandfathering と同時に撤去** | 併存中に料金表へ 2 行出さないことは D10 で担保。撤去を P4 に置くのは、free fallback の消滅とゲート反転が同一の意味変更だから |
| **D12** | `config/quota.php` に `personal`/`starter` の limits | **P1 で必ず追加**（personal は free と同値） | `QuotaService.php:33` が未知キーを `?? []` = **無制限に silent 退行**させる（重大な後退。起草で発見） |
| **D13** | 移行期の `claimSignupGrantMarker()` public 化 | **許容**（P6 で private へ戻す。移行専用 API と docblock に明記） | 概念設計の移行期規約 5（付与と marker を同一 tx）を成立させるために必要 |
| **D14** | `PlanPriceService` への `?string $lookupKey` 追加 | **許容**（adaptation） | AI-CUE の `SyncStripePrices.php:78-87` が current 行の `lookup_key` 一致を要求するため。verbatim 移植すると既存 sync 契約が壊れる |
| **D15** | JSON/XHR への 402 応答 | **維持**（aigenba は常に redirect） | 既存 API/XHR クライアントの後退を避ける。UI 導線の parity には影響しない |
| **D16** | `Welcome.svelte` の `/register` 直リンク | **P7 で `/pricing` 誘導へ寄せる**（aigenba の Landing は `/register` 直リンクを持たない） | F1 でプラン選択が必須関門になる以上、直リンクは「選ばせない導線」で矛盾する |
| **D17** | チケット単位（枚 vs 回） | **「枚」を維持** | AI-CUE 全体の既存語彙。per-bucket ラベルは aigenba の意味（プラン付与残/購入済み残/次の失効）を「枚」語彙で表現する |
| **D18** | **判定モデルの単一化**（P2↔P4 の二重化解消） | **`EffectivePlan` を唯一の判定源に固定**。`OnboardingBillingState` は**導入しない**。`BillingAccess` / middleware / Controller は全て `EffectivePlan` を参照し、P4 は **`NoPlan` variant の `grantsAccess()` だけを変更**する（**D23 で上書き済み**。当初 `GrandfatheredLegacyFreePlan` と書いていたのは誤り） | 並列起草で `EffectivePlan` 系と `OnboardingBillingState` 系が混在していた（Codex 詳細レビュー Critical）。二重化すると P4 の「反転を 1 箇所に閉じる」が成立せず `grantsAccess()` の責務が分散して **F-07 再発余地**が残る。aigenba は 2 段（`OnboardingBillingState` + `SubscriptionEntitlementDto`）だが、AI-CUE には subscription checkout session テーブルが無く Pending/ExpiredCheckout 状態が表現できないため、2 段構成は**移植対象が存在しない**（原則 4） |
| **D19** | **返金過多の負残高**（旧 U1。金銭） | **債務保全で確定（clamp しない）**。判定用の `availableTrueBalance()` は非負を維持しつつ、**会計残高 DTO に `debt` を明示**する | aigenba の per-source `max(…,0)` clamp を移植すると、購入→消費→全額返金の債務が以後の付与から回収されず **タダ乗り経路**になる（`TicketRefundClawbackTest:147` の `-2` が `0` に）。**金銭の後退は parity に優先しない**。表示 clamp と判定値を分離することで aigenba の「表示に balance()、判定に使うと負残高で誤判定する」規約とも整合する |
| **D20** | `billing:reconcile-auto-recharge` の停止 | **監視アラートの実装 / 既存監視への接続確認を P8a の DoD に必須化**（設計項目として明文化。「注意喚起」で終わらせない） | 本コマンドは webhook が恒久 drop した「**課金済み・チケット未付与**」を回収する唯一の経路。静かに止まると資金回収済み・未付与が長期滞留し、ユーザー被害 + 会計不整合になる |
| **D26** | **paid 判定を `plan_code` に依存させない**（webhook 同期ラグ組織の締め出し防止） | `effectivePlan()` は **active/trialing subscription があれば最優先で `PaidSubscriptionPlan`** へ解決する（`plan_code` の null 有無を見ない）。plan code は subscription の price から org-scoped に解決する。**price から解決できない場合も `PaidSubscriptionPlan(planCode: null, grantsAccess: true)` を返す**（fallback quota を適用し、**ログ・監視を必須**とする）。**`GrandfatheredLegacyFreePlan` へ倒してはならない**（有償契約中なのに kind/quota が personal 扱いになり型の意味が壊れるため）。`free_plan_code='personal'` でない組織を Grandfathered variant へ入れない | `plan_code=null` + active sub + `free_plan_code=null` の**同期ラグ組織**は backfill 対象外なのに、`plan_code !== null` 依存の判定では `NoPlan` に落ち **P4 後に課金済みユーザーが締め出される**（Codex Round 3 Critical）。解決不能時に grandfather へ倒す当初案は型の意味を壊すため撤回（Codex Round 4/5 Critical） |
| **D27** | **`reserve()` も debt 控除後の利用可能額で判定する** | `balance()` / `availableTrueBalance()` / `reserve()` / auto-recharge は**同一の内部 snapshot** を使う。不足判定は **`availableTrueBalance < amount`** に統一。配賦は `debtAmount = max(-(purchasedRaw), 0)` / `monthlySpendable = max(monthlyPositive - debtAmount, 0)` / `purchasedSpendable = purchasedPositive`。**`debt` は raw ledger の負値から算出し hold とは分離**する（予約 hold で増減する「債務」ではない） | D24 で表示計算にのみ debt を入れたため、`reserve()` が依然 `availableMonthly + availablePurchased < amount` で判定し、**monthly=10 / purchased debt=-2（真の利用可能額 8）で `reserve(10)` が通る**（Codex Round 3 Critical） |
| **D23** | **`NoPlan` variant の追加**（D18 の完成） | `EffectivePlan` を **4 variant** に分離: `PaidSubscriptionPlan` / `ActivatedPersonalPlan` / `GrandfatheredLegacyFreePlan`(declarer-less = P4 backfill 済) / **`NoPlan`**(未契約)。**P4 の変更は `NoPlan::grantsAccess()` を false にする 1 点のみ** | 3 variant では「backfill 済み既存 org」と「新規未契約 org」が同一 variant に畳まれ、P4 で false にすると**既存 org も遮断(F-07 再発)**、true なら**未契約を遮断できない**（Codex Round 2 Critical） |
| **D24** | **debt の相殺は「書込み」でなく「残高計算」で一度だけ**（数式の正本は **D27**） | grant 行の `delta` は変更しない。相殺は残高計算側でのみ行う。**正規数式は D27 を唯一の正本とする**（`debtAmount = max(-purchasedRaw, 0)` を raw ledger から算出し hold と分離）。DTO 境界では **debt を正数**で表現 | 付与時に相殺すると、台帳合計が自然に債務控除済みなのに更に grant を減額して **二重回収**になる。また source 別 clamp のみだと monthly grant がある時に purchased 債務が回収されない（Codex Round 2 Critical）。**hold 込みの旧式 `min(purchasedRaw - purchasedHold, 0)` は誤り**（hold で DTO 上の債務が増減する）ため使用禁止（Codex Round 4 Critical） |
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

### P1 プラン基盤 (PlanCode / plans.is_active / PlanPriceService / free plan・signup marker 列 / PersonalPlanService / marker backfill)

**DoD**: **ゲートは反転しない = 既存挙動不変**。`BillingAccess::hasActiveAccess()` は無改変で、既存の業務ルート到達性・付与枚数・`/pricing` の表示件数はいずれも変わらない。列追加はすべて additive（既存行の書き換えをしない）。`PersonalPlanService::activate()` は **P1 で完成**させる（marker claim + grant を含む）が、まだどの route からも呼ばれない（配線は P3）。付与契機は移行期規約に従い **P6 まで登録時を維持**する。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| AI-CUE | 内容 | 移植元 |
|---|---|---|
| `app/Enums/PlanCode.php`（新規） | `Personal='personal' / Starter='starter' / Standard='standard'` の **3 case**。`requiresStripeCheckout()` は Personal のみ false。**`isSeatFixed()` は移植しない**（AI-CUE に席概念・`plans.included_seats` が無い）。Business / Enterprise は Plan 行も価格体系も無いため case を作らない | `/tmp/aigenba/app/Enums/PlanCode.php` |
| `database/migrations/2026_07_17_000100_add_free_plan_and_signup_grant_marker_to_organizations.php`（新規） | **verbatim**: `free_plan_code`(string 32 nullable)・`free_plan_activated_at`・`personal_declared_at`・`personal_declared_by_user_id`(FK users nullOnDelete)・`signup_tickets_granted_at` + `index('free_plan_code')` + raw partial unique index。`down()` も verbatim | `/tmp/aigenba/database/migrations/2026_07_08_113500_add_free_plan_and_signup_grant_marker_to_organizations.php` |
| `database/migrations/2026_07_17_000110_backfill_signup_tickets_granted_at.php`（新規） | **verbatim の data migration（列追加とは別 migration に分離）**。既存 `ticket_ledger_entries` の `idempotency_key LIKE 'signup_grant:%'` 履歴を持つ org に `min(granted_at)` で marker を立てる。`whereNull` ガードで冪等。`down()` は意図的 no-op。AI-CUE の実スキーマと完全一致（`ticket_ledger_entries` / `organization_id` / `idempotency_key` / `granted_at` が実在: `create_ticket_tables.php:37-61`） | `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php` |
| `database/migrations/2026_07_17_000120_add_is_active_to_plans.php`（新規） | `plans.is_active`(boolean, default true) を追加。既存 `free` / `standard` 行は default で true になり **料金表の表示は変わらない** | aigenba `create_plans_table.php:19` |
| `app/Models/Billing/Plan.php` | `is_active` を `$fillable` と `casts()`(bool) に追加。`@property bool $is_active` を docblock へ | `/tmp/aigenba/app/Models/Billing/Plan.php:23,40,45` |
| `app/Services/Marketing/PricingService.php` | `listPublicPlans()` のクエリに `->where('is_active', true)` を追加（公開制御の唯一の場所） | aigenba `plans.is_active` の露出制御 |
| `database/seeders/PlanSeeder.php` | `personal`（Price 無し・`monthly_ticket_grant=0`・`sort_order=0`）と `starter`（base ¥980・`monthly_ticket_grant=10`・`sort_order=1`）を `updateOrCreate` で追加。**personal / starter は `is_active=false` で seed**（**Personal は P3 で `is_active=true` へ**（`activate_personal_plan` migration。activate-personal 導線が P3 で揃うため）/ **Starter は P8b で `is_active=true` へ**（有償のため checkout UI が要る）。D10）。既存 `free`(sort_order=0→維持) / `standard` は残し `is_active=true`。aigenba に倣い **`is_active` は属性配列に入れず `wasRecentlyCreated` 時のみ確定**する（運用者が手で変えた値を seed 再実行で踏み潰さない） | `/tmp/aigenba/database/seeders/PlanSeeder.php:34-124`（SPECS の personal / starter） |
| `app/Support/Billing/StripePriceLookupKeys.php` + `stripe/fixtures/plan_starter.json`（新規） | `CATALOG` に `'starter' => [PlanPriceKind::Base]` を追加し fixture（unit_amount=980 / lookup_key=`{slug}_starter_base`）を同時追加。`StripePriceCatalogFixtureInvariantTest` が集合一致を強制するため両者は必ず同一 PR。personal は Checkout 非対象のため追加しない（aigenba の Personal=Price skip 規約と同値） | `/tmp/aigenba/database/seeders/PlanSeeder.php:130` の Personal skip 規約 |
| `config/quota.php` | `plans` に `personal`（`max_projects=1` / `max_members=3` / `max_storage_bytes=1GiB` = 既存 `free` と同値）と `starter`（`max_projects=3` / `max_members=3` / `max_storage_bytes=5GiB`）の limits を追加。`fallback_plan` は **`'free'` のまま**（撤去は P4）。**未追加だと `QuotaService.php:33` が `?? []` で無制限に silent 退行する**（プラン能力が消える重大な後退） | — |
| `app/Services/Billing/PersonalPlanService.php`（新規） | `eligibility()` / `activate()` / `retireForPaidSubscription()` を移植し、**`activate()` を P1 で完成**させる（org 行 `lockForUpdate()` → eligibility 再検証 → `forceFill` → marker の条件付き先取 → 先取できた場合のみ `grantSignupGrant`。すべて同一 transaction）。`QueryException` の declarer unique 違反は `PersonalPlanNotEligibleException(AlreadyHasFreePersonalOrg)` に変換し **並行 activate の後着を 500 にしない** | `/tmp/aigenba/app/Services/Billing/PersonalPlanService.php` |
| `app/DataTransferObjects/Billing/PersonalPlanActivationResultDto.php`・`PersonalPlanEligibilityDto.php`（新規） | verbatim（`@phpstan-type PersonalPlanEligibilityShape` 込み） | 同名 aigenba ファイル |
| `app/Enums/Billing/PersonalPlanIneligibleReason.php`（新規） | verbatim（`HasEntitledSubscription` / `TooManyMembers` / `AlreadyHasFreePersonalOrg` + `label()` の日本語文言） | `/tmp/aigenba/app/Enums/Billing/PersonalPlanIneligibleReason.php` |
| `app/Exceptions/Billing/PersonalPlanNotEligibleException.php`（新規） | verbatim（`userMessage()`）。Controller 層で 422 に変換する前提（500 にしない根拠。変換は P3） | 同名 aigenba ファイル |
| `app/Services/Billing/PlanPriceService.php`（新規） | `replaceCurrent()` を移植。AI-CUE の `plan_prices` は `lookup_key` / `livemode` / `synced_at` と CHECK（`is_current ⇔ active_to IS NULL`）を持つため **`?string $lookupKey` 引数を追加**する adaptation を採る（`SyncStripePrices.php:78-87` が「kind + is_current + lookup_key 一致」の current 行を要求するため、lookup_key を書かない verbatim 移植は既存 sync 契約を壊す） | `/tmp/aigenba/app/Services/Billing/PlanPriceService.php` |
| `app/Models/Organization.php` | 新 5 列を `casts()` に追加（4 timestamp は `immutable_datetime` / `personal_declared_by_user_id` は `integer`）。`$fillable` は不変（書き込みは `PersonalPlanService` の `forceFill` 経由のみ）。docblock に「free entitlement は `free_plan_code` 側で表現される」を追記。**`plan_code=null=free tier` の既存記述は P4 まで有効なので消さない** | aigenba `Organization` |
| `app/Support/Security/MassAssignmentProtectedKeys.php` | actor キーとして `'personal_declared_by_user_id'` を追加（`MassAssignmentSafetyTest` が `$fillable` 不含を検証する） | — |
| `app/Services/Billing/TicketLedgerService.php` | `grantSignupGrant(Organization $organization, string $idempotencyKey): void` へ**シグネチャ変更**（内部生成キーをやめ、呼び出し側が `signup_grant:org:{orgId}` / `signup_grant:personal:{orgId}` を渡す）。付与枚数・期限・`insertOrIgnore` の冪等は不変 | `/tmp/aigenba/app/Services/Billing/TicketService.php:261` `grantSignupGrant(Organization, string)` |
| `app/Actions/Fortify/CreateNewUser.php` (L106) | **移行期規約**: 既存の登録 tx 内で `PersonalPlanService::claimSignupGrantMarker($org)` を呼び、**先取できたときのみ** `grantSignupGrant($org, "signup_grant:org:{$id}")`。org 行 `lockForUpdate()` 下・同一 tx。**付与契機・付与枚数・招待経由は非付与という現行挙動は不変**（marker を同時に立てるだけ） | 概念設計 §signup grant の冪等移行 規約 5 |
| `app/Services/Billing/StripeWebhookProcessor.php` (L266-271) | `grantSignupGrant($organization)` → `grantSignupGrant($organization, "signup_grant:org:{$organizationId}")` の**引数適合のみ**（付与結果は現行と同値）。paid 経路の marker claim ブロック追加は **P6**。P1〜P5 の paid 経路は現行どおり `ticket_ledger_entries_signup_grant_unique` が org 生涯 1 回を DB 強制する | — |

**移植時の adaptation（aigenba → AI-CUE。いずれも名前解決か既存契約への適合で、意味論は不変）**

- `TicketService` → `TicketLedgerService`。`SubscriptionService` 依存は P1 では持たない（下記 seam）。
- `Role::OrganizationOwner` → `App\Enums\OrganizationRole::Owner`（値 `organization_owner`）。laratrust pivot は AI-CUE も `role_user` + `role_user.team_id`（`config/laratrust.php:151`）で、`hasOtherActiveFreePersonalOrg()` の `whereColumn('role_user.team_id', 'organizations.laratrust_team_id')` はそのまま成立する。
- `hasEntitledSubscription()`: P2 の `SubscriptionService::deriveEntitlement()` が未着のため、P1 は `subscription('default')?->stripe_status ∈ {active, trialing}`（= 現行 `BillingAccess::GRANTING_STATUSES` と同値）で実装し、docblock に **P2 で `deriveEntitlement()` へ差し替える seam** と明記する。
- `MAX_MEMBERS = 3`（`config/quota.php` の `personal.max_members=3` と一致。invariant テストで固定）。
- `claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool` を **移行期は public**（aigenba では `activate()` 内 private）。登録経路と共用するための**移行専用 API**であることを docblock に明記し、**P6 で private へ戻す**（D13）。

#### 波及変更

- **TypeScript 型定義**: なし（P1 は Inertia props を一切変えない。`PersonalPlanEligibilityShape` の TS 対応は P3 で `Onboarding/*` と同時に入れる）。
- **DTO / JsonResource**: 新規 `PersonalPlanActivationResultDto` / `PersonalPlanEligibilityDto`（いずれも P1 時点では Controller から返さない = Service 戻り値のみ）。既存 `PricingPlanDto` / `PricingPageDto` は**形状不変**。
- **Inertia props**: なし。`Pricing.svelte` の `page.plans` は `is_active=false` により **配列長も不変**。
- **Factory**: `database/factories/OrganizationFactory.php` に `freePersonal(User $declarer)`（`free_plan_code='personal'` + declared_* を state で設定）/ `grandfathered()`（declarer-less）/ `signupGranted()` state を追加（テストデータ手組み禁止のため）。
- **テストファイル（更新。削除しない）**:
  - `tests/Feature/Billing/TicketGrantTest.php` — `grantSignupGrant` 呼び出しに `$idempotencyKey` 引数を追加。
  - `tests/Feature/Billing/WebhookIdempotencyTest.php` (L138) — 「冪等キーは内部生成」前提のコメント/期待を「呼び出し側が渡す `signup_grant:org:{id}`」へ更新（値・挙動は同値）。
  - `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` — `seededFreePlan()` が「current base Price を持たない最初の Plan」を拾うため personal 追加で対象が非決定になる。`code='free'` 固定へ更新（**ゲート期待値は変えない** = 挙動不変の証明）。
  - `tests/Feature/Billing/PlanSeederPriceInvariantTest.php` — 「有償プラン starter は current base Price を持つ」「personal は Price を持たない」を追加。既存 free / standard の期待は維持。
  - `tests/Feature/Billing/SyncStripePricesCommandTest.php` / `VerifyStripePricesCommandTest.php` — starter fixture + lookup_key 追加の影響を反映。
  - `tests/Feature/Auth/RegistrationTest.php` — 登録時付与（枚数・期限は不変）に加え `signup_tickets_granted_at` が**同一 tx で立つ**期待を追加。
  - `tests/Architecture/MassAssignmentSafetyTest.php` / `MassAssignmentStrictModeTest.php` — コード変更不要（キー追加で自動検証）。
  - `tests/Architecture/StripePriceCatalogFixtureInvariantTest.php` / `QuotaKeyConfigInvariantTest.php` — コード変更不要（fixture・config 追加で自動的に集合一致）。
  - `tests/Feature/Marketing/PricingPageTest.php` / `tests/js/pages/Pricing.test.ts` / `tests/js/pages/Welcome.test.ts` — **件数・カード期待は不変**（personal / starter は `is_active=false`）。回帰確認のみで更新は不要。
- **テストファイル（新規）**: `tests/Feature/Billing/PersonalPlanServiceTest.php` / `tests/Feature/Billing/SignupGrantOncePerOrgTest.php` / `tests/Feature/Billing/PlanActiveFilterTest.php` / `tests/Architecture/FreePlanCodeWriteInvariantTest.php`（aigenba 同名を移植）。

#### 主要な契約

```php
enum PlanCode: string {
    case Personal = 'personal'; case Starter = 'starter'; case Standard = 'standard';
    public function requiresStripeCheckout(): bool;   // Personal のみ false
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
final readonly class PersonalPlanEligibilityDto {          // eligible() / ineligible(reason) / toArray()
    public bool $eligible; public ?PersonalPlanIneligibleReason $reason; }
enum PersonalPlanIneligibleReason: string { HasEntitledSubscription | TooManyMembers | AlreadyHasFreePersonalOrg; }

class TicketLedgerService { public function grantSignupGrant(Organization $o, string $idempotencyKey): void; }

class PlanPriceService {
    public function replaceCurrent(Plan $plan, PlanPriceKind $kind, string $stripePriceId, int $amount,
        ?string $lookupKey = null, string $currency = 'jpy', ?CarbonImmutable $activeFrom = null): PlanPrice;
}
```

`activate()` の中核（marker claim + grant を含む完成形）:

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
→ declarer NULL 行（P4 の grandfathered）は **index 対象外**。
**DB（`plans`）**: `is_active boolean NOT NULL DEFAULT true`。
**冪等キー**: `signup_grant:org:{orgId}`（登録経路 = 移行期）/ `signup_grant:personal:{orgId}`（activate）。既存の partial unique `ticket_ledger_entries_signup_grant_unique ON (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'`（`2026_07_13_180622`）が**キー種別を跨いで org 生涯 1 回を DB 強制済み** → marker と 1:1 の二重防御。
**ルート**: 追加・変更なし（P3）。

#### PHPStan 適合チェック（level 10）

- `Organization::query()->lockForUpdate()->findOrFail($org->id)` は `Builder<Organization>` generics から `Organization` を返す（`@var` 不要）。`$org->id` は `int|null` のため `Assert::integer()` で絞る（現行 `TicketLedgerService::grantSignupGrant` と同じ作法）。
- `DB::table('organizations')->…->update([...])` は `int` 戻り → `$claimed === 1` は型安全。
- `QueryException::getCode()` は `Throwable::getCode()` の宣言上 `int` だが PDO 由来は string を返す。aigenba の `in_array($e->getCode(), ['23000','23505'], true)` は level 10 で `alwaysFalse` になるため **`in_array((string) $e->getCode(), ['23000','23505'], true)`** とする（意味論は不変。禁止事項 2 の widen ではなくキャストによる正規化）。
- `PersonalPlanEligibilityDto::toArray()` は `@phpstan-type PersonalPlanEligibilityShape array{eligible: bool, reason: string|null, reasonLabel: string|null}` で配列形状を固定（aigenba verbatim）。
- `hasOtherActiveFreePersonalOrg()` のクロージャ引数は `Illuminate\Database\Eloquent\Builder $q` を型注釈。戻りは `exists()` の `bool`。
- `config()` 参照は `config()->string()` / `config()->array()` か `Assert::integer()` 経由（既存 `grantSignupGrant` / `PricingService` の作法を踏襲）。
- 新 casts は `protected function casts(): array` の `array<string, string>` 契約内（`immutable_datetime` / `integer` / `boolean`）。
- `PlanCode` は 3 case で閉じており `match` は網羅。存在しない case への `identical` 比較を作らないため `alwaysFalse` は発生しない。
- `response()->json()` の直書きは無し（P1 は Controller を触らない）。

#### テスト計画（テストファースト）

**先に red を作る（新規）**

1. `tests/Feature/Billing/PersonalPlanServiceTest.php`
   - `activate()` が `free_plan_code='personal'` / `free_plan_activated_at` / `personal_declared_at` / `personal_declared_by_user_id` を埋め、`signup_tickets_granted_at` を立て、`config('billing.signup_grant_tickets')` 枚を **1 回だけ**付与し `granted=true` を返す（**activate は P1 で完成している**ことの固定）。
   - 同一 org の再 `activate()` は `granted=false` かつ**残高不変**（marker 先取が 0 件）。
   - `eligibility()` の 3 理由: 有効 subscription あり / メンバー 4 名 / 同一 declarer の別 free personal org。
   - **並行 activate の後着**: 同一 declarer で別 org を `activate()` → partial unique 違反が `PersonalPlanNotEligibleException(AlreadyHasFreePersonalOrg)` に変換され、**`QueryException` が漏れない（= 500 にしない）**。
   - `retireForPaidSubscription()` の冪等（2 回目 no-op。`personal_declared_*` は監査証跡として残る）。
2. `tests/Feature/Billing/SignupGrantOncePerOrgTest.php`（**P1 の要**）
   - **free activate ↔ paid webhook の競合で二重付与しない**: activate 済み org に `invoice.paid (billing_reason=subscription_create)` → 付与 0（ledger 行数 1 のまま）。逆順（paid 成立済み org を activate）は `eligibility()` の `HasEntitledSubscription` で弾かれ付与 0。
   - **移行期回帰（必須）**: 移行期に `CreateNewUser` 経由で登録された org（marker 済み）を **P6 後相当の `activate()` に掛けても再付与されない**（`granted=false`・残高不変）。
   - **backfill migration**: 既存 `signup_grant:org:{id}` 履歴のある org は marker が `min(granted_at)` で立ち、履歴の無い org は null のまま。再実行しても値が動かない（冪等）。
3. `tests/Feature/Billing/PlanActiveFilterTest.php` — `is_active=false` の Plan は `/pricing` の props に出ない / `is_active=true` の Plan は出る。
4. `tests/Architecture/FreePlanCodeWriteInvariantTest.php` — `app/` 内の `free_plan_code` 書き込みは `PersonalPlanService` に限定（aigenba verbatim）。
5. invariant: `PersonalPlanService::MAX_MEMBERS === config('quota.plans.personal.max_members')` / **全 `Plan.code` が `config('quota.plans')` に存在する**（`QuotaService.php:33` の `?? []` による無制限 silent 退行を機械検知）。

**既存テストの更新（削除しない）**: `tests/Feature/Billing/TicketGrantTest.php` / `WebhookIdempotencyTest.php` / `SeededFreePlanBillingAccessTest.php` / `PlanSeederPriceInvariantTest.php` / `SyncStripePricesCommandTest.php` / `VerifyStripePricesCommandTest.php` / `tests/Feature/Auth/RegistrationTest.php`。

**挙動不変の固定（回帰。期待値を変えない）**: `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php` / `SeededFreePlanBillingAccessTest.php`（ゲート未反転の証明）/ `tests/Feature/Marketing/PricingPageTest.php` / `tests/js/pages/Pricing.test.ts` / `tests/js/pages/Welcome.test.ts`（`is_active=false` により料金表の件数不変）。`Welcome.svelte:349` の文言は P1 では触らない（付与契機は登録時のまま = 文言は依然事実。修正は P6）。

#### リスク

| リスク | 緩和 |
|---|---|
| `config/quota.php` に personal / starter の limits を入れ忘れると `QuotaService.php:33` の `?? []` で**無制限に silent 退行**する | 同 PR で limits を追加し、「全 `Plan.code` が `quota.plans` に存在する」invariant テストを追加（`QuotaKeyConfigInvariantTest` と同格の機械検証） |
| `grantSignupGrant` のシグネチャ変更で呼び出し漏れ | 呼び出し元は 2 箇所のみ（`CreateNewUser.php:106` / `StripeWebhookProcessor.php:270`）。引数を必須にすることで PHPStan level 10 が漏れを静的検出する |
| 移行期の marker claim を入れ忘れた org が P6 後に再付与される | 上記テスト 2 の移行期回帰 + `ticket_ledger_entries_signup_grant_unique` の DB 二重防御（キー種別を跨いで org 生涯 1 回） |
| P1〜P5 の paid webhook 経路は marker を立てないため、当該経路のみで付与された org（招待参加 org が期間中に契約した場合など）は marker 無しで残る | 二重付与は `ticket_ledger_entries_signup_grant_unique` が DB レベルで阻止するため**金銭的影響は無い**（残高は不変。影響は flash 文言の分岐のみ）。当該経路は **P6 (b) の claim+grant ブロック追加**で閉じる |
| backfill 対象 org に `signup_grant:%` が複数行あると `min(granted_at)` が曖昧 | `2026_07_13_180622` が「重複あれば fail-closed」で既に入っており、本番に重複行は存在し得ない |
| `PlanPriceService` が P1 時点で呼び出し元なし（dead code） | 移植方針上は許容（P2 / 価格改定運用で使用）。`replaceCurrent` の単体テストで生存を確保。`?string $lookupKey` を落とすと `SyncStripePrices.php:78-87` が current 行を見失うため引数追加は必須 |
| `free` プラン行と `personal` が並存し、`ManualTestSeeder`（全 Plan に 1 org 生成）由来の組織が 2 つ増える | 手動テスト用途のみで本番影響なし。`SeededFreePlanBillingAccessTest` の対象非決定性は `code='free'` 固定で解消 |
| partial unique index は pgsql / sqlite 前提（MySQL 非対応） | 既存 `2026_07_13_180622` が同じ前提を driver チェック + fail-closed で明示済み。本番 / CI とも該当ドライバのみ |
| `starter` を `is_active=false` のまま放置すると再公開が漏れる（**`personal` は P3 で公開済み**） | **P8b の変更一覧に「Starter 再公開 data migration（`starter` を `is_active=true` へ）+ 残余検証 + `/pricing` 露出テスト」を含める**ことで受け渡しを固定する |

---

### P2 サブスク層: SubscriptionService / Gateway 置換と EffectivePlan への判定集約

前提: P1 で `PlanCode` enum / `PersonalPlanService`(`FREE_PLAN_CODE='personal'`) / `organizations.{free_plan_code, free_plan_activated_at, personal_declared_at, personal_declared_by_user_id, signup_tickets_granted_at}` + partial unique index が入っている。

**DoD**: P2 は**判定の集約と層の入れ替えのみ**で、ゲートの結論は現行 `BillingAccess::hasActiveAccess`（`plan_code === null` → 許可 / 非 null → `stripe_status ∈ {active,trialing}` のみ許可）と**同値**。migration ゼロ・route 変更ゼロ。支払い不健全（past_due / paused / 行不在）は解決順 2 の entitlement が `denied` を返すことで**現行どおり遮断**される。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| ファイル (AI-CUE) | 何をするか | 移植元 (aigenba) |
|---|---|---|
| `app/DataTransferObjects/Billing/EffectivePlan.php` (新規) | `abstract readonly class EffectivePlan`。`kind()` / `grantsAccess()` / `planCode()` / `deniedReason()` / `toArray()` を定義する**唯一の判定型**。生成経路は `SubscriptionService::effectivePlan()` のみ | 対応クラス無し（aigenba は `OnboardingBillingState` + `SubscriptionEntitlementDto` の 2 段。AI-CUE には subscription checkout session テーブルが無く Pending/ExpiredCheckout を表現できないため 2 段は移植対象が存在しない） |
| `app/DataTransferObjects/Billing/PaidSubscriptionPlan.php` (新規) | variant: 解決順 1（active/trialing sub あり）と解決順 2（`plan_code` 非 null）の両方が落ちる先。`SubscriptionEntitlementDto` を内包し `grantsAccess() = entitlement->entitled`。**`planCode` は nullable** | `BillingAccess::state()` の `Subscribed` 分岐 (`/tmp/aigenba/app/Services/Billing/BillingAccess.php:28-40`) |
| `app/DataTransferObjects/Billing/ActivatedPersonalPlan.php` (新規) | variant: `free_plan_code='personal'` かつ declarer あり。`planCode='personal'` / 常に許可 | 同 `ActiveFreePlan` 分岐 (`BillingAccess.php:42-49`) |
| `app/DataTransferObjects/Billing/GrandfatheredLegacyFreePlan.php` (新規) | variant: `free_plan_code='personal'` かつ declarer なし（= P4 backfill 済の既存 org）。`planCode='personal'` / 常に許可 | 概念設計「grandfathering の定義」(declarer-less) |
| `app/DataTransferObjects/Billing/NoPlan.php` (新規) | variant: 上記いずれにも当たらない未契約。`planCode=null`。**P2 は `grantsAccess()=true`（現行同値）。P4 の変更はここを false にする 1 点のみ** | — |
| `app/Enums/Billing/EffectivePlanKind.php` (新規) | `paid_subscription` / `activated_personal` / `grandfathered_legacy_free` / `no_plan`。PHP 側は型（variant）で分離し、境界（Inertia props / JSON）でのみ tag 文字列に落とす | — |
| `app/Enums/Billing/SubscriptionState.php` (新規) | `Active` / `PastDue` / `Paused` / `Inactive` + `fromSubscription()` + `grantsAccess()`。`ScheduledForUpgrade` / `UpgradeRecovery` は `pending_plan_code` / `upgrade_recovery_required` 列が AI-CUE の `subscriptions` に無い（`2026_06_11_091200_create_subscriptions_table.php`）ため移植しない。`grantsAccess()` は **`Active` のみ true**（`PastDue`=false は現行遮断の維持。aigenba の PastDue=true は `has_payment_method` 列前提のため採らない） | `/tmp/aigenba/app/Enums/Billing/SubscriptionState.php` |
| `app/Enums/Billing/EntitlementDeniedReason.php` (新規) | `NoActiveSubscription` / `Paused` の 2 case。`TrialEndedWithoutPaymentMethod` は `subscriptions.has_payment_method` が無いため移植しない | `/tmp/aigenba/app/Enums/Billing/EntitlementDeniedReason.php` |
| `app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php` (新規) | `entitled` / `state` / `reason` + `granted()` / `denied()` / `toArray()`（`EntitlementShape`）を verbatim 移植 | `/tmp/aigenba/app/DataTransferObjects/Billing/SubscriptionEntitlementDto.php` |
| `app/Services/Billing/SubscriptionSnapshot.php` (新規) | Stripe subscription の値オブジェクト。AI-CUE の `subscriptions` に対応列が無い `currentPeriodStart` / `seatItemQuantity` は持たない（列を足すのは P2 の非スコープ。**現行の同期挙動と同値**） | `/tmp/aigenba/app/Services/Billing/SubscriptionSnapshot.php` |
| `app/Services/Billing/SubscriptionService.php` (新規) | サブスク層の中枢。`effectivePlan()` / `deriveEntitlement()` / `applySubscriptionSnapshot()` / `startCheckout()` / `createPortalSession()`。Stripe I/O は Gateway 経由のみ | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:82-370`（deriveEntitlement / assertPriceSynced / applySubscriptionSnapshot）, `:1095`（createPortalSession） |
| `app/Services/Billing/BillingAccess.php` (改修) | 中身を `return $this->subscriptions->effectivePlan($org)->grantsAccess();` に差し替え。`GRANTING_STATUSES` 定数と `plan_code === null` 分岐を撤去。docblock の「plan_code null = fallback free」記述を EffectivePlan 参照へ更新（`BillingAccess` が課金判定の単一窓口である契約は不変） | `/tmp/aigenba/app/Services/Billing/BillingAccess.php:26-30`（`state($org)->grantsAccess()` 委譲の形） |
| `app/Services/Billing/Contracts/StripeGatewayInterface.php` (新規。旧 `SubscriptionCheckoutGateway.php` を置換) | namespace と命名のみ aigenba 形へ寄せ、**メソッドは現行 2 本 + `syncCustomerDetails()` の 3 本に限定**（`createSubscriptionCheckout` / `createPortalSession` / `syncCustomerDetails`）。戻り値は AI-CUE の `ExternalBillingRedirect` を維持（aigenba の `array{id,url}` は DTO 返却規約に反する）。**aigenba の巨大単一 `StripeGatewayInterface`（seat / schedule / auto-recharge を含む 30+ メソッド）へは寄せない。チケット系 Gateway 4 分割の境界も維持する** | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php`（命名のみ） |
| `app/Services/Billing/CashierStripeGateway.php` (新規。旧 `CashierSubscriptionCheckoutGateway.php` を rename) | 実装本体は現行のまま（`newSubscription('default',…)->checkout()` / `billingPortalUrl(…, PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')))`）+ `syncCustomerDetails()` に `$org->syncStripeCustomerDetails()` を追加。`portalRedirect` → `createPortalSession` へ改名 | `/tmp/aigenba/app/Services/Billing/CashierStripeGateway.php` |
| `app/Services/Billing/Fakes/FakeStripeGateway.php` (旧 `Fakes/FakeSubscriptionCheckoutGateway.php` を rename) | interface 変更に追随（`FakeExternalUrl::neutralReturn` の中立帰還 URL 契約は不変）。`syncCustomerDetails()` は **no-op**（fake 環境が実 Stripe を叩かない規約の維持） | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php:204,211` |
| `app/Services/Billing/BillingCustomerSynchronizer.php` (新規) | `dispatchFor()` を verbatim 移植（`stripe_id === null` は no-op / `afterCommit()` / transaction 内から呼ぶ契約を docblock 込みで移植） | `/tmp/aigenba/app/Services/Billing/BillingCustomerSynchronizer.php` |
| `app/Jobs/Billing/SyncBillingCustomerDetails.php` (新規) | `handle(StripeGatewayInterface $gateway)` → `$gateway->syncCustomerDetails($org)`。Cashier 標準 job を使わない理由（billable を trait 型で受けるため PHPStan level 10 で不一致）は移植元コメントごと持ち込む | `/tmp/aigenba/app/Jobs/Billing/SyncBillingCustomerDetails.php` |
| `app/Actions/Organizations/RenameOrganizationAction.php` (新規) | `OrganizationController::update`（`app/Http/Controllers/Organizations/OrganizationController.php:98-108`）の内部を抽出し、`DB::transaction` 内で `isDirty('name')` のときだけ `BillingCustomerSynchronizer::dispatchFor()`。**配線するのは rename 経路のみ**（aigenba の `UpdateBillingContactAction` 経路は請求先列・更新 UI が AI-CUE に存在しないため移植しない）。laratrust team の rename も AI-CUE に無いため移植しない | `/tmp/aigenba/app/Actions/Organizations/RenameOrganizationAction.php` |
| `app/Services/Billing/BillingPermissionService.php` (新規) | `grant` / `revoke` / `hasDirectPermission` / `getDirectManageBillingMap`。AI-CUE の同型先例 `app/Services/ApiKey/ApiKeyPermissionService.php`（`ensureTeamId` / `ensureMembership` / `permission_user` 一括引き）の構造に合わせる。permission 名は AI-CUE 規約（kebab）で `manage-billing`（`public const PERMISSION_MANAGE_BILLING`。AI-CUE に `BillingPermission` enum は無く、`ApiKeyPermissionService` と同じ const 方式）。**`canEdit` / `canEditWithKnownRoles`（ロール階層マトリクス）は付与 UI 専用のため移植しない**（`OrganizationRole` に `level()` が無く、追加は付与 UI 別 TODO の責務） | `/tmp/aigenba/app/Services/Billing/BillingPermissionService.php` |
| `database/seeders/PermissionSeeder.php` (改修) | `permissions()` に `['name' => BillingPermissionService::PERMISSION_MANAGE_BILLING, 'display_name' => '請求・プラン管理']` を追加（`manage-api-keys` の隣。flat 付与モデルのため `RolePermissionSeeder` には登録しない） | — |
| `app/Policies/OrganizationPolicy.php:37 manageBilling` (改修) | `manageApiKeys`（同ファイル L48-60）と同型に: role null → false / `canManage()` → true / それ以外は `BillingPermissionService::hasDirectPermission()` を **OR 参照**。**付与 route / UI は P2 に含めない**（別 TODO）ため直接付与行は 0 件 = 認可の結論は現行と同一 | `/tmp/aigenba/app/Services/Billing/BillingPermissionService.php` の Policy 参照形 |
| `app/Http/Controllers/Billing/BillingController.php` (改修) | `SubscriptionCheckoutGateway` 直注入をやめ `SubscriptionService` へ委譲。`index` の `currentPlanCode`（`organizations.plan_code` の raw 読み）を `effectivePlan` prop（DTO `toArray()`）へ。`checkout` の price 不在分岐は `SubscriptionService::startCheckout()` が投げる `StripePriceNotSyncedException` を catch し、**現行と同一文言**の `back()->with('error', '選択したプランは現在お申し込みいただけません。')` を返す。`portal` は `createPortalSession()` へ | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php`（Service 委譲の層構成） |
| `app/Exceptions/Billing/StripePriceNotSyncedException.php` (新規) | `SubscriptionService::assertPriceSynced()` が投げる。`userMessage()` を持ち Controller が flash に使う（500 にしない） | `/tmp/aigenba/app/Exceptions/Billing/StripePriceNotSyncedException.php` |
| `app/Services/Billing/StripeWebhookProcessor.php` (改修) | `syncPlanCode` / `clearPlanCode` / `syncSubscriptionPeriod`（L203-329）の**書込ロジックを `SubscriptionService::applySubscriptionSnapshot()` へ移設**。Processor の責務は payload → `SubscriptionSnapshot` の写像 + 組織解決（`resolveOrganization`）に縮む。反映条件（active/trialing のみ・未知 Price は受理のみ・invoice/ticket 系の分岐）は不変 | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:204-370` |
| `app/Providers/AppServiceProvider.php:110` / `app/Providers/FakeExternalsServiceProvider.php:80` (改修) | bind を `StripeGatewayInterface → CashierStripeGateway` / fake は `FakeStripeGateway` へ更新 | `/tmp/aigenba/app/Providers/AppServiceProvider.php:103`, `DuskFakesServiceProvider.php:60` |

**非スコープ（P2 で持ち込まない）**: `OnboardingBillingState`（**導入しない**。判定源は `EffectivePlan` 単一）/ `BillingStatusDto`・`getStatus()`（表示用 DTO であり判定には不要。呼び出し側 UI は P8b 所管のため P8b で導入する = dead code を作らない）/ `startSignupCheckout` / `changePlan` / `upgradeNow` / schedule lifecycle / seat 系 / `grantSignupInitialTickets`（P6）/ `manage-billing` の付与 route・UI（別 TODO）/ 請求先情報（billing contact）系（P9）/ チケット系 Gateway の統合。

#### 波及変更

- **TypeScript 型定義**
  - `resources/js/types/billing.ts`（既存。PHP DTO の `@phpstan-type` shape と exact 対で保守する規約）: `EffectivePlanKind` union（`"paid_subscription" | "activated_personal" | "grandfathered_legacy_free" | "no_plan"`）と `EffectivePlanShape { readonly kind: EffectivePlanKind; readonly planCode: string | null; readonly grantsAccess: boolean; readonly deniedReason: string | null }` を追加。
  - `resources/js/pages/Billing/Index.svelte`: `interface Props` の `currentPlanCode: string | null` を `effectivePlan: EffectivePlanShape` へ。`currentPlan` の `$derived` は `plans.find((plan) => plan.code === effectivePlan.planCode) ?? null` に変更。L149 / L157 の `plan.code === currentPlanCode` 比較も `effectivePlan.planCode` へ。**描画・分岐の追加はしない**（kind による出し分けは P8b）。
  - `resources/js/types/dashboard.ts` の `BillingSummary.has_billing_access`: 変更なし（`BillingSummaryData` の形は不変）。
- **DTO / JsonResource**: 新規 = `EffectivePlan`（+ 4 variant: `PaidSubscriptionPlan` / `ActivatedPersonalPlan` / `GrandfatheredLegacyFreePlan` / `NoPlan`）/ `SubscriptionEntitlementDto` / `SubscriptionSnapshot`。既存 `ExternalBillingRedirect` は据置（Gateway 戻り値契約として維持）。`BillingSummaryData` / `PurchaseTicketsPageDto` は据置（`ticketAttemptToken` を含むチケット決済の冪等性契約は P2 で一切触らない）。JsonResource の新設なし。
- **Inertia props**: `Billing/Index` の `currentPlanCode` → `effectivePlan`（DTO `toArray()` 経由）。`plans` / `ticketBalance` / `canManageBilling` は不変。他ページの props 変更なし。
- **テストファイル（更新）**: `tests/Feature/Billing/BillingPageTest.php`（props 名 + fake bind 名）/ `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`（期待不変）/ `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`（期待不変）/ `tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php`・`tests/Feature/Billing/WebhookIdempotencyTest.php`（期待不変）/ `tests/Feature/Billing/PortalConfigurationTest.php`（期待不変 + 1 ケース追加）/ `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（bind 名の rename 追随）。
- **Factory**: `database/factories/OrganizationFactory.php` に P1 列を使う state（`activatedPersonal()` / `grandfatheredFree()` / `paid(string $planCode)`）を追加（テストデータ手組み禁止のため）。

#### 主要な契約

```php
// App\DataTransferObjects\Billing
/**
 * @phpstan-type EffectivePlanShape array{kind: string, planCode: string|null, grantsAccess: bool, deniedReason: string|null}
 */
abstract readonly class EffectivePlan {
    abstract public function kind(): EffectivePlanKind;
    abstract public function grantsAccess(): bool;
    /** quota / プラン解決キー。null は config('quota.fallback_plan') 適用 */
    abstract public function planCode(): ?string;
    public function deniedReason(): ?EntitlementDeniedReason { return null; }
    /** @return EffectivePlanShape */
    public function toArray(): array;
}
final readonly class PaidSubscriptionPlan extends EffectivePlan {
    // planCode は nullable: active sub の price から plan code を解決できない場合に null（後述）
    public function __construct(public ?string $planCode, public SubscriptionEntitlementDto $entitlement) {}
    public function grantsAccess(): bool { return $this->entitlement->entitled; }
}
final readonly class ActivatedPersonalPlan extends EffectivePlan {   // planCode='personal' / grantsAccess=true
    public function __construct(public int $declaredByUserId, public CarbonImmutable $declaredAt) {}
}
final readonly class GrandfatheredLegacyFreePlan extends EffectivePlan {} // planCode='personal' / grantsAccess=true
final readonly class NoPlan extends EffectivePlan {}                      // planCode=null / P2: true, P4: false

final class SubscriptionService {
    public function __construct(private readonly StripeGatewayInterface $gateway) {}
    public function effectivePlan(Organization $org): EffectivePlan;   // 判定の唯一の生成経路
    public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto;
    public function applySubscriptionSnapshot(Organization $org, SubscriptionSnapshot $snap, bool $terminated = false): void;
    public function startCheckout(Organization $org, Plan $plan, string $successUrl, string $cancelUrl): ExternalBillingRedirect;
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect;
}
final class BillingAccess {                                            // 判定は委譲のみ
    public function __construct(private readonly SubscriptionService $subscriptions) {}
    public function hasActiveAccess(Organization $org): bool { return $this->subscriptions->effectivePlan($org)->grantsAccess(); }
}

// App\Services\Billing\Contracts
interface StripeGatewayInterface {
    public function createSubscriptionCheckout(Organization $org, string $stripePriceId, string $successUrl, string $cancelUrl): ExternalBillingRedirect;
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect;
    public function syncCustomerDetails(Organization $org): void;
}
```

**`effectivePlan()` の解決順（唯一の判定契約。上から最初に一致したものを返す）**

| # | 条件 | variant | P2 grantsAccess | P4 grantsAccess |
|---|---|---|---|---|
| 1 | `subscription('default')` が **active/trialing**（`plan_code` の null 有無を**見ない** = webhook 同期ラグ組織を締め出さない） | `PaidSubscriptionPlan(planCode: 解決結果, entitlement: granted(Active))` | true | true |
| 2 | `organizations.plan_code !== null` | `PaidSubscriptionPlan(planCode: $org->plan_code, entitlement: deriveEntitlement(sub) / sub 不在なら denied(Inactive, NoActiveSubscription))` | entitlement 次第（**denied を含む = 支払い不健全は遮断**） | 同左（不変） |
| 3 | `free_plan_code === PersonalPlanService::FREE_PLAN_CODE` かつ `personal_declared_by_user_id !== null` | `ActivatedPersonalPlan` | true | true |
| 4 | `free_plan_code === PersonalPlanService::FREE_PLAN_CODE` かつ declarer なし（P4 backfill 済） | `GrandfatheredLegacyFreePlan` | true | true |
| 5 | それ以外 | `NoPlan` | **true** | **false**（P4 の変更はこの 1 点のみ） |

**現行同値の証明**: 現行は「`plan_code === null` → 許可 / 非 null → `stripe_status ∈ {active,trialing}` のみ許可」。新解決順では `plan_code` 非 null は 1（active/trialing = 許可）か 2（それ以外 = entitlement denied で不許可）に必ず落ち、`plan_code === null` は 1（許可）か 3/4/5（P2 は全て true）に落ちる。よって結論は全ケースで一致する。

**解決順 1 の plan code 解決と fail-open 禁止**: plan code は `subscriptions.stripe_price` → `plan_prices.stripe_price_id` → `plans.code` で解決する（`StripeWebhookProcessor::planByStripePriceId` と同一の引き方を `SubscriptionService` に集約）。**解決不能でも `GrandfatheredLegacyFreePlan` を返してはならない**（有償契約中の org が kind/quota 上 personal 扱いになり型の意味が壊れる）。この場合は **`PaidSubscriptionPlan(planCode: null, entitlement: granted(...))`** を返す（`grantsAccess=true` / quota は `config('quota.fallback_plan')` 適用）。**`free_plan_code` が `'personal'` でない組織を Grandfathered variant へ入れない**。
併せて **ログ・監視を必須**とする: `Log::warning('effective plan: active subscription price unresolved', ['organization_id' => …, 'subscription_stripe_id' => …, 'stripe_price' => …])` を出し、既存のログベース監視アラートへ接続済みであることを P2 の DoD に含める（price sync 漏れの恒久滞留を検知する唯一の経路）。

**`deriveEntitlement()`**: `SubscriptionState::fromSubscription($sub)` → `grantsAccess()` が false なら `denied($state, $state === Paused ? Paused : NoActiveSubscription)`、true（= `Active`）なら `granted($state)`。`fromSubscription()` は `paused` → `Paused` / `past_due` → `PastDue` / `active|trialing` → `Active` / その他（canceled・unpaid・incomplete・incomplete_expired）→ `Inactive`。**`past_due` は現行どおり遮断**（`PastDue::grantsAccess()=false`）。aigenba の「PM 登録済み past_due は継続」は `subscriptions.has_payment_method` 列を要するため P2 では採らず、別 TODO とする。

**`applySubscriptionSnapshot()`**: `SubscriptionSnapshot{stripeId, status, basePriceId, baseQuantity, currentPeriodEnd, trialEndsAt, endsAt}` を受け、単一 transaction 内で (a) `status ∈ {active,trialing}` かつ既知 Price のときのみ `organizations.plan_code` を同期（未知 Price は受理のみ = 現行の `syncPlanCode` と同値）、(b) `subscriptions` 行が存在すれば `current_period_end` を更新（行の作成は Cashier の WebhookController 責務 = 現行の `syncSubscriptionPeriod` と同値）、(c) `$terminated === true` のとき `organizations.plan_code = null`（現行 `clearPlanCode` と同値）。反映条件の判定は Processor ではなく Service に一元化する。

**DB 列 / index / ルート**: **P2 での追加・変更なし**（migration ゼロ。P1 の列を読むだけ）。ルートは `/billing`・`/billing/checkout`・`/billing/portal` のまま。`manage-billing` permission 行のみ `PermissionSeeder` に追加。

#### PHPStan 適合チェック

- `EffectivePlan` は abstract readonly + final variant。呼び出し側は `match(true)` ではなく `instanceof` narrowing で扱い、抽象宣言（`kind()` / `grantsAccess()` / `planCode()`）により実装漏れが静的に潰れる。`kind()` の enum 化で props 側の string も型付け。
- `Organization::subscription('default')` は Cashier 由来で `Subscription|null`（`Cashier::useSubscriptionModel` で `App\Models\Billing\Subscription` に差し替え済み）。`deriveEntitlement()` に渡す前に `$sub instanceof Subscription` で narrow する（aigenba `BillingAccess.php:31` と同型）。**`?->` で握り潰さない**（不在は明示的に `denied(Inactive, NoActiveSubscription)`）。
- `PaidSubscriptionPlan::$planCode` は `?string`。nullable を型で表明することで、解決不能ケースを PHPStan が呼び出し側に強制的に扱わせる（quota 側は `?? config()->string('quota.fallback_plan')`）。
- `personal_declared_by_user_id` / `personal_declared_at` は `?int` / `?CarbonImmutable`。variant 構築時に `Assert::integer` / `Assert::notNull` で narrow してから `ActivatedPersonalPlan` の非 null プロパティへ渡す（DTO 側は null を受けない = 型で variant 不変条件を保証）。
- `toArray()` は各 DTO に `@phpstan-type ...Shape` を宣言し `@return ...Shape` で固定（既存 `BillingSummaryData` / aigenba `SubscriptionEntitlementDto` と同じ様式）。Inertia props は DTO の `toArray()` のみを渡す（`response()->json()` 直書きなし = 禁止事項 4）。
- `getDirectManageBillingMap(Organization $org, array $userIds): array` は `@param list<int>` / `@return array<int, bool>`。`DB::table('permission_user')->pluck()` の `mixed` は `Assert::integerish()` 後に cast（`ApiKeyPermissionService::getDirectMap` と同一実装）。`ensureTeamId()` は `Assert::integer($org->laratrust_team_id)`（不変条件 #5: `laratrust_team_id` を常に明示）。
- `SubscriptionSnapshot` の日時は `?CarbonImmutable` に正規化してから渡す（webhook payload の `data_get` は `mixed` → 既存 `stringAt()` + 新設 `epochAt()` helper で narrow）。
- config 読みは `config()->string('quota.fallback_plan')` / `config('cashier.portal_configuration_id')` の typed accessor 経由を維持（`QuotaService` は P2 で触らない）。型を緩めた回避・baseline 化は行わない（禁止事項 2）。

#### テスト計画

**先に red を作るテスト**

1. `tests/Unit/Billing/EffectivePlanTest.php` — 4 variant の `kind()` / `grantsAccess()` / `planCode()` / `toArray()` 形状（dataset）。`PaidSubscriptionPlan(planCode: null)` が `grantsAccess=true` / `planCode=null` を返すこと。クラス不在で red。
2. `tests/Feature/Billing/EffectivePlanResolutionTest.php` — **解決順 5 段と現行同値表**を固定する回帰（Factory state から生成）:
   - `plan_code=null` + **active/trialing sub あり** → `PaidSubscriptionPlan` + `hasActiveAccess()=true`（同期ラグ組織を締め出さない）
   - **`plan_code` 非 null + sub 行不在 / `past_due` / `paused`** → `PaidSubscriptionPlan` + `entitled=false` + `deniedReason` 付き + `hasActiveAccess()=false`（**P2・P4 とも遮断**）
   - `plan_code='standard'` × `stripe_status ∈ {active,trialing,past_due,canceled,unpaid,incomplete,incomplete_expired,paused}` × 行不在 → `hasActiveAccess()` が現行 `GRANTING_STATUSES` の結論と一致
   - **`plan_code=null` + active sub（price 解決可）→ `PaidSubscriptionPlan` が P4 後もアクセス継続**（P4 の diff が `NoPlan` のみであることの担保）
   - `free_plan_code='personal'` + declarer あり → `ActivatedPersonalPlan` + true
   - `free_plan_code='personal'` + declarer なし → `GrandfatheredLegacyFreePlan` + true（P4 後も true）
   - `free_plan_code=null` + paid なし → `NoPlan` + **true（P2）**
   - active sub + **price 解決不能** → `PaidSubscriptionPlan(planCode: null)` + true + `Log::warning` が 1 回出る（`Log::spy()`）。`GrandfatheredLegacyFreePlan` に**ならない**ことを明示アサート
3. `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` — webhook payload → `SubscriptionSnapshot` → `applySubscriptionSnapshot` で `organizations.plan_code` / `subscriptions.current_period_end` が現行と同一に落ちる。`deleted`（terminated=true）で `plan_code=null`。未知 Price は無変更。非 active/trialing status は無変更。
4. `tests/Architecture/EffectivePlanSingleSourceTest.php` — `app/` 配下で `plan_code` / `free_plan_code` / `personal_declared_*` を**直接読む**のは allowlist（`SubscriptionService`=判定生成 / `StripeWebhookProcessor`=writer 委譲 / `QuotaService`=map lookup / `Organization` model / Filament の admin 表示 / `PersonalPlanService`）のみ。新規の raw 分岐を CI で構造的に禁止する。
5. `tests/Architecture/BillingSyncDispatchInvariantTest.php` — `SyncBillingCustomerDetails::dispatch` の呼び出し元は `BillingCustomerSynchronizer` のみ（aigenba IV-2 の移植）。

**既存テストの更新（削除しない）**

- `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php` / `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`: **期待を 1 行も変えずに green を維持**（P2 の DoD = 挙動不変の証明）。
- `tests/Feature/Billing/BillingPageTest.php:33`: `->where('currentPlanCode', null)` → `->where('effectivePlan.planCode', null)` + `->where('effectivePlan.kind', 'no_plan')` + `->where('effectivePlan.grantsAccess', true)`。fake gateway の型参照を `FakeStripeGateway` へ更新（中立帰還 URL の期待は不変）。
- `tests/Feature/Providers/FakeExternalsServiceProviderTest.php:35`: `SubscriptionCheckoutGateway` / `CashierSubscriptionCheckoutGateway` / `FakeSubscriptionCheckoutGateway` の参照を `Contracts\StripeGatewayInterface` / `CashierStripeGateway` / `FakeStripeGateway` へ。
- `tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php` / `tests/Feature/Billing/WebhookIdempotencyTest.php`: 期待不変（内部が Service 経由になっても event_id 冪等・plan_code 同期条件が保たれること）。
- `tests/Feature/Billing/PortalConfigurationTest.php`: `PortalConfigurationSpec` の期待は不変。Service 委譲後も `sessionOptions(config('cashier.portal_configuration_id'))` が Gateway に渡ることを 1 ケース追加。

**新規（機能追加分）**

- `tests/Feature/Billing/BillingPermissionServiceTest.php`: grant/revoke → `hasDirectPermission` の反映、非メンバーは `DomainException`（grant）/ false（has）、`getDirectManageBillingMap` が 1 クエリで返る（N+1 なし）。加えて **Policy 回帰**: 直接付与ゼロの状態で `manageBilling` の結論が現行（owner/admin のみ）と同一 / 直接付与された member は `/billing/checkout` が 403 にならない / 非メンバーは付与行が残存しても false。
- `tests/Feature/Organizations/OrganizationRenameStripeSyncTest.php`: `Queue::fake()` で (a) name 変更時のみ `SyncBillingCustomerDetails` が dispatch、(b) 同名 save では dispatch なし、(c) `stripe_id === null` は no-op、(d) transaction rollback 時に発火しない（`afterCommit`）。
- `tests/Unit/Billing/FakeStripeGatewayTest.php`: `syncCustomerDetails()` が no-op（実 Stripe を叩かない）+ checkout / portal の中立帰還 URL 契約（既存 `FakeTicketCheckoutGatewayTest` と同型）。

#### リスク

| リスク | 緩和 |
|---|---|
| **判定の集約で結論がずれる**（`plan_code` 非 null + subscription 行不在の fail-closed、`past_due`） | 解決順テスト（2）で現行 `GRANTING_STATUSES` を dataset として固定。`SeededFreePlanBillingAccessTest` / `RequireActiveSubscriptionMiddlewareTest` を**無改変で green** に保つことを DoD にする |
| **active sub の price から plan code を解決できず quota が意図せず fallback になる** | `PaidSubscriptionPlan(planCode: null)` として `grantsAccess=true` を維持（締め出さない）+ `Log::warning` + 監視アラート接続を DoD 化。fallback quota は旧 free と同値のため実効 limits は現行同値。ログ発火をテストで固定 |
| **P4 の diff が 1 点に収まらなくなる**（variant の畳み込み） | 4 variant を P2 時点で分離し、`NoPlan` / `GrandfatheredLegacyFreePlan` の両ケースを `EffectivePlanResolutionTest` に P2 から入れる。P4 は `NoPlan::grantsAccess()` の 1 行変更のみで済むことをテストの diff で確認する |
| `StripeWebhookProcessor` からの書込移設で webhook の順序逆転耐性が退行 | 既存 `WebhookEventSubscriptionInvariantTest` / `WebhookIdempotencyTest` を無改変で維持。`applySubscriptionSnapshot` に現行の反映条件（active/trialing のみ・未知 Price は受理のみ・行不在時は period 更新のみ skip）をそのまま持ち込む |
| **rename 時に Stripe API 呼び出しが増える**（現行は customer 同期なし）= 外部副作用の新規発生 | job 化 + `stripe_id === null` no-op + `isDirty('name')` 限定 + fake 環境は `FakeStripeGateway::syncCustomerDetails()` で no-op。`OrganizationRenameStripeSyncTest` と `BillingSyncDispatchInvariantTest` で固定 |
| `manageBilling` への直接付与 OR 追加で認可が緩む | 付与経路（route / UI / Action）を P2 に含めない = 直接付与行は生成されない。Policy 回帰テストで「付与ゼロなら結論は現行と同一」を固定。非メンバーは role null で早期 false（`manageApiKeys` と同型） |
| Gateway rename で fake bind 漏れ（bughunt 環境が実 Stripe を叩く） | `AppServiceProvider` / `FakeExternalsServiceProvider` の bind を同一 PR で更新し、`FakeExternalsServiceProviderTest` + `BillingPageTest` の fake 経由 happy path 2 本（checkout / portal）が中立帰還 URL を返すことで検出 |
| `EffectivePlan` の props 露出で UI が先走る（UI parity は P8b） | P2 は prop 名の差し替えのみ。`Billing/Index.svelte` の描画・分岐は追加しない（kind による出し分け・PlanCard 構成は P8b）。DS token / T071 primitive 準拠は現行のまま維持 |

---

### P3 Onboarding 最小導線（ゲート反転より前に導線を実在させる = F-07 再発防止の条件 A）

前提: P1（`PlanCode`(Personal/Starter/Standard) / `plans.is_active` / `organizations.{free_plan_code, free_plan_activated_at, personal_declared_at, personal_declared_by_user_id, signup_tickets_granted_at}` + partial unique index / `PersonalPlanService::activate()` **完成済み** / `PersonalPlanEligibilityDto` / `PersonalPlanNotEligibleException` / `PlanSeeder` の personal・starter）と P2（`SubscriptionService::effectivePlan()` = 唯一の判定源。4 variant + 5 段の解決順）がマージ済み。

**DoD**: **導線を足すだけ**。`BillingAccess` / `RequireActiveSubscription` は一切触らない（ゲート反転は P4 の責務）。Personal 有効化は
P1 の `PersonalPlanService::activate()` を**呼ぶだけ**（付与ロジックを再実装しない = 二重付与源を作らない）。

**Personal の公開（D10。P4 の前提条件）**: `database/migrations/2026_07_17_000200_activate_personal_plan.php`（data migration）で
**`plans` の `code='personal'` を `is_active=true`** にする。Personal の「購入導線」は `activate-personal` そのものであり本フェーズで揃うため、
ここで公開して**初めて P4 のゲート反転時に無料導線が実在する**（**Personal が非公開のまま反転すると未契約者が Standard しか選べず
F-07 の変種が再発する**）。**Starter は有償で checkout UI が要るため `is_active=false` のまま**（再公開は P8b）。
- 検証: migration 末尾で `plans.code='personal' AND is_active=true` が 1 件であることをアサート。
- rollback: `down()` で `is_active=false` へ戻す（**P4 より前のフェーズなので単独 revert が安全**）。
- テスト: `PersonalPlanPublishedTest` — `/pricing` に Personal が出る / Starter は出ない / `onboarding.checkout` の
  `personalEligibility` が `null` にならない。

#### 変更箇所

| AI-CUE（新規/変更） | 移植元 aigenba | 何をするか |
|---|---|---|
| `routes/web.php`（auth group 内、`require-active-subscription` group の**外**。既存 `organizations.onboarding.{mcp,cli}`(L293-296) の直後 = `/billing` 群の隣） | `/tmp/aigenba/routes/web.php:442-449` | **D6/D21 適用（route parameter を持たない current-org スコープ）**: `GET /onboarding/checkout` → `onboarding.checkout` / `POST /onboarding/activate-personal`（`->middleware('throttle:10,1')`）→ `onboarding.activate-personal` / `GET /billing-required` → `onboarding.billing-required`。組織は `ResolvesCurrentOrganization` で解決し `{organization:slug}` バインドは使わない（既存 `billing.index` / `billing.checkout` と同一の組織解決 = 対称）。route name は既存 `organizations.onboarding.{mcp,cli}`（CLI/MCP セットアップ = 別責務）と非衝突 |
| `app/Http/Concerns/ResolvesCurrentOrganization.php` | — | **additive**: `resolveMemberCurrentOrganization(Request): Organization` を追加。`resolveCurrentOrganization()`（current org 不在 → 404）に続けて `abort_if($user->organizationRole($organization) === null, 404)` を行う（`current_organization_id` が退会後も残存する不整合を**認可より前に 404** = セキュリティ不変条件 #2。403 で存在を漏らさない）。既存メソッドは無改変 = 既存 Controller の挙動は不変 |
| `app/Http/Controllers/Onboarding/OnboardingController.php`（新規） | 同名 | プラン選択 + Personal 自己申告画面を render。`show(Request): Response|RedirectResponse` |
| `app/Http/Controllers/Onboarding/ActivatePersonalController.php`（新規） | 同名 | `__invoke(ActivatePersonalRequest): RedirectResponse`。P1 の `PersonalPlanService::activate()` を呼ぶだけ。`PersonalPlanNotEligibleException` を **422** に変換 |
| `app/Http/Controllers/Onboarding/BillingRequiredController.php`（新規） | 同名 | 未申告 + `manageBilling` なし member 向け説明画面。`show(Request): Response|RedirectResponse` |
| `app/Http/Requests/Onboarding/ActivatePersonalRequest.php`（新規） | 同名 | `declaration`（`required` + `accepted`）のみ。`funding_choice` / `consent_version` は移植しない（P7 / P8a の additive 追加）。`ProhibitsProtectedKeys` を配線し `protectedKeyMissingRules()` を merge（`FormRequestProhibitedKeyTest` 対応）。認可は Controller の `Gate::authorize` |
| `app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php`（新規） | 同名 | Checkout の pageData（下記 shape。aigenba のフィールド名を維持し後続フェーズは additive に足すだけにする） |
| `app/DataTransferObjects/Onboarding/BillingRequiredDto.php`（新規） | 同名 | `ownerName` / `ownerEmail` / `contactUrl`（verbatim） |
| `app/DataTransferObjects/Billing/PlanDto.php`（新規。AI-CUE に PlanDto は不在） | 同名 | `fromModel(Plan)`。**AI-CUE の実列にのみマップ**する（下記） |
| `app/Enums/Inquiry/InquirySource.php` | `InquirySource::Onboarding` | **additive**: `case Onboarding = 'onboarding';` + `label()` に「オンボーディング」を追加（`match` 網羅は case 追加で自動維持。`normalize()` は allowlist に自動追随） |
| `lang/ja/validation.php` | — | `attributes` に `'declaration' => '個人利用の確認'` を追加（`ValidationAttributeCoverageTest` は全 rule キーの登録を要求。`plan_code` は L211 に既存） |
| `resources/js/pages/Onboarding/Checkout.svelte`（新規） | 同名(643 行) | **P3 部分のみ移植**（plan grid + Personal 自己申告 step）。funding 2 択 / 同意 UI / intended バッジ / 折りたたみ確認は移植しない（P7・P8a） |
| `resources/js/pages/Onboarding/BillingRequired.svelte`（新規） | 同名(53 行) | Owner 連絡先 + 問い合わせ導線。403 ではなく専用ページで「行き先のない詰み」を回避する |
| `resources/js/types/onboarding.ts`（新規） | — | PHP `@phpstan-type` と exact 対（`types/billing.ts` の既存規約に従う） |

**名前空間の分離（aigenba と同一理由）**: 既存 `App\Http\Controllers\Organizations\OrganizationOnboardingController`（MCP/CLI 手順）と `resources/js/pages/Organizations/Onboarding/{Mcp,Cli}.svelte` は**触らない**。課金オンボーディングは `App\Http\Controllers\Onboarding\*` / `resources/js/pages/Onboarding/*` に分離する。

**移植時の adaptation（意味論は不変）**

- `ContactUrl::forSource(...)->url`（aigenba）→ **`ContactUrl::resolveForSource(InquirySource $source): string`**（AI-CUE の既存 API。`HomeController` / `PricingController` と同じ作法）。
- `TicketService::signupGrantTicketCount()`（aigenba）→ **`TicketPricingService::signupGrantTickets(): int`**（AI-CUE の既存 API。`config('billing.signup_grant_tickets')` の直読みを増やさない）。
- `Role::OrganizationOwner` → `App\Enums\OrganizationRole::Owner`。Owner 解決は `Organization::routeNotificationForMail()`(`app/Models/Organization.php:164-172`) と**同一パターン**（`users()->get()->first(fn (User $u) => $u->organizationRole($this) === OrganizationRole::Owner)`）。
- `GuestLayout`（aigenba）→ **`AppLayout` + T071 primitive**（`PageContainer` / `PageHeader` / `PageContent`）。両ページとも auth group 内のログイン後ページであり、AI-CUE の外枠規約（arch: page-shell-structure）が parity に優先する（原則 1）。
- `OrganizationDto`（aigenba）は AI-CUE に不在 → 新設しない。organization props は既存 `OrganizationOnboardingController::organizationProps()` と同形（`{id, name, slug}`）。
- `Inertia::location()` は AI-CUE では **Stripe への full page redirect 専用**（`BillingController`）。内部遷移は素の `RedirectResponse` を使う。

#### 波及変更

- **TypeScript 型定義**: `resources/js/types/onboarding.ts` 新規（`OnboardingCheckoutShape` / `BillingRequiredShape` / `PlanShape` / `PersonalPlanEligibilityShape` / `type PlanCode = 'personal' | 'starter' | 'standard'`）。`types/billing.ts` / `types/marketing.ts` は変更なし。
- **DTO**: 新規 `OnboardingCheckoutDto` / `BillingRequiredDto` / `PlanDto`。P1 産出の `PersonalPlanEligibilityDto` を再利用（新規作成しない）。**JsonResource は使わない**（Inertia ページのため DTO→`toArray()`。`response()->json()` 直書きなし = 禁止事項 #4 準拠）。
- **Inertia props**: `Onboarding/Checkout` = `{ organization: {id,name,slug}, pageData: OnboardingCheckoutShape }` / `Onboarding/BillingRequired` = `{ organization, pageData: BillingRequiredShape }`。既存ページの props 変更は**なし**。
- **Enum / lang**: `InquirySource::Onboarding` 追加（公開フォームの `source` allowlist が 1 件増える。既存 case の意味は不変）/ `lang/ja/validation.php` の `attributes.declaration` 追加。
- **Factory**: `database/factories/OrganizationFactory.php` の `freePersonal()` / `signupGranted()` state（P1 産出）を再利用。Plan は `PlanSeeder` が真実源。P1 で `database/factories/Billing/PlanFactory.php` が作られていない場合は P3 で新設する（テストデータ手組み禁止）。
- **テストファイル（新規）**: `tests/Feature/Onboarding/OnboardingCheckoutTest.php` / `tests/Feature/Onboarding/ActivatePersonalTest.php` / `tests/Feature/Onboarding/BillingRequiredTest.php` / `tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php` / `tests/js/pages/OnboardingCheckout.test.ts` / `tests/js/pages/OnboardingBillingRequired.test.ts`。
- **テストファイル（更新）**: **なし**（`RequireActiveSubscriptionMiddlewareTest` / `SeededFreePlanBillingAccessTest` は P4 の更新対象。P3 では期待が変わらない）。arch テストは inventory 追加なしで green: `NestedRouteIdorDefenseTest`（route param 2 個以上が対象 / 本 route は **0 個**）/ `OrganizationRouteParamWebOnlyInvariantTest`（`{organization}` param を持たない）/ `page-shell-structure`（AppLayout + 3 primitive を使用するため allowlist 不要）/ `FormRequestProhibitedKeyTest`（`ProhibitsProtectedKeys` 配線）/ `ValidationAttributeCoverageTest`（`declaration` 属性追加）。

#### 主要な契約

```php
// App\Http\Concerns\ResolvesCurrentOrganization （additive。既存メソッドは無改変）
private function resolveMemberCurrentOrganization(Request $request): Organization;
//  current org 不在 → 404 / current org がユーザー非所属 → 404（いずれも認可より前 = 不変条件 #2）

// App\Http\Controllers\Onboarding\OnboardingController  （Request のみ。Organization 引数なし）
public function show(Request $request): Response|RedirectResponse
//  1. $org = $this->resolveMemberCurrentOrganization($request)      ← 404 / 404
//  2. Gate::authorize('view', $org)                                  ← 二重防御（1 で担保済み）
//  3. $this->subscriptions->effectivePlan($org)->isDeclared()        → redirect route('billing.index')
//  4. ! Gate::allows('manageBilling', $org)                          → redirect route('onboarding.billing-required')
//  5. Inertia::render('Onboarding/Checkout', ['organization' => …, 'pageData' => $dto->toArray()])

// App\Http\Controllers\Onboarding\ActivatePersonalController        （Request のみ。Organization 引数なし）
public function __invoke(ActivatePersonalRequest $request): RedirectResponse
//  1. $org = $this->resolveMemberCurrentOrganization($request)      ← 404 / 404
//  2. Gate::authorize('manageBilling', $org)                        ← 403
//  3. abort_unless($this->personalPlanIsActive(), 404)              ← 非公開プランは存在しないものとして扱う
//  4. try { $result = $this->personalPlan->activate($org, $user); }  ← P1 完成済み。P3 は呼ぶだけ
//     catch (PersonalPlanNotEligibleException $e) {
//         throw ValidationException::withMessages(['plan_code' => $e->userMessage()]);   // 422（500 にしない）
//     }
//  5. redirect()->route('dashboard')->with('success', $message)      ← 着地は dashboard 固定（禁止事項 #7: intended() を使わない）

// App\Http\Controllers\Onboarding\BillingRequiredController         （Request のみ。Organization 引数なし）
public function show(Request $request): Response|RedirectResponse
//  1. $org = $this->resolveMemberCurrentOrganization($request)      ← 404 / 404
//  2. Gate::authorize('view', $org)
//  3. isDeclared()                → redirect route('dashboard')          ← 離脱ガード（見せる理由がない）
//  4. Gate::allows('manageBilling', $org) → redirect route('onboarding.checkout')  ← 自分で手続き可
//  5. Inertia::render('Onboarding/BillingRequired', …)
```

**入口ガード = 明示的プラン申告の有無（P3 の中核設計判断）**: aigenba は入口ガードに entitlement（`BillingAccess::hasActiveAccess()` / `state()->grantsAccess()`）を使うが、AI-CUE で機械移植すると **P3 の導線が到達不能**になる。現行 `BillingAccess`（`plan_code === null` → 許可）により **P3 時点の全未契約 org が `hasActiveAccess()=true`** となり、`show()` が常に `billing.index` へ redirect するためである（= 条件 A が実質未達のまま P4 に入る = F-07 再発）。aigenba では entitlement と「プラン申告済みか」が同値だが、AI-CUE の暗黙 free 期には両者が乖離する。
→ **P3 の入口ガードは `EffectivePlan::isDeclared()`（`paid あり || free_plan_code !== null`）で判定し、`BillingAccess` を読まない**。P4 で `NoPlan::grantsAccess()` が false へ反転した後、両者は同値へ収束する（= P4 後は自然に aigenba と一致する）。判定源は依然 `EffectivePlan` 単一（D18）であり、二重化は生じない。

```php
// App\DataTransferObjects\Billing\EffectivePlan （P2 の型への additive。判定源は EffectivePlan のまま）
/** 明示的なプラン申告済みか（paid subscription あり || free_plan_code !== null）。
 *  P4 のゲート反転後は grantsAccess() と同値へ収束する。 */
public function isDeclared(): bool { return true; }          // 既定
// App\DataTransferObjects\Billing\NoPlan
public function isDeclared(): bool { return false; }         // 唯一の override
```

解決順（D26 / P2 の 5 段）上、`PaidSubscriptionPlan`(段 1・2) / `ActivatedPersonalPlan`(段 3) / `GrandfatheredLegacyFreePlan`(段 4) が `isDeclared()=true`、`NoPlan`(段 5) のみ false。`free_plan_code` は `PersonalPlanService::FREE_PLAN_CODE='personal'` 以外の値を取らない（書き込み経路は `PersonalPlanService` のみ = `FreePlanCodeWriteInvariantTest` が固定）ため、「`free_plan_code !== null`」と段 3/4 の条件は同値である。

**Plan 集合の露出規則（単一規則 = `plans.is_active`）**

```php
/** @var list<PlanDto> $plans */
$plans = Plan::query()
    ->where('is_active', true)                                   // P1 で導入済みの列。公開制御の唯一の規則
    ->whereIn('code', array_map(static fn (PlanCode $c): string => $c->value, PlanCode::cases()))
    ->orderBy('sort_order')
    ->get()->map(static fn (Plan $p): PlanDto => PlanDto::fromModel($p))->values()->all();
```

- `whereIn(PlanCode::cases())` により **legacy `free` 行（P4 で撤去）は onboarding に出ない**（personal と二重に無料枠が並ぶ UX 破綻を防ぐ）。
- **Personal 自己申告 step も同一規則に従う**: `personalEligibility` は personal Plan 行が `is_active=true` のときのみ非 null（非公開プランを直接 POST で露出させないため、`activate-personal` も personal 行が非 active なら **404**）。P8b の再公開 data migration（`personal`/`starter` を `is_active=true` へ）でコード変更なしに card と step が同時に露出する。

**DTO 形状（P3 スコープ。フィールド名は aigenba と同一）**

```
OnboardingCheckoutShape = {
  plans: PlanShape[],                   // is_active=true かつ PlanCode 集合。sort_order 昇順
  recommendedPlanCode: string,          // PlanCode::Standard->value  （aigenba と同値。P1 PlanSeeder の standard）
  defaultPlanCode: string,              // PlanCode::Starter->value   （aigenba と同値。P1 PlanSeeder の starter）
  contactUrl: string,                   // ContactUrl::resolveForSource(InquirySource::Onboarding)
  personalEligibility: {eligible: bool, reason: string|null, reasonLabel: string|null}|null,  // P1 eligibility()
  signupGrantTickets: int,              // TicketPricingService::signupGrantTickets()
}
PlanShape = { code: string, name: string, monthlyTicketGrant: int, currentBaseAmount: int|null,
              currency: string|null, sortOrder: int }
BillingRequiredShape = { ownerName: string|null, ownerEmail: string|null, contactUrl: string }
```

- **`PlanDto` は AI-CUE の実列にのみマップする**: aigenba の `includedSeats` / `includedMonthlyTickets` / `scenarioLimit` / `courseLimit` / `currentSeatAmount` は **AI-CUE の `app/Models/Billing/Plan.php` に存在しない**（AI-CUE は `code / name / monthly_ticket_grant / sort_order`、能力は `config/quota.php` の「値」で表現するのが既存規約）。席課金は概念設計でスコープ外（原則 4）。`currentBaseAmount` / `currency` は `Plan::currentPrice(PlanPriceKind::Base)` から取り、base price 不在（personal）は `null` = 無料表示契約。
- **`currentSeatCount` / `starterAutoMigrationDays` は持たない**（席概念なし / Starter 自動移行は aigenba 固有機能）。
- **`subscriptionAttemptToken` は持たない（P9 所管）**。チケット決済の `ticketAttemptToken` は既存の冪等性機構であり P3 では触らない。
- `intendedPlanCode` / `preselectFunding` は **P7**、`autoRechargeTerms` / `funding_choice` / `consent_version` は **P8a** の additive 追加。
- `defaultPlanCode` / `recommendedPlanCode` は**コード値**であり `plans` への包含を保証しない。フロントは `plans` に該当 code があるときのみ preselect し、無ければ先頭 plan を選択する（決定的挙動。P8b の再公開で starter が現れれば追加変更なしに既定が効く）。

**DB 列 / index**: **P3 での追加・変更なし**（P1 の列・index を読むだけ）。**migration は 1 本**: `activate_personal_plan`（data migration。**`code='personal'` のみ**を `is_active=true` へ。**Starter は `is_active=false` のまま** = 再公開は P8b。D10 / P4 の前提条件）。

**route 構造上の帰結**: onboarding route は **route parameter を持たない current-org スコープ**（D6/D21）であり、既存 `billing.checkout` と**同一の組織解決**を使う。したがって「URL の org ≠ current org」という状態が**構造的に発生せず**、cross-org 課金の余地がない。`isCurrentOrganization` prop・組織切替 CTA・org-slug 非対称は**存在しない**。

**UI 契約**: 両ページとも `AppLayout` + `PageContainer` + `PageHeader` + `PageContent`（T071 primitive / arch: page-shell-structure）。plan grid は既存 `resources/js/components/molecules/PricingPlanCard.svelte`（aigenba から移植済み・DTO 非依存 primitive props）を再利用し**新規 molecule を作らない**。アイコンは `@lucide/svelte` の named import のみ（`lucide-scoped-import`）、色は DS token のみで hex 直書き禁止（`ds-purity`）、import 方向は pages → templates/molecules/atoms（`atomic-import-graph`）。
**D4（AGENTS.md 禁止事項 #8）**: Personal 有効化 CTA は `personalEligibility.eligible=false` でも `declaration` 未チェックでも **disabled にしない**。押下すると submit し、サーバの 422 由来 `errors.plan_code` / `errors.declaration` を表示する（`personalEligibility.reasonLabel` は理由 caption として常時可視。**いずれの文言もサーバ確定**でフロントは組み立てない）。eligibility は render 後に変化しうるため、**サーバ判定が唯一の権威**である。

#### PHPStan 適合チェック

- Controller 戻り値: `show(): Response|RedirectResponse` / `__invoke(): RedirectResponse`。内部遷移に `Inertia::location()` を使わないため `SymfonyResponse` は union に不要。**`response()->json()` 不使用**。
- `$request->user()` は `Assert::isInstanceOf($user, User::class)` で narrowing（既存 `ResolvesCurrentOrganization` / `BillingController` と同一パターン）。`abort_if` に型絞りを依存させない。
- `resolveMemberCurrentOrganization()` は `Organization` を返す（`abort_if` 後の `$organization` は `Organization`。`BelongsTo` 由来の `Organization|null` は `abort_if($organization === null, 404)` で解決済み）。
- `list<PlanDto>` を保つため `->map(...)->values()->all()`（`values()` を省くと `array<int, PlanDto>` に落ちて level 10 で落ちる）。`whereIn` に渡す `array_map(fn (PlanCode $c): string => $c->value, PlanCode::cases())` は `list<string>`。
- `Plan::query()->where('is_active', true)` は **P1 で `is_active` を列 + `casts()` + `@property bool $is_active` に追加済み**のため larastan の model property 解決が通る。
- DTO は全て `final readonly` + `@phpstan-type ...Shape` + `toArray(): ...Shape`。`PlanDto` は `@phpstan-import-type PlanDtoShape` 可能な形にし、`OnboardingCheckoutDto` は `PlanDtoShape` / `PersonalPlanEligibilityShape` を import する。
- `Plan::currentPrice(PlanPriceKind::Base)` は `?PlanPrice` → `$price?->amount` / `$price?->currency` で `int|null` / `string|null` にそのまま落とす（DTO 側が nullable を型で表明）。
- Owner 解決 `->first(static fn (User $u): bool => $u->organizationRole($organization) === OrganizationRole::Owner)` は `?User` → `$owner instanceof User ? $owner->name : null`（`routeNotificationForMail` と同形）。
- `TicketPricingService::signupGrantTickets(): int` / `PersonalPlanNotEligibleException::userMessage(): string`（P1 産出）を前提にし、`config()` の `mixed` 直読みを増やさない。
- `InquirySource::label()` の `match ($this)` は case 追加で網羅維持（`identical.alwaysFalse` は発生しない）。`PlanCode` は 3 case で閉じている。
- `EffectivePlan::isDeclared()` は抽象クラス側に既定実装（`true`）+ `NoPlan` の override。`instanceof` narrowing 不要で呼び出し側の分岐は生じない。
- **baseline 化 / widen は行わない**（禁止事項 #2）。

#### テスト計画

**先に red を作る（新規 Feature）**

1. `tests/Feature/Onboarding/OnboardingCheckoutTest.php`
   - **current org 不在 → 404** / **current org がユーザー非所属（`current_organization_id` が残存する退会ユーザー）→ 404**（403 にしない = 存在秘匿）。**同じ 404 テストを 3 route すべてに置く**（不変条件 #2 の網羅）。
   - 未申告 + `manageBilling` なし member → `onboarding.billing-required` へ redirect。
   - 未申告 + owner → `Onboarding/Checkout` を 200 render / `pageData.plans` は `is_active=true` かつ `PlanCode` 集合のみ（**legacy `free` 行は含まれない**）で `sort_order` 昇順 / `recommendedPlanCode === 'standard'` / `defaultPlanCode === 'starter'` / `signupGrantTickets === TicketPricingService::signupGrantTickets()` / `contactUrl` が `source=onboarding` 付き。
   - `is_active=false` の Plan は `pageData.plans` に出ない（露出規則の固定）。personal 行が非 active のとき `personalEligibility === null`、active のとき非 null。
   - **申告済み → `billing.index` へ**: `free_plan_code='personal'`（declarer あり / declarer なし = grandfathered の両方）/ active subscription / `plan_code` 非 null。
   - **F-07 条件 A の直接検証（本フェーズの要）**: `plan_code IS NULL` かつ `free_plan_code IS NULL` かつ subscription なしの org（= P3 時点の既存全 org）で checkout が **200 で render される**（= 入口ガードに `BillingAccess::hasActiveAccess()` を使っていたら必ず red になるテスト）。
2. `tests/Feature/Onboarding/ActivatePersonalTest.php`
   - current org 不在 → 404 / 非所属 → 404 / `manageBilling` なし member → **403**。
   - `declaration` 未チェック → redirect-back + `errors.declaration`（XHR は 422）。
   - 成功 → `organizations.free_plan_code='personal'` / `personal_declared_by_user_id` = declarer / `signup_tickets_granted_at` 設定 / signup grant 付与（P1 の marker 経路）/ **`dashboard` へ redirect** + success flash（枚数入り文言）。
   - **二重 POST 冪等**: 2 回目は `granted=false` 側の文言で、`ticket_ledger_entries` の `signup_grant:%` は **1 行のまま**（不変条件 #7 と P1 marker の回帰）。
   - eligibility 不成立（別 free personal org 保有 / メンバー 4 名 / 有効 subscription あり）→ redirect-back + `errors.plan_code` に**サーバ確定文言**（`PersonalPlanNotEligibleException` が **500 にならない** = 422 相当）。
   - personal Plan が `is_active=false` → **404**（非公開プランは POST でも露出しない）。
   - `throttle:10,1` が効く（11 回目 429）。
3. `tests/Feature/Onboarding/BillingRequiredTest.php`
   - current org 不在 → 404 / 非所属 → 404。
   - 申告済み → `dashboard` / `manageBilling` 保持者 → `onboarding.checkout`（**離脱ガード = 行き先のない詰みの回避**）。
   - 未申告 + 一般 member → 200 render + `ownerName` / `ownerEmail` / `contactUrl`。
   - Owner 不在 org（Owner が抜けた）でも 200 で `ownerName`/`ownerEmail` が null（`routeNotificationForMail` と同じ null 許容）。
4. `tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php`
   - `fromModel()` が `code` / `name` / `monthly_ticket_grant` / `sort_order` と現行 base price（`PlanPriceKind::Base` かつ `is_current`）をマップする / base price 不在 → `currentBaseAmount === null` かつ `currency === null`（= 無料表示契約）。
5. `tests/js/pages/OnboardingCheckout.test.ts`（**D4 適用**）
   - `personalEligibility.eligible=false` でも Personal CTA は**押せる状態を維持**し、`reasonLabel` を caption として表示する / 押下で submit され、返ったサーバ文言（`errors.plan_code`）を表示する。
   - `declaration` 未チェックでも submit ボタンは**押せ**、押下後に `errors.declaration` を表示する。
   - `defaultPlanCode` が `plans` に含まれるときは preselect、含まれないときは先頭 plan を選択する。
6. `tests/js/pages/OnboardingBillingRequired.test.ts`
   - `ownerName`/`ownerEmail` が null でも描画が壊れず、問い合わせ導線が出る。

**既存テストの更新対象**: **なし**（削除も一切なし）。arch テスト（`page-shell-structure` / `ds-purity` / `atomic-import-graph` / `lucide-scoped-import` / `FormRequestProhibitedKeyTest` / `ValidationAttributeCoverageTest` / `NestedRouteIdorDefenseTest` / `OrganizationRouteParamWebOnlyInvariantTest` / `MassAssignmentSafetyTest`）は **allowlist 追加なしで green のままであること**を DoD にする（allowlist 追加が必要になった時点で設計を疑う）。テストデータは全て Factory + `PlanSeeder` 経由で生成する。

#### リスク

| リスク | 緩和 |
|---|---|
| **入口ガードを aigenba 機械移植すると P3 の導線が到達不能**（`plan_code null → hasActiveAccess=true` で常に `billing.index` へ）。気付かず P4 に進むと**条件 A 未達のまま反転 = F-07 再発** | 「未申告 org で checkout が 200」を Feature テストで先に red 化（テスト計画 1 の最終項）。入口ガードは `EffectivePlan::isDeclared()` を使い `BillingAccess` を読まない |
| ~~P3〜P8a の間 personal/starter が非公開~~ → **D10 改訂で解消**。**Personal は P3 の `activate_personal_plan` migration で公開**（P4 の反転時に無料導線が実在する）。**Starter のみ P8b まで非公開**（有償で checkout UI が要るため）。checkout の選択肢は personal + standard になる | — |
| current org スコープのため `current_organization_id` の不整合（退会後の残存）が cross-org 課金に化ける | `resolveMemberCurrentOrganization()` が**認可より前に 404**（不変条件 #2）。**3 route すべてに同一の 404 テスト**を置く。route parameter が無いため「URL の org ≠ current org」自体が構造的に発生しない |
| P1 未マージ（`PersonalPlanService::activate()` / `PlanCode` / `free_plan_code` / `plans.is_active` / personal seed）だと P3 が実装できない | 依存順（`P1 → P2 → P3`）は交渉不可。`activate-personal` は P1 の `activate()` を**呼ぶだけ**で付与ロジックを再実装しない（二重付与源を作らない） |
| `Onboarding/Checkout.svelte` を 643 行 verbatim 移植すると P7/P8a の未実装機能（funding 2 択・同意・intended バッジ）を先取りして壊れる | P3 は plan grid + Personal 自己申告 step のみ。**フィールド名を aigenba と同一**にし、P7/P8a は DTO/TS に additive に足すだけにする（再設計コストゼロ） |
| Personal 有効化後の着地が `dashboard` 固定（aigenba は `OnboardingReturnResolver` で復帰） | `OnboardingReturnResolver` は **P7 の成果物**。P3 時点はゲート未反転 = 「gate に奪われた destination」が存在しないため機能欠落にならない。P7 で着地解決へ差し替える |
| `InquirySource` に case を足すと公開フォームの `source` allowlist が広がる | `normalize()` が allowlist 検証を担い自由入力を正本に残さない設計は不変。追加は 1 case（`onboarding`）で流入元分析の粒度が増えるだけ |
| aigenba の `GuestLayout` を捨てたことで見た目が aigenba と差分になる | 外枠規約（T071 / page-shell-structure）は AGENTS.md 側の不変条件で parity に優先する（原則 1）。本文（plan grid / 自己申告 step）は aigenba の構成と文言を維持する |

---

### P4 ゲート反転 + grandfathering 移行（free 撤去を含む。山場）

前提: P1（`free_plan_code` 等の列 + partial unique index + `PersonalPlanService::activate()` 完成 + `plans.is_active` + `config/quota.php` の `personal`/`starter` limits）/ P2（`BillingAccess::effectivePlan()` = 唯一の判定源。4 variant + 5 段の解決順）/ P3（`onboarding.{checkout,activate-personal,billing-required}` 導線）がマージ済み。

P4 は**判定の結論を変える唯一のフェーズ**で、内容は 4 点に閉じる（新機能を足さない）:

1. **`NoPlan::grantsAccess()` を `false` にする**（判定変更はこの 1 点のみ。他 3 variant は不変 = 既存ユーザーは締め出されない）。
2. `RequireActiveSubscription` の遮断先を aigenba 方式へ（`manageBilling` 保持者 → `onboarding.checkout` / 非保持者 → `onboarding.billing-required`）。JSON/XHR への 402 は維持（D15）。
3. **declarer-less grandfathering backfill**（既存 org を救う data migration）。
4. **free 撤去（D11）**（Plan 行削除 data migration + Seeder + `config/quota.php` の `fallback_plan='personal'`）。

**DoD**: **前提条件として P3 の Personal 公開 migration が適用済みで `plans.code='personal'` が `is_active=true`** であること
（**無料導線が実在しない状態で反転すると、未契約者が Standard しか選べず F-07 の変種が再発する**。Codex Round 6 Critical）。
その上で `列/index（P1 済）→ backfill 完了・件数検証 → **Personal 公開の確認** → ゲートコード deploy` の順序を守り、
**backfill が失敗したらゲートを反転しない**（migration の throw でデプロイが中断し旧リリースが生き続ける）。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| ファイル | 変更 | 移植元 |
|---|---|---|
| `/workspace/app/DataTransferObjects/Billing/NoPlan.php` | `grantsAccess()` を `true` → **`false`**。**本フェーズの判定変更はこの 1 行のみ**（`kind()` / `planCode()` / `deniedReason()` / `toArray()` は不変） | `/tmp/aigenba/app/Enums/Billing/OnboardingBillingState.php` の `NoSubscription`（`grantsAccess()=false`）。※ `OnboardingBillingState` は **aigenba 側の実装名**であり AI-CUE には導入しない（D18: 判定源は `EffectivePlan` 単一） |
| `/workspace/app/Services/Billing/BillingAccess.php` | **コード変更なし**（P2 で `effectivePlan()->grantsAccess()` 委譲の薄い façade 済み）。クラス docblock の「意図的な書き換え（devnotes/20260712-0927-bugfix-billing-free-access）= plan_code null は支払い不要 tier として許可」節を**反転記録**（本設計を正とする / 旧 devnote は歴史として保持 / 無料枠は `free_plan_code='personal'` の明示申告で表現する）へ差し替える | `/tmp/aigenba/app/Services/Billing/BillingAccess.php:26-30` |
| `/workspace/app/Http/Middleware/RequireActiveSubscription.php` | 遮断分岐を反転。`effectivePlan($org)->grantsAccess()` で通過判定 → 不許可なら `manageBilling` 保持者を `onboarding.checkout`、非保持者を `onboarding.billing-required` へ redirect。`billing.index` + `error` flash の誘導を廃止。**JSON/XHR は 402 を維持**（D15。文言は variant 由来）。`resolveOrganization()`（route binding 優先 → `currentOrganization` → null は素通し）・非メンバー 404 defense-in-depth・`session()->reflash()` は**現行のまま維持**。docblock を反転後の方針へ更新 | `/tmp/aigenba/app/Http/Middleware/RequireActiveSubscription.php:60-91`（`OnboardingReturnResolver` による destination 記憶 L79-81 は**移植しない** = P7） |
| `/workspace/database/migrations/2026_07_17_000300_backfill_grandfathered_free_plan_code.php`（新規 data migration） | 分類表の述語で `free_plan_code='personal'` / `free_plan_activated_at=now()` / `personal_declared_by_user_id=NULL` / `personal_declared_at=NULL` を chunk 更新。**grant を発火しない**（`signup_tickets_granted_at` に触れない）。末尾で残余件数を検証し 0 でなければ `RuntimeException` を throw。`down()` は no-op | 構造は `/tmp/aigenba/database/migrations/2026_07_08_113550_backfill_signup_tickets_granted_at.php`（分離 data migration + `whereNull` ガード + `down()` no-op）。grandfather backfill 相当は aigenba に無い（gate 有り前提のスタート）ため**移行データ固有の追加** |
| `/workspace/database/migrations/2026_07_17_000400_remove_free_plan_row.php`（新規 data migration。D11） | Seeder は `updateOrCreate` のため既存 DB の `free` 行が消えない。(1) `organizations.plan_code='free'` の参照行と `plans.code='free'` の関連 `plan_prices` を**事前検証し、残存すれば fail-closed（throw）**、(2) `plans` から `code='free'` を削除、(3) **残余 0 件を検証**。`down()` は `free` 行（`name='Free'` / `monthly_ticket_grant=10` / `sort_order=0` / `is_active=true`）を復元する（config は触らない） | — （AI-CUE 固有の移行。aigenba に free plan 行の概念が無い） |
| `/workspace/database/seeders/PlanSeeder.php` | `free` 行の `updateOrCreate` を**削除**（`personal` が後継 = P1 で seed 済み）。docblock の「free プランは Stripe Price を持たない = 未契約の既定」節を「free entitlement は `organizations.free_plan_code='personal'` で表現する（`plan_code` に載る経路は無い）」へ更新 | `/tmp/aigenba/database/seeders/PlanSeeder.php`（Personal/Starter/Standard のみで free 行を持たない） |
| `/workspace/config/quota.php` | `fallback_plan` を **`'personal'`** へ切替（P1 で追加済みの `personal` limits は旧 `free` と同値 = **実効 limits 不変**）。`plans` から `'free'` キーを削除（対応する Plan 行が消えるため。並走を残さない） | — |
| `/workspace/app/Services/Billing/QuotaService.php` | **コード変更なし**。docblock の「plan_code null は fallback_plan（free）」を `personal` 表記へ更新（解決先が `personal` になることは回帰テストで固定） | — |
| `/workspace/app/Models/Organization.php:37,110` / `/workspace/app/Services/Billing/StripeWebhookProcessor.php:49` | docblock の「plan_code null = fallback free = BillingAccess が業務 route を許可」記述を反転後の事実（`plan_code` null は quota の `fallback_plan='personal'` 適用。利用可否は `EffectivePlan` が決める）へ更新 | — |
| `/workspace/database/seeders/ManualTestSeeder.php` | current base Price を持たない Personal プラン組織を `PersonalPlanService::activate($org, $owner)` 経由で有効化する（`plan_code` は null のまま / declarer = owner / marker 付与は P1 の activate 内で 1 回）。手動テスト環境が反転後に締め出されないため | —（AI-CUE 固有 fixture） |
| `/workspace/database/factories/OrganizationFactory.php` | **変更なし**（`activatedPersonal()` / `grandfatheredFree()` / `paid()` state は P2 で追加済み） | `/tmp/aigenba/database/factories/OrganizationFactory.php:62-73`（`freePersonal()`） |
| `/workspace/tests/Pest.php:118-133` | `createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true)` に拡張。既定で **backfill 相当の状態**（`free_plan_code='personal'` / `free_plan_activated_at` / declarer NULL）を付与する。**`activate()` は呼ばない**（呼ぶと signup grant が発火し 106 テストファイルの残高期待が壊れる / declarer partial unique index に触れる）。ゲート・onboarding テストは `grandfatherFreePlan: false` で真の未契約 org を作る。docblock の「生成される組織は Free（未契約 = plan_code null）」を反転後の記述へ更新 | — |
| `/workspace/routes/web.php:302-317,343-348` | コメントのみ更新（gate group の allowlist 説明を「free 組織は遮断されない」→「未契約組織は onboarding へ遮断され、billing / ticket / onboarding 系は gate group 外の構造的 allowlist」へ）。**route 定義の変更なし**（`onboarding.*` は P3 が gate group 外に定義済み） | `/tmp/aigenba/routes/web.php:442-449` |
| `/workspace/docs/architecture.md:85` / `/workspace/docs/app-integration-guide.md:129-139,190` / `/workspace/docs/template-divergence.md §D9` | 課金ゲート方針の記述を反転後へ更新。**`template-divergence.md` の D9（free tier は課金ゲートを通す）は「解消（本設計で反転。無料枠は `free_plan_code='personal'` の明示申告へ移行）」として記録を更新**する（削除しない） | — |

**P4 に含めない（フェーズ境界の明示）**: `OnboardingReturnResolver` による destination 記憶（P7）/ `IntendedPlanResolver`（P7）/ `past_due` の entitlement 緩和（D8 = 別 TODO）/ `personal`・`starter` の `is_active=true` **Starter** の再公開（D10 の解除 = P8b。**Personal は P3 で公開済み**）/ サブスク checkout 用 attempt token（P9）。

#### 波及変更

- **TypeScript 型定義**: なし（`EffectivePlan` の shape は不変。`grantsAccess` の**値**が変わるだけ）。
- **DTO / JsonResource**: なし（`NoPlan` の戻り値のみ。`toArray()` の shape・`EffectivePlanKind` の case 集合は不変）。
- **Inertia props**: なし。遮断理由の提示は P3 の `Onboarding/Checkout` / `Onboarding/BillingRequired` の props に依存する（middleware は `->with('error', ...)` を渡さない = aigenba 同様「理由は着地ページが持つ」）。
- **テストファイル（更新。削除しない）**
  - `/workspace/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`
  - `/workspace/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`
  - `/workspace/tests/Feature/Billing/EffectivePlanResolutionTest.php`（P2 成果物。`NoPlan` の期待を `true` → `false` へ）
  - `/workspace/tests/Feature/Billing/QuotaTest.php`（`fallback_plan` = `personal` へ。limits の期待値は不変）
  - `/workspace/tests/Feature/Billing/BillingPageTest.php`（`plans.0.code` `'free'` → `'standard'`）
  - `/workspace/tests/Feature/Marketing/PricingPageTest.php`（`page.plans.0.code` `'free'` → `'standard'` / 件数）
  - `/workspace/tests/Feature/Billing/PlanSeederPriceInvariantTest.php`（`where('code','free')` → `'personal'`）
  - `/workspace/tests/Feature/Database/BughuntBillingSeederTest.php:68`（`plan_code='free'` の forceFill を撤去し「plan_code null の grandfathered org」へ = 課金なし経路の温存は不変）
  - `/workspace/tests/js/pages/Pricing.test.ts`（fixture の `code: "free"` を実在プランへ。表示ロジックはプラン名分岐を持たないため期待値のみ）
  - `/workspace/tests/Pest.php`（helper 既定の変更）
  - `Organization::factory()` を直呼びして gate 対象 route を叩くファイルの棚卸し: `/workspace/tests/Feature/Organization/OrganizationSwitchTest.php` / `/workspace/tests/Feature/Auth/ApiKeyGuardTest.php` / `/workspace/tests/Feature/Organization/DefaultTeamInvariantTest.php` / `/workspace/tests/Feature/Billing/BillingNotificationDispatchTest.php` / `/workspace/tests/Feature/Billing/SendBillingRemindersTest.php` / `/workspace/tests/Feature/Filament/UserResourceTest.php`。業務 route を叩くものだけ `grandfatheredFree()` state を付与する。
- **テストファイル（新規）**: 下記「テスト計画」。

#### 主要な契約

**判定（P2 成果物。P4 は段 5 の結論だけを変える）**

```text
解決順（上から最初に一致したものを返す）        variant                        grantsAccess (P2)   grantsAccess (P4)
1. active/trialing subscription あり           PaidSubscriptionPlan           entitlement (=true)  同左
   （同期ラグ対応。plan_code の null 有無を見ない）
2. plan_code 非 null                           PaidSubscriptionPlan           entitlement          同左
   （entitlement は denied を含む = 支払い不健全は遮断）
3. free_plan_code='personal' かつ declarer あり ActivatedPersonalPlan          true                 true
4. free_plan_code='personal' かつ declarer なし GrandfatheredLegacyFreePlan    true                 true   （P4 backfill 済）
5. それ以外                                    NoPlan                         true                 false  ← P4 の変更点（1 点のみ）
```

- 段 1 で active sub の price から plan code を解決できない場合は `PaidSubscriptionPlan(planCode: null, grantsAccess: true)` を返し（fallback quota 適用 + ログ・監視）、**`GrandfatheredLegacyFreePlan` へ倒さない**（P2 の契約。P4 は変更しない）。
- `free_plan_code='personal'` でない組織を `GrandfatheredLegacyFreePlan` へ入れる経路は存在しない。

```php
// App\DataTransferObjects\Billing\NoPlan — P4 の判定変更はここだけ
final readonly class NoPlan extends EffectivePlan
{
    public function grantsAccess(): bool { return false; }   // P2: true → P4: false
}

// App\Services\Billing\BillingAccess — P4 でコード変更なし（P2 で委譲済み）
public function hasActiveAccess(Organization $org): bool { return $this->effectivePlan($org)->grantsAccess(); }
public function effectivePlan(Organization $org): EffectivePlan;   // 唯一の判定源

// App\Http\Middleware\RequireActiveSubscription（差分のみ）
private const string NO_PLAN_MESSAGE  = 'ご利用にはプランの選択が必要です。';
private const string UNHEALTHY_MESSAGE = 'サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。';

public function handle(Request $request, Closure $next): Response
{
    // 未認証素通し / resolveOrganization()（route binding 優先 → currentOrganization → null 素通し）
    // 非メンバー 404 defense-in-depth は現行のまま維持
    $plan = $this->access->effectivePlan($organization);
    if ($plan->grantsAccess()) {
        return $next($request);
    }

    if ($request->expectsJson()) {                       // D15: JSON/XHR は 402 を維持
        abort(Response::HTTP_PAYMENT_REQUIRED, $plan instanceof NoPlan ? self::NO_PLAN_MESSAGE : self::UNHEALTHY_MESSAGE);
    }

    $request->session()->reflash();                      // 直前 hop の flash 延命（招待受諾等）は維持

    return redirect()->route(
        Gate::forUser($user)->allows('manageBilling', $organization)
            ? 'onboarding.checkout'                      // D6/D21: route parameter なしの current-org スコープ
            : 'onboarding.billing-required'
    );
}
```

- 認可 ability は既存の `manageBilling`（`app/Policies/OrganizationPolicy.php:37`）を使う（ability を増やさない）。
- 402 の文言を variant で出し分けるのは、既存 API/XHR クライアントの後退を避けるため（`UNHEALTHY_MESSAGE` は現行文言と同一）。
- ルート名 `onboarding.{checkout,activate-personal,billing-required}` は **gate group 外**（`routes/web.php:349` の `require-active-subscription` group に入れない = 構造的 allowlist）。入れると遮断 → 遮断の無限ループ = 詰みになる。
- **DB 列 / index の追加は無い**（すべて P1）。P4 は既存列への UPDATE のみ。partial unique index `organizations_personal_free_declarer_unique` は `WHERE free_plan_code='personal' AND personal_declared_by_user_id IS NOT NULL` のため、**declarer NULL の backfill 行は対象外 = 衝突しない**。
- backfill migration は `'personal'` リテラルを直書きする（aigenba の index 定義と同じ流儀 = migration がアプリ定数に依存しない）。ドリフトは invariant テストで固定する。

**backfill 分類表（effective entitlement snapshot ベース。確定）**

判定基準は raw な `plan_code IS NULL` ではなく、**「今日アクセスできているか（旧 `BillingAccess`）」×「反転後に backfill 無しでアクセスできるか（新 `effectivePlan()`）」**の 2 値。`sub` = `subscription('default')`。

| # | effective entitlement snapshot | 旧 gate | 新 variant（backfill 前） | 新 gate | 処置 |
|---|---|---|---|---|---|
| 1 | `plan_code` 非 null + sub `active`/`trialing`（`cancel_at_period_end` の grace は Cashier が status `active` + `ends_at` で保持 = 本行） | 許可 | `PaidSubscriptionPlan`（段 1） | 許可 | **何もしない** |
| 2 | `plan_code` null + sub `active`/`trialing`（webhook 同期ラグ / price → plan 解決不能） | 許可 | `PaidSubscriptionPlan(planCode: null)`（段 1。D26） | 許可 | **何もしない**（実効 entitlement は paid。free へ倒さない） |
| 3 | `plan_code` null + `free_plan_code` null + sub 行なし | 許可 | `NoPlan`（段 5） | **遮断** | **grandfather** |
| 4 | `plan_code` null + `free_plan_code` null + sub が `canceled`/`incomplete`/`unpaid`/`past_due`/`paused` のみ（= `subscription.deleted` 後に webhook が `plan_code` を null 化した paid→free 経路） | 許可 | `NoPlan`（段 5） | **遮断** | **grandfather** |
| 5 | `plan_code` 非 null + sub `past_due`/`unpaid`/`incomplete`/`canceled`/`paused` | **遮断** | `PaidSubscriptionPlan`（段 2。entitlement=denied） | 遮断 | **何もしない**（今日遮断中の org に free entitlement を与えない = 支払い健全性ゲートを緩めない） |
| 6 | `plan_code` 非 null + sub 行なし（webhook 順序逆転の壊れ状態） | **遮断**（fail-closed） | `PaidSubscriptionPlan`（段 2。entitlement=denied） | 遮断 | **何もしない** |
| 7 | `free_plan_code='personal'` + declarer あり（P3〜P4 間に自発 activate した org） | 許可 | `ActivatedPersonalPlan`（段 3） | 許可 | **何もしない**（`whereNull('free_plan_code')` ガード = 冪等） |
| 8 | `free_plan_code='personal'` + declarer なし（migration 再実行時） | 許可 | `GrandfatheredLegacyFreePlan`（段 4） | 許可 | **何もしない**（同ガード = 冪等） |
| 9 | 分類 3・4 のうち owner ロール保持者が 0 / メンバー数 > `PersonalPlanService::MAX_MEMBERS` | 許可 | `NoPlan` | 遮断 | **grandfather**（declarer-less のため `eligibility()` を評価しない = 移行分岐を作らない） |
| 10 | 付与履歴（`ticket_ledger_entries.idempotency_key LIKE 'signup_grant:%'`）/ `signup_tickets_granted_at` の有無 | — | — | — | **分類に影響しない**（backfill は grant を発火せず marker にも触れないため、未付与 org は将来の activate / paid 成立時に 1 回だけ付与される。真実源は P1 の marker） |

→ 実効述語は分類 3・4・9 を 1 本の SQL に縮退させたもの（分類 7・8 は `free_plan_code IS NULL` ガードで自動的に除外される）:

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

この集合は `{ org : 旧gate=許可 ∧ 新gate(backfill前)=遮断 }` と一致し、**「誰もアクセスを失わない」「今日遮断中の誰もアクセスを得ない」**の両不変条件を同時に満たす。**D22: この同値を migration テストで機械検証する**（下記）。

**free 撤去（D11）の実変更一式**

| 対象 | 変更 |
|---|---|
| `plans` テーブル | `remove_free_plan_row` migration が事前検証（`organizations.plan_code='free'` の参照行 / `free` の `plan_prices` が残存すれば throw = fail-closed）→ 削除 → 残余 0 件検証 |
| `PlanSeeder` | `free` 行の投入を削除 |
| `config/quota.php` | `fallback_plan: 'free' → 'personal'` / `plans` から `'free'` キーを削除（`personal` limits は P1 で投入済み・旧 free と同値 = 実効 limits 不変） |
| `/pricing`・`/billing` の一覧 | P1 で入った `plans.is_active` フィルタにより、**P4 時点で `personal`（P3 で公開済）+ `standard` が露出する**。`starter` のみ P8b まで非公開（**`personal` は `is_active=true`（P3 で公開済み）/ `starter` のみ `is_active=false`**）。無料枠は `onboarding.checkout` の導線から選べるため詰まない。**P8b で `is_active=true` へ再公開**する |

**デプロイ順序（DoD）**

1. 列 + partial unique index は **P1 で適用済み**。
2. `php artisan migrate` が `backfill_grandfathered_free_plan_code` → `remove_free_plan_row` の順に完了し、それぞれ末尾の**件数検証**（残余 = 0）を通る。
3. その後にゲートコード（`NoPlan::grantsAccess()=false` + middleware）のリリースが活性化する。

migration が throw した場合はデプロイが中断し、**旧リリース（ゲート未反転）が生き続ける** — これが「backfill 失敗ならゲートを反転しない」の実現機構。

**rollback 手順（運用手順として分離。migration の `down()` はリポジトリ内の config を書き換えられないため、`down()` で config を戻すとは書かない）**

1. **コード / config を revert**（`NoPlan::grantsAccess()=true` / middleware / `config/quota.php` の `fallback_plan='free'` + `plans.free` 復活）→ 締め出しが即座に解消する。
2. 必要なら **`remove_free_plan_row` の `down()`** を実行し `plans` の `free` 行を復元する（1 と 2 の間は `fallback_plan='free'` が config の limits だけを引く = 実害なし。`/pricing` に free が出ないだけ）。
3. **grandfather backfill は revert しない**（`down()` は no-op）。旧コードは `free_plan_code` を読まないため、backfill 済みの行は無害に無視される。

#### PHPStan 適合チェック

- `NoPlan::grantsAccess(): bool { return false; }` — 呼び出し側は抽象型 `EffectivePlan` 経由のため `identical.alwaysFalse` は出ない（`instanceof` narrowing も final variant に対して有効なまま）。抽象宣言 `grantsAccess(): bool` を widen しない。
- `RequireActiveSubscription::handle()`: `$request->route('organization')` は `mixed` → 既存の `instanceof Organization` narrowing（`resolveOrganization(): ?Organization`）を維持。`$user` も既存の `instanceof User` narrowing を維持。`$plan instanceof NoPlan` で 402 文言を分岐（`EffectivePlan` が abstract なので alwaysFalse にならない）。
- `Gate::forUser($user)->allows('manageBilling', $organization)` は `bool`、`route(string): string`、`redirect()->route()` は `RedirectResponse`（⊂ `Response`）、`abort()` は `never`。`@param Closure(Request): Response $next` docblock を維持し、全経路が `Response` を返すことを型で保証する。
- migration は `DB::table()` クエリビルダのみ（Eloquent モデル・アプリ定数に依存しない）。件数は `->count(): int`、削除は `->delete(): int` で受け、違反時は `RuntimeException` を直接 throw。
- `config()->string('quota.fallback_plan')` の typed accessor を維持（`QuotaService` のコードは無改変）。
- `tests/Pest.php` の `createOrganizationWithOwner(): array{Organization, User}` は戻り値型不変。追加引数は `bool` 既定値付き。
- **baseline / widen は使わない**（禁止事項 2）。

#### テスト計画

**先に red で書く（F-07 回帰。新規 `/workspace/tests/Feature/Billing/GateInversionF07RegressionTest.php`）**

- **(a) 既存 `plan_code IS NULL` 組織が移行後も業務ルートに到達する**: `createOrganizationWithOwner()`（既定 = backfill 相当の declarer-less grandfathered）で org を作り、`/projects` が `assertOk()` + `assertInertia(component 'Projects/Index')`、`POST /projects` でプロジェクト作成に到達、`/app` に到達。**declarer NULL でも通る**ことを固定する。
- **(b) 新規登録者が遮断されても activate-personal / checkout に到達し詰まない**: `createOrganizationWithOwner(grandfatherFreePlan: false)` の owner → `/projects` が `onboarding.checkout` へ redirect、着地が 200 → `POST onboarding.activate-personal` → 再度 `/projects` が `assertOk()`（= 導線が閉じている）。`manageBilling` 非保持 member は `onboarding.billing-required` へ redirect し着地が 200。
- **(c) 遮断時に理由が画面に出る（H1「説明なしリダイレクト」の再発検知）**: 遮断 redirect を follow した着地が **`billing.index` でないこと**を明示 assert し、Inertia component が `Onboarding/Checkout` / `Onboarding/BillingRequired` であること、および理由提示の素材が props に載っていること（Checkout: `pageData.plans` 非空 + `pageData.personalEligibility` 非 null / BillingRequired: `pageData.ownerEmail`・`pageData.contactUrl`）。JSON は 402 + `message` が variant 別の確定文言と一致。
- **(d) 無限ループ不在**: `onboarding.*` / `billing.*` / チケット購入系が gate group 外である構造的 allowlist の検証（遮断 redirect 先を再度叩いて 302 が返らないこと）。

**backfill migration テスト（新規 `/workspace/tests/Feature/Billing/GrandfatherFreePlanBackfillTest.php`）**

- **D22（必須 DoD）**: 分類表 10 行を Factory で組み、**expected 集合**（分類表で grandfather 対象と判定した org の ID 集合 = テスト側で PHP により独立に算出）と **actual 集合**（migration 実行後に `free_plan_code='personal'` かつ declarer NULL になった org の ID 集合から、実行前から該当していた org を除いた差分）の **双方向完全一致**をアサートする（`expected \ actual === []` かつ `actual \ expected === []`）。片側包含では条件漏れ（締め出し）も誤救済（収益後退）も検出できないため、両方向を必須とする。
- 分類 5・6 が**救われない**（支払い不健全が free に落ちない）/ 分類 2 が**救われない**（paid 実効を free へ倒さない）。
- **grant が 1 枚も発火しない**（`ticket_ledger_entries` の件数不変 + `signup_tickets_granted_at` 不変）。
- 2 回実行して結果不変（冪等。`whereNull('free_plan_code')` ガード）。
- declarer-less 行が partial unique index に衝突せず、**同一 user が複数 org を持っていても全件救われる**。backfill 後に当該 org の owner が別 org で `activate` しても index 違反にならない。
- 残余件数検証が 0 でないときに migration が `RuntimeException` を throw する（= デプロイ中断 = ゲート非活性）。

**free 撤去テスト（新規 `/workspace/tests/Feature/Billing/RemoveFreePlanRowMigrationTest.php`）**

- `organizations.plan_code='free'` の参照行が残る状態 / `free` の `plan_prices` が残る状態で **fail-closed（throw）**し、`plans` の `free` 行が消えないこと。
- 参照ゼロなら削除され、`plans.code='free'` の残余が 0 件。
- `down()` で `free` 行が復元される（config は触らないこと = リポジトリ内 config が無改変であることを assert）。

**arch / invariant テスト（新規 `/workspace/tests/Architecture/BackfillPlanCodeLiteralInvariantTest.php`）**

- backfill migration が直書きする `'personal'` リテラルが `PersonalPlanService::FREE_PLAN_CODE` と一致すること（ドリフト検知）。
- `config('quota.fallback_plan')` が `PersonalPlanService::FREE_PLAN_CODE` と一致し、対応する limits が `config('quota.plans')` に存在すること（`QuotaService` の「未知キー = 無制限」silent 退行の防止）。

**既存テストの更新（削除しない）**

- `/workspace/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`
  - 冒頭コメントの gate 方針を反転後の記述へ。
  - 「Free（未契約）組織は業務 route に到達できる（F-07 再現）」3 本 → 「**未契約組織は `onboarding.checkout` へ遮断される**」+「**grandfathered / activated な free 組織は到達できる**」へ期待を更新。
  - 「有償契約 + 支払い不健全は billing へ redirect + 理由 flash」→ **`onboarding.checkout` へ redirect（flash なし）**へ。dataset に **`paused` を追加**する（`past_due` / `unpaid` / `incomplete` / `canceled` / `paused`）。
  - 「有償契約 + subscription 行なしは fail-closed」→ 遮断先を `onboarding.checkout` へ更新（**`plan_code` 非 null + sub 不在は P4 でも遮断**の固定）。
  - 「有償契約 + 支払い不健全の JSON は 402 + message 固定」は**文言も含めて維持**（D15 = 既存 XHR クライアントの後退なし）。加えて「**未契約の JSON は 402 + `NO_PLAN_MESSAGE`**」を 1 本追加。
  - `BillingAccess` 単体マトリクスを `effectivePlan()` ベースへ更新: `plan_code` null + sub なし + `free_plan_code` null → **false** / `free_plan_code='personal'`（declarer 有無を問わず）→ **true** / **`plan_code` null + active sub → true（D26。P4 後もアクセス継続）** / `plan_code` 非 null は `active`/`trialing` のみ true。
  - 「free プランは Stripe Price を持たない（plan_code に free が入る経路がない前提の固定）」→ 対象を `config('quota.fallback_plan')`（= `personal`）へ読み替えて**維持**。
  - 「route bound organization が有償不健全なら redirect」「非メンバー 404 defense-in-depth」「billing ページは遮断中でも到達できる」は**維持**（遮断先の期待のみ更新）。
- `/workspace/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`: 「seeder の free 組織が**素通り**する」→「seeder が `PersonalPlanService::activate()` 済みのため全ロールが `/projects` に到達する」へ。`seededFreePlan()` の解決先は `free` 消滅により `personal`（current base Price を持たない唯一のプラン）になる。`expect($organization->plan_code)->toBeNull()` は**維持**し、`expect($organization->free_plan_code)->toBe('personal')` と declarer 非 null を追加（F-C3 の不変条件を残したまま反転後の事実を固定）。
- `/workspace/tests/Feature/Billing/EffectivePlanResolutionTest.php`（P2 成果物）: `NoPlan` の `grantsAccess` 期待を `true` → `false` へ。他 3 variant の期待は**不変**（= 反転が 1 点に閉じている証明）。
- `/workspace/tests/Feature/Billing/QuotaTest.php`: 「`plan_code` 未設定の組織には `fallback_plan`（free）の既定 limits が効く」→ `personal` 表記へ。**limits の期待値（`max_projects=1` / `max_members=3` / `max_storage_bytes=1GiB`）は 1 つも変えない**（実効 limits 不変の証明）。
- `/workspace/tests/Feature/Billing/BillingPageTest.php` / `/workspace/tests/Feature/Marketing/PricingPageTest.php` / `/workspace/tests/js/pages/Pricing.test.ts` / `/workspace/tests/Feature/Billing/PlanSeederPriceInvariantTest.php` / `/workspace/tests/Feature/Database/BughuntBillingSeederTest.php`: 上記「波及変更」のとおり `free` 参照を実在プランへ更新。
- `/workspace/tests/Pest.php`: helper 既定の変更（docblock 含む）。

**新規（UI 文言の固定）**

- `/workspace/tests/js/pages/OnboardingBillingRequired.test.ts`: `Onboarding/BillingRequired` が「なぜ操作を続けられないか」の説明コピーと owner 連絡導線をレンダすること（H1 の再発検知をコンポーネント側にも置く。props 追加はしない）。

**共通**: テストデータは Factory 生成（手組み禁止）/ `RefreshDatabase` グローバル + `--parallel` 前提を維持（個別 `DatabaseTransactions` を追加しない）。

#### リスク

| リスク | 緩和 |
|---|---|
| **既存ユーザー締め出し（F-07 再発）** = backfill 漏れ or デプロイ順序逆転 | 述語を `{旧gate=許可 ∧ 新gate=遮断}` の集合と一致させ、**D22 の双方向集合同値アサート**で分類表と実 SQL のズレを機械検出。migration 末尾の残余 0 件検証が throw → デプロイ中断（ゲート非活性）。 |
| **106 テストファイルの一斉 red** | `createOrganizationWithOwner` の既定を **backfill 相当（declarer-less grandfathered）**に変更して吸収。`activate()` を呼ばないため **signup grant が発火せず既存の残高期待が壊れない**、かつ declarer partial unique index に触れないため 1 user 複数 org のテストも 23000 にならない。`Organization::factory()` 直呼び 6 ファイルのみ `grandfatheredFree()` を手当。 |
| **支払い不健全（`past_due` 等）の paid org が遮断先の checkout から Personal(free) へ自主降格できる**（`eligibility()` の `hasEntitledSubscription` が false になるため） | **aigenba も同挙動**であり独自ガードを足さない（原則 2）。降格しても `plan_code` 非 null + sub 不健全のまま `free_plan_code` が立つが、解決順の段 2 が段 4 より上位のため `PaidSubscriptionPlan(denied)` = **遮断は維持される**（収益ゲートは緩まない）。この解決順の効果を `EffectivePlanResolutionTest` の該当ケースで固定する。 |
| **`error` flash 廃止で遮断理由が失われる** | 理由は着地ページが持つ（aigenba 方式）。F-07 テスト (c) と `OnboardingBillingRequired.test.ts` が固定。`reflash()` は招待受諾等の直前 flash 延命のため維持する。 |
| **JSON 402 の文言変更で既存 XHR クライアントが後退** | 支払い不健全経路の文言は現行と同一のまま維持（D15）。新文言は「未契約」という**新しい遮断事由にのみ**追加される。 |
| ~~P4〜P8b の間 /pricing に無料枠が出ない~~ → **D10 改訂で解消**。**Personal は P3 で公開済み**のため `/pricing` にも `onboarding.checkout` にも無料枠が出る（free 撤去後の後継は personal） | — |
| **`fallback_plan` 切替で quota が silent に緩む**（`QuotaService` は未知キーを `?? []` = 無制限に倒す） | `personal` limits は P1 で投入済み・旧 free と同値。`QuotaTest` の limits 期待値を 1 つも変えないことで実効不変を証明し、`BackfillPlanCodeLiteralInvariantTest` が `fallback_plan` ⊆ `quota.plans` を CI で固定する。 |
| **`free` Plan 行の削除が参照行を壊す** | migration が `organizations.plan_code='free'` / 関連 `plan_prices` を事前検証して **fail-closed**（黙って消さない）。free は Stripe Price を持たないため `plan_code` に載る経路が構造的に無く、本番の残存は 0 件が期待値。残存が出た場合はデプロイを止めて調査する。 |
| **grandfathered org が declarer-less のまま滞留**（濫用防止が既存 org に効かない） | 概念設計で受容済み（自然収束しない旨を明記）。P4 は主張を広げない。 |
| **遮断先ページ（P3）の欠落・rename** | P4 のテストが route 名 3 本を直接叩くため欠落は red で検知。P4 単独マージ不可の依存として DoD に明記。 |
| **backfill の長時間ロック**（大量 org） | chunk 更新 + `whereNull('free_plan_code')` ガードで再実行安全。additive な UPDATE のみで index 再構築を伴わない。 |

---

### P5: チケット残高会計の精緻化（台帳の置換ではない）

現行 `TicketLedgerService::balance()` は docblock（`app/Services/Billing/TicketLedgerService.php:217-225`）自身が
「失効は未消費分も含めた全額失効として保守的に働く」と近似を認める単一 int。これを aigenba の per-bucket 会計へ寄せる。
**変更は additive 列 + 読み取り計算のみ**で、台帳（`ticket_ledger_entries`）は列追加ゼロ・既存行の書き換えゼロ。
reserve→commit/release の 2 フェーズ（AGENTS.md 不変条件 #7）と amount ベース reserve
（`AnalysisPipeline.php:121` / `RenderPipeline.php:177` の `reserve($organization, $cost)`）は維持する。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| ファイル | 内容 | 移植元（aigenba） |
|---|---|---|
| `database/migrations/2026_07_1x_xxxxxx_add_consume_columns_to_ticket_reservations.php`（新規） | `ticket_reservations` へ additive 3 列: `consume_source`(string nullable) / `consume_expires_at`(timestamp nullable) / `consume_monthly_amount`(unsignedInteger nullable)。**データ backfill はしない**（既存 Reserved 行は 3 列 null = legacy） | `TicketService.php:425-437` の `ticket_reservations` insert 列（`consume_source` / `consume_expires_at`） |
| `app/DataTransferObjects/Billing/TicketBalanceDto.php`（新規） | `monthlyRemaining` / `purchasedRemaining` / **`debt: int`（正数）** / `activeReservations` / `nextExpireAt` + `totalAvailable()`（債務控除後の非負値）+ `toArray()` | `app/DataTransferObjects/Billing/TicketBalanceDto.php`（shape verbatim + `debt` 追加） |
| `app/Enums/Billing/TicketCommitResult.php`（新規） | `Committed` / `AlreadyCommitted` / `ReleasedExpired` | `app/Enums/Billing/TicketCommitResult.php` **verbatim**（case を足さない） |
| `app/Models/Billing/TicketReservation.php` | 3 列の `@property` + `casts()`（`consume_source` => `TicketSource::class` / `consume_monthly_amount` => `integer` / `consume_expires_at` => `immutable_datetime`）。`$fillable` は引き続き持たない（明示代入のみ）。`HasFactory` 追加 | `app/Models/Billing/TicketReservation.php` |
| `app/Services/Billing/TicketLedgerService.php` | 中核。private `computeSnapshot()` を単一 snapshot 源として新設し `balance()` を DTO 化 / `availableTrueBalance()` 追加 / `reserve()` に per-source 配賦と `consume_*` 固定を追加 / `commit()` を commit-wins + `TicketCommitResult` 化 / private `sumBucket()` `holdsBySource()` `nearestMonthlyExpiry()` `isExpiredMonthlyHold()` `assignConsumption()` 追加 / `insertIdempotent()` を `int`（挿入行数）返しへ | `TicketService.php:312-342`(balance) / `:349-453`(reserve) / `:465-588`(commit) / `:596-624`(失効述語) / `:1024-1043`(availableTrueBalance) / `:1045-1083`(sumBalance/countActiveReservations/nearestMonthlyExpiry) |
| `app/Http/Controllers/Billing/BillingController.php:63` | `'ticketBalance' => $tickets->balance($organization)->totalAvailable()`（props 形状は int のまま） | — |
| `app/Http/Controllers/Billing/TicketPurchaseController.php:66` | `balance:` へ `->totalAvailable()` | — |
| `app/Services/Dashboard/DashboardService.php:221` | `$balance = $this->tickets->balance($organization)->totalAvailable()`（`isLowBalance` 判定も同値） | — |
| `app/Services/Manual/AnalysisJobService.php:81` / `RenderJobService.php:90` | 入口 fail-fast の残高を判定値 `availableTrueBalance()` へ（表示 clamp を判定に使わない） | `TicketService.php:1024`「UI 表示には balance() を使うこと — 判定に使うと負残高で誤判定する」 |
| `app/Services/Manual/AnalysisPipeline.php:219-223` / `RenderPipeline.php:293-297` | commit の docblock/コメントを commit-wins へ更新（「非 Reserved は LogicException → rollback」を撤回）。**戻り値は分岐に使わない**（job 成否は既存 guard が決める） | `TicketService.php:465-478` |
| `database/factories/Billing/TicketReservationFactory.php` / `TicketLedgerEntryFactory.php`（新規） | 新規テスト用 Factory（手組み禁止）。state: `legacy()`(`consume_*`=null) / `monthlyHold()` / `purchasedHold()` / `monthlyGrant()` / `purchasedGrant()`。`docs/factories.md` へ追記 | — |

**移植しない判断（二重実装を作らない）**: `app/Enums/CreditSource.php` は移植せず既存
`App\Enums\Billing\TicketSource`（`monthly` / `purchased`）を使う（値・意味が 1:1。`plan_monthly` へ改名すると
`ticket_ledger_entries.source` 全行の書き換え = 台帳の置換になり additive 前提に反する）。`ensureSufficient()` /
`insertOrIgnore(encounter_id)` 冪等 reserve も移植しない（前者は 1 encounter=1 枚前提、後者は AI-CUE では job 行の
`ticketReservation()` 関連が冪等化を担う = `AnalysisPipeline.php:105-118`）。
`releaseStale()` は**変更しない**（AI-CUE の予約は monthly + purchased の混在がありうるため、monthly 失効を理由に行ごと
Released 化すると purchased 分の拘束まで解けオーバーセル窓が開く。aigenba が release 側で得ていた効果は、本設計では
hold 集計側の失効 monthly 除外が達成する）。

#### 波及変更

- **TypeScript 型定義**: **なし**。`resources/js/types/billing.ts:14`(`balance: number`) / `types/dashboard.ts:36`
  (`ticket_balance: number`) / `pages/Billing/Index.svelte:35`(`ticketBalance: number`) は int のまま維持する
  （per-bucket・`debt` の UI 露出は P8b 所管）。この props 形状不変が P5 の revert 安全性の根拠。
- **DTO・JsonResource**: 新規 `TicketBalanceDto`。既存 `PurchaseTicketsPageDto.balance`（`@phpstan-type` の `balance: int`）
  / `Dashboard/BillingSummaryData.ticketBalance` は**形状不変**（供給値の算出元のみ変更）。JsonResource は不使用（Inertia props）。
- **Inertia props**: `Billing/Index` の `ticketBalance` / `Billing/PurchaseTickets` の `page.balance` /
  `Dashboard` の `dashboard.billing.ticket_balance` — いずれもキー・型ともに不変。
- **テストファイル（更新対象・全列挙）**:
  `tests/Feature/Billing/TicketLedgerTest.php`(:26,:38,:43,:56,:61,:85-93,:95,:103,:115) /
  `tests/Feature/Billing/TicketGrantTest.php`(:28,:43,:58,:63,:79,:91,:101,:136,:145) /
  `tests/Feature/Billing/TicketRefundClawbackTest.php`(:142,:147) /
  `tests/Feature/Billing/TicketPurchaseWebhookTest.php`(:87,:106,:116,:131,:147,:163,:184,:202,:226,:256) /
  `tests/Feature/Billing/WebhookIdempotencyTest.php`(:94,:112,:123,:137,:150,:164,:214,:227,:280,:293) /
  `tests/Feature/Organization/InvitationTest.php:387` /
  `tests/Feature/Database/BughuntBillingSeederTest.php`(:50,:61,:83,:87) /
  `tests/Feature/Auth/RegistrationTest.php:29` / `tests/Feature/Auth/RegistrationInvitationPrefillTest.php:178` /
  `tests/Feature/Projects/AnalysisPipelineTest.php`(:166 + :294 近傍の不変条件コメント) /
  `tests/Feature/Manual/RenderPipelineTest.php`(:143,:164) / `tests/Feature/Manual/RenderStaleRecoveryTest.php:92` /
  `tests/Feature/Manual/RenderTriggerTest.php:254` / `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php`(:88,:117)。
- **変更なし（回帰確認のみ。grep で `balance()` / `reserve()` 非依存を確認済み）**:
  `tests/Feature/Billing/TicketVolumeTierTest.php`（volume tier 解決のみ）/ `tests/Feature/Billing/BillingPageTest.php` /
  `tests/Feature/DashboardTest.php`（props 形状不変）。
- **削除するテストは無い**（期待の更新のみ）。**ルート変更なし**。

#### 主要な契約

```php
/** 表示用の per-bucket 会計 (単一 snapshot の射影) */
public function balance(Organization $organization): TicketBalanceDto;
/** 与信・判定用の真値 (= balance()->totalAvailable()。常に 0 以上) */
public function availableTrueBalance(Organization $organization): int;
public function reserve(Organization $organization, int $amount): TicketReservation; // シグネチャ不変 (amount ベース維持)
public function commit(TicketReservation $reservation): TicketCommitResult;          // void → enum (commit-wins)
public function release(TicketReservation $reservation): void;                       // 不変 (非 Reserved は LogicException)
public function releaseStale(): int;                                                 // 不変

/** 単一 snapshot 源。balance() / availableTrueBalance() / reserve() / legacy 再配賦 / auto-recharge(P8a) が共有する */
private function computeSnapshot(
    Organization $organization,
    CarbonImmutable $now,
    ?int $excludeReservationId = null,   // commit 時の legacy 再配賦で自予約の hold を除くため
): TicketBalanceDto;
```

**DTO 形状**（aigenba verbatim + `debt`。PHP shape / TS shape / constructor / テスト fixture すべてに `debt` を反映）

```php
/**
 * @phpstan-type TicketBalanceShape array{
 *   monthlyRemaining: int, purchasedRemaining: int, debt: int,
 *   totalAvailable: int, activeReservations: int, nextExpireAt: string|null
 * }
 */
final readonly class TicketBalanceDto
{
    public function __construct(
        public int $monthlyRemaining,   // = monthlySpendable (hold + 債務控除後・非負)
        public int $purchasedRemaining, // = purchasedPositive (hold 控除後・非負)
        public int $debt,               // 正数の未回収債務 (返金過多由来)
        public int $activeReservations, // 拘束「枚数」= snapshot が控除した hold の合計 (aigenba は 1 枚固定のため count)
        public ?string $nextExpireAt,
    ) {}

    /** 債務控除後の非負値。両フィールドが非負のため常に 0 以上 */
    public function totalAvailable(): int
    {
        return $this->monthlyRemaining + $this->purchasedRemaining;
    }
}
```

`totalAvailable()` の本体だけは aigenba（`max(monthly + purchased - activeReservations, 0)`）と異なる。aigenba は
`monthlyRemaining` が raw で hold を後から引くのに対し、AI-CUE は hold が amount ベース = per-bucket に配分済みのため、
ここで再度引くと**二重控除**になる。hold が全て 1 枚なら両者は同値。

**正規数式（債務保全。clamp しない。grant 行の `delta` は書き換えない = 相殺は残高計算側で一度だけ）**

```text
debtAmount           = max(-purchasedRaw, 0)                       // raw ledger 負値から算出。hold と分離
monthlyPositive      = max(monthlyRaw   - monthlyHold,   0)
purchasedPositive    = max(purchasedRaw - purchasedHold, 0)
monthlySpendable     = max(monthlyPositive - debtAmount, 0)
availableTrueBalance = monthlySpendable + purchasedPositive
```

`debt` が monthly 側からのみ差し引かれるため二重回収は起きない（`purchasedRaw < 0` のとき `purchasedPositive` は
必ず 0）。purchased 付与で `purchasedRaw` が非負へ戻れば `debtAmount = 0` となり、債務は台帳合計の中で自然に一度だけ
回収される。monthly 付与だけでは `purchasedRaw` が動かないため債務は残り続ける（= monthly grant 失効後も未回収債務が残る）。

**バケット定義（台帳 backfill を不要にする中核）**
- `monthly` バケット = `source = 'monthly'` の未失効行（`expires_at IS NULL OR expires_at > now`）。
- `purchased` バケット = **`source = 'purchased' OR source IS NULL`** の未失効行。
  既存の消費行（`kind=reserve_commit` は `source=null`）・手動 `grant()`・`adjustment`・`release` の 0 行は
  無期限の負債/資産であり、無期限バケット（= purchased）と寿命特性が一致する。**この畳み込みにより台帳の backfill が
  一切不要**（純粋な per-source SUM にすると `source IS NULL` 行が両バケットから消え、過去消費が帳消しになる over-grant が起きる）。
- `monthlyRaw = SUM(monthly バケット delta)` / `purchasedRaw = SUM(purchased ∪ null バケット delta)`（clamp 前の生値）。
- `nextExpireAt` = `delta > 0 AND expires_at IS NOT NULL AND expires_at > now` の最小 `expires_at` の ISO8601
  （aigenba `:334-341` 同型。`amount` → `delta`）。

**hold（拘束）の per-source 集計** — `status = reserved` の行のみ。予約行は org あたり TTL 30 分の少数のため
PHP 側で畳む（混在予約は per-row の按分が要り COUNT に落ちないため、aigenba の query 版 `expiredMonthlyHoldCondition` は
移植せず PHP 述語 `isExpiredMonthlyHold` に一本化する。`whereNot` の 3 値論理事故も同時に消える）。

```text
各 Reserved 行 r について:
  m_r = r.consume_monthly_amount           // legacy (null) は下記 §legacy の仮配賦で決める
  p_r = r.amount - m_r
  isExpiredMonthlyHold(r) = m_r > 0 && boundary(r) !== null && boundary(r) <= now
    boundary(r) = r.consume_monthly_amount === null ? r.expires_at        // legacy: 予約 TTL を期間境界とみなす
                                                    : r.consume_expires_at // null = 無期限 monthly (失効しない)
  monthlyHold   += isExpiredMonthlyHold(r) ? 0 : m_r     // 失効 monthly hold は grant 自体が消えているため計上しない
  purchasedHold += p_r
activeReservations = monthlyHold + purchasedHold
```

**reserve TTL 切れでも Reserved である限り枠は保持する**（aigenba `:1061-1066` と同じく commit-wins と対称。
30 分超ジョブ中の同枠二重予約 = オーバーセルを防ぐ）。枠の解放は `releaseStale` の Released 化に委ねる。

**reserve（amount ベース配賦。既存 `lockOrganizationRow()` = org 行 `lockForUpdate` 下で評価する = 直列化点は不変）**

```text
$s = computeSnapshot($org, $now)                        // balance() と同一 snapshot
if ($s->totalAvailable() < $amount) → InsufficientTicketsException::forReserve($amount, $s->totalAvailable())
useMonthly   = min($amount, $s->monthlyRemaining)       // 消費優先 monthly → purchased (債務控除後)
usePurchased = $amount - useMonthly                     // totalAvailable >= amount より必ず <= purchasedRemaining
consume_monthly_amount = useMonthly
consume_source         = useMonthly > 0 ? TicketSource::Monthly : TicketSource::Purchased  // amount=1 で aigenba と一致
consume_expires_at     = useMonthly > 0 ? nearestMonthlyExpiry($org, $now) : null          // nullable。Assert しない
```

低残高クロス検知（`TicketLedgerService.php:269-280`）は `$balance` を `$s->totalAvailable()` に差し替えるのみ
（`$after = $s->totalAvailable() - $amount`。閾値クロスの意味論・通知回数は不変）。

**`consume_expires_at` を非 null 強制しない理由（aigenba との必然的な差。接地事実）**: aigenba は
`Assert::isInstanceOf($consumeExpiresAt, CarbonImmutable::class)` で monthly 予約に生きた期限を強制するが、これは
「monthly grant は必ず期限付き」という aigenba 固有の前提に依る。**AI-CUE の monthly grant は既定で無期限**
（`StripeWebhookProcessor.php:286-291` の `invoice.paid` 月次付与が `null` を渡し「期限運用は派生アプリの判断」と明記、
`BughuntBillingSeeder.php:63-68` も同様。期限付きは `grantSignupGrant()` の 30 日のみ）。この Assert を移植すると
**本番の monthly reserve が全て例外で落ちる**。よって `nearestMonthlyExpiry()` は nullable を返し、
`consume_monthly_amount > 0 && consume_expires_at IS NULL` は「無期限 monthly からの消費」を意味する
（legacy 行は `consume_monthly_amount IS NULL` で区別されるため曖昧にならない）。消費行に載る `expires_at` が
null になるのは monthly バケットに期限付き grant が 1 行も無いときだけなので、null-expiry の不滅ゴーストは発生しない。

**commit（commit-wins + per-source 2 行計上）**

```text
lockReservationRow($reservation, requireReserved: false)   // 行ロックは維持。status guard は撤去 (commit-wins)
status === Committed                        → TicketCommitResult::AlreadyCommitted   // 冪等 no-op
lockOrganizationRow($organization)
(m, p) = consume_monthly_amount !== null ? (cma, amount - cma) : assignConsumption(...)  // legacy は下記 §legacy で遅延固定
chargeMonthly = isExpiredMonthlyHold($locked, $now) ? 0 : m                              // 失効 monthly は課金しない
if (chargeMonthly + p === 0):
    status === Reserved なら Released 化 + Log::warning                                   // 台帳行を書かない
    → TicketCommitResult::ReleasedExpired
insertIdempotent(-chargeMonthly, source=monthly,   expires_at=consume_expires_at, key="consume:{$id}:monthly")   // >0 のみ
insertIdempotent(-p,             source=purchased, expires_at=null,               key="consume:{$id}:purchased") // >0 のみ
挿入 0 行なら Log::warning (既存 consume 行あり = 冪等だが不整合検知のため可観測化。aigenba :545-551 同型)
status === Reserved のときのみ Committed へ (Released は据え置き = 一方向遷移維持 + Log::info。課金の真実源は台帳)
→ TicketCommitResult::Committed
```

- **monthly 消費行に grant と同じ `expires_at` を載せる**のが精緻化の核心。バケット失効時に `+grant` と `−consume` が
  同時に合算から落ち、現行 docblock の「全額失効」近似が消える（aigenba `TicketService.php:520-533` 同型）。
- status guard 撤去で失われる二重課金防止は **`idempotency_key` UNIQUE（`consume:{id}:{source}`）が肩代わりする**
  （aigenba `consume:{encounterId}` と同型。既存 `ticket_ledger_entries.idempotency_key` UNIQUE をそのまま使う = 列追加なし）。
  `insertIdempotent()` は `kind` / `reservation_id` / `description` を含む任意属性を受ける既存実装のままで足り、
  可観測化のため戻り型のみ `void → int`（挿入行数）へ変える（既存呼び出し側は戻り値を捨てる）。
- 混在予約（monthly 3 + purchased 2）で monthly のみ失効 → purchased 2 のみ課金し `Committed`。
  aigenba の 3 値 enum は amount=1 で完全一致し、amount 一般化で自然に拡張される（**enum に case を足さない**）。

**既存 reserved 行（`consume_source` 未設定の旧予約）の扱い — 決定: 移行時に固定せず、commit 時に再配分する**

1. **migration での backfill を書かない**。理由: (a) Reserved 行は TTL 30 分で消える過渡的少数であり、正しく固定するには
   org ごとの残高大域計算が要る（無条件に monthly/purchased へ寄せると `max(…,0)` clamp と噛み合って over-grant =
   オーバーセルになる）、(b) デプロイ中の並行 reserve と競合する、(c) 誤配賦の固定は revert 不能だが、再配分は純粋関数で
   revert 可能（「旧コードは新列を無視する」という rollback 前提を保つ）。
2. **読み取り時（`computeSnapshot`）**: legacy hold（`consume_monthly_amount IS NULL`）を id 昇順に走査し、
   消費優先順と同じ規則で仮配賦する（`m_r = min(r.amount, max(monthlyRaw − monthlyHold, 0))`、残りを `p_r` へ）。
   **控除は合計 1 回のみ**（二重控除 = ユーザー不利を作らない）。純粋計算で、行は書き換えない。
3. **commit 時（遅延固定）**: org 行ロック下で `computeSnapshot($org, $now, excludeReservationId: $locked->id)` を取り、
   **自予約の hold を除いた** availability に対し `reserve` と同一規則（`assignConsumption`）で `(m, p)` を確定し、
   予約行へ `consume_*` を書き込んでから課金する。monthly の期限は `nearestMonthlyExpiry()` を採用する。
   再配分時に monthly grant が失効していれば生存分のみ課金し、`chargeable = 0` なら `ReleasedExpired`。
   なお additive 原則が禁じるのは**台帳行・既存データの書き換え**であり、予約行の新規 nullable 列を確定させる書き込みは
   これに該当しない（予約行は元より `status` を Service が更新する状態行）。

**DB 列 / index**

```text
ticket_reservations:
  consume_source          string       nullable  // monthly|purchased (App\Enums\Billing\TicketSource)。null = legacy
  consume_expires_at      timestamp    nullable  // monthly 分の失効境界。null = 無期限 monthly または legacy
  consume_monthly_amount  unsignedInt  nullable  // monthly から拘束した枚数。purchased 分 = amount − 本列。null = legacy
```

**新規 index は追加しない**: hold 集計は `where(organization_id, status)` = 既存 `['organization_id','status']` で覆われ、
`releaseStale` は既存 `['status','expires_at']` を使う。`ticket_ledger_entries` は**列追加ゼロ**
（`source` / `expires_at` / `idempotency_key` は既存）。**ルート変更なし**。

#### PHPStan 適合チェック

- 戻り型を明示: `balance(): TicketBalanceDto` / `availableTrueBalance(): int` / `commit(): TicketCommitResult` /
  `computeSnapshot(): TicketBalanceDto` / `insertIdempotent(): int`。`commit` の呼び出し側
  （`AnalysisPipeline:223` / `RenderPipeline:297`）は戻り値を捨てる（level 10 は未使用戻り値を咎めない）。
- `TicketBalanceDto` は `final readonly` + `@phpstan-type TicketBalanceShape`（`debt: int` を含む）+
  `toArray(): TicketBalanceShape`。`PurchaseTicketsPageDto` の `@phpstan-type` は `balance: int` のまま（形状不変）。
- `->sum('delta')` は mixed → 既存踏襲で `(int)` キャスト。`->value('expires_at')` は mixed →
  `$v instanceof CarbonImmutable ? $v : ($v instanceof Carbon ? CarbonImmutable::instance($v) : null)` で null 安全に絞る
  （AI-CUE は `immutable_datetime` cast のため `CarbonImmutable` 側を先に判定する。aigenba は `Carbon` 判定）。
- `consume_monthly_amount` は `?int`。`$locked->consume_monthly_amount ?? $this->assignConsumption($locked, $snapshot)` で
  null 合体してから `int` に確定させ、以降 null を伝播させない。`consume_expires_at` は `?CarbonImmutable` のまま扱い、
  **`Assert::isInstanceOf` で非 null を強制しない**（AI-CUE の monthly grant は無期限が既定のため。前述）。
- `TicketReservation` へ `@property ?TicketSource $consume_source` / `@property ?int $consume_monthly_amount` /
  `@property ?CarbonImmutable $consume_expires_at` を追加。`casts(): array<string, string>` の既存戻り型に適合。
- hold の畳み込みは `Collection<int, TicketReservation>` を `@var` で確定させてから foreach（`->get()` の mixed 漏れを防ぐ）。
- Factory は `/** @extends Factory<TicketReservation> */` + `definition(): array<string, mixed>`、Model へ
  `/** @use HasFactory<TicketReservationFactory> */`。
- `TicketCommitResult` は純粋 enum。呼び出し側で分岐しないため `match` の網羅義務は発生しない。
- **型の widen・baseline 化は行わない**（禁止事項 2）。

#### テスト計画

**先に red を作る新規テスト**

1. `tests/Feature/Billing/TicketBalanceAccountingTest.php`
   - monthly grant +10（期限あり）→ reserve/commit −3 → 期限到達で **grant と消費行が同時に落ち** `monthlyRemaining = 0`
     （現行実装なら `-3` が残るため red）。
   - 消費優先: monthly 4 / purchased 10 で `reserve(5)` → `consume_monthly_amount = 4`・`consume_source = monthly`・
     purchased 拘束 1。commit で `source=monthly:-4` と `source=purchased:-1` の 2 行。
   - `source IS NULL` の legacy 消費行が **purchased バケットへ畳まれる**（帳消しにならない）。
   - `nextExpireAt` が最短の未失効・正 delta の ISO8601。`activeReservations` が拘束**枚数**（hold 合計）。
   - **無期限 monthly grant のみの org で `reserve()` が例外にならず** `consume_expires_at = null` で固定される
     （`invoice.paid` の本番経路 = `grantMonthly(..., null, ...)` の回帰。aigenba の Assert を移植した場合の red）。
2. `tests/Feature/Billing/TicketDebtAccountingTest.php`（**金銭 invariant / 最重要**）
   - **`monthly = 10` / `debt = 2` で `reserve(8)` 成功・`reserve(9)` が `InsufficientTicketsException`**。
   - `balance()` が `monthlyRemaining = 8` / `purchasedRemaining = 0` / `debt = 2`（正数）/ `totalAvailable() = 8` を返す。
   - **債務が一度だけ回収される**（各 grant 経路）: `grantPurchased` / `grantMonthly` / `grantSignupGrant` を
     債務 `-2` の org へ適用し、回収が 1 回だけ効くこと（purchased 付与のみが `debtAmount` を消し、monthly 付与では
     債務が残ったまま `monthlySpendable` から 1 回だけ引かれること）。auto-recharge 付与は下位実装が `grantPurchased` の
     ため本テストで同経路を覆い、**auto-recharge 経由の E2E 回帰は P8a の DoD に置く**。
   - **monthly grant 失効後も未回収債務が残る**（monthly 失効 → `totalAvailable() = 0` かつ `debt = 2` を維持）。
   - `totalAvailable()` が常に非負である一方、台帳の負値（`debt`）は消えない（clamp しない）。
3. `tests/Feature/Billing/TicketBalanceMonotonicityTest.php`（**invariant**）
   - 旧式 oracle（`SUM(未失効 delta) − SUM(reserved.amount)`）をテスト内に実装し、代表シナリオ集合
     （失効あり/なし × 混在予約 × clawback × legacy 消費行 × legacy 予約）で **`availableTrueBalance() >= max(oracle, 0)`** を検証
     （**`>= oracle` のままだと oracle が負のとき自動的に通ってしまい単調性を検証できない**。Codex Round 5 Warning）。
   - 併せて **`debt` が `max(-purchasedRaw, 0)` と一致する独立アサート**を置く（単調性テストとは別に、債務額そのものを固定する）
     = **精緻化がユーザー不利に動かない**（現行は保守的近似 = 過小評価）。
4. `tests/Feature/Billing/TicketCommitWinsTest.php`
   - TTL 切れで `releaseStale` に Released 化された生存予約の commit → **課金され `Committed`**（status は Released 据え置き）。
   - 再 commit → `AlreadyCommitted` かつ台帳は 1 組のみ（`consume:{id}:{source}` UNIQUE）。
   - 全額 monthly 予約の `consume_expires_at` 経過 → `ReleasedExpired`・台帳行ゼロ・status Released。
   - 混在予約で monthly のみ失効 → purchased 分のみ課金 + `Committed`。
5. `tests/Feature/Billing/TicketLegacyReservationTest.php`
   - Factory `legacy()` の Reserved 行（`consume_*` = null）に対し、`balance()` の仮配賦が**合計 1 回だけ控除**すること
     （monthly 先・溢れは purchased）。
   - commit 時再配分で `consume_*` が確定し per-source 台帳が書かれること。再配分時に monthly が失効済みなら生存分のみ課金。
   - legacy 行の自予約 hold が再配分の availability から除外され、自分自身の hold で不足判定にならないこと。
6. `tests/Feature/Billing/TicketAmountBasedReserveTest.php`（**AGENTS.md #7 / ドメイン境界の回帰**）
   - `reserve($org, 5)` が amount=5 の予約 1 行を作る（1 枚固定に退化していない）。
   - `config('manual.analysis_ticket_cost') !== config('manual.render_ticket_cost')` の可変コストで解析/レンダが完走する。
   - reserve→commit / reserve→release の 2 フェーズが残っている（直接デクリメントが無い = 台帳 append-only）。

**既存テストの更新（削除しない。期待値の実質変更は下記のみで、他は API 差し替え + 期待不変の回帰網）**

- `tests/Feature/Billing/TicketLedgerTest.php`: `balance()->toBe(int)` を `balance()->totalAvailable()` へ
  （:26,:38,:43,:56,:61,:95,:103,:115）。**:85-93「committed / released の予約は再 commit / 再 release できない」は
  commit-wins へ期待更新** — 再 commit は `AlreadyCommitted`（台帳は 1 組のみ・残高 7 のまま）、released 予約の commit は
  課金 + `Committed`、**再 release は引き続き `LogicException`**（release の意味論は変えない）。:98-116 `releaseStale` は不変。
- `tests/Feature/Billing/TicketRefundClawbackTest.php`: :142 は API 差し替えのみ。**:147 の `-2` 期待は維持** —
  `purchasedNet($organization)`（同ファイル :42 のヘルパ = `source=purchased` の台帳純額）が `-2` のままであること、
  `balance($organization)->debt` が `2`（DTO 境界では正数）、`totalAvailable()` が `0`、直後の `reserve(1)` が
  `InsufficientTicketsException` であることを検証する（債務保全 = clamp で消さない）。
  なお P5 後は消費行にも `source` が載るため、`purchasedNet` は消費 `-2` を含んで同じく `-2` になる。
- `tests/Feature/Billing/TicketGrantTest.php`（:28,:43,:58,:63,:79,:91,:101,:136,:145）: `balance()` の戻り値変更に伴う
  API 差し替え。**期待値は不変**（:63 の「期限付き 10 が失効し無期限 5 が残る」= 両行とも monthly バケット、
  :101 の「signup grant が期限到達で 0」= monthly バケットの失効。per-bucket 化後も同値であることを同時に検証する）。
- `tests/Feature/Billing/TicketPurchaseWebhookTest.php`（:87,:106,:116,:131,:147,:163,:184,:202,:226,:256）/
  `tests/Feature/Billing/WebhookIdempotencyTest.php`（:94,:112,:123,:137,:150,:164,:214,:227,:280,:293）/
  `tests/Feature/Organization/InvitationTest.php:387` / `tests/Feature/Database/BughuntBillingSeederTest.php`（:50,:61,:83,:87）/
  `tests/Feature/Auth/{RegistrationTest.php:29,RegistrationInvitationPrefillTest.php:178}` /
  `tests/Feature/Projects/AnalysisPipelineTest.php:166` /
  `tests/Feature/Manual/{RenderPipelineTest.php:143,:164, RenderStaleRecoveryTest.php:92, RenderTriggerTest.php:254}` /
  `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php`（:88,:117）:
  `->balance($org)` → `->balance($org)->totalAvailable()` の API 差し替え。**期待値が不変であることを同時に検証する
  （= 回帰の網）**。低残高通知はクロス判定・通知回数とも不変。
- `tests/Feature/Projects/AnalysisPipelineTest.php:294` 近傍の不変条件記述「succeeded ∧ released の非共存」を
  **「succeeded ∧ 無課金の非共存」へ読み替え更新**（commit-wins は Released 据え置きのまま課金するため、守るべきは
  「成果物を渡して無課金 = タダ乗り」と「失敗して課金」の排除であり、これは強化される）。
- `tests/Feature/Billing/{TicketVolumeTierTest,BillingPageTest}.php` / `tests/Feature/DashboardTest.php`: **更新なし・
  回帰確認のみ**（grep 済み: `balance()` / `reserve()` を呼ばず props 形状も不変）。
- テストデータは Factory 生成（手組み `new TicketReservation` を書かない）。`RefreshDatabase` グローバル・`--parallel` 前提を
  維持（個別 `DatabaseTransactions` を足さない）。

#### リスク

| リスク | 緩和 |
|---|---|
| **無期限 monthly grant が本番の既定経路**（`invoice.paid` は `expiresAt=null`）。aigenba の `Assert::isInstanceOf(consumeExpiresAt)` を移植すると全 monthly reserve が例外で落ちる | `nearestMonthlyExpiry()` を nullable のまま扱い Assert を移植しない。「無期限 monthly 消費」を新規テスト #1 の必須ケースに置き、legacy 行とは `consume_monthly_amount IS NULL` で区別する |
| **commit-wins により「succeeded ∧ released」が成立し得る**（status 据え置き・課金は台帳が真実源） | 既存 guard（`AnalysisPipeline:202` の job status 検査）が cron 先勝ちケースを先に弾くため、実際に到達するのは「TTL 切れだが Running」= 成果物を渡す正当な課金ケースのみ。不変条件記述を更新し `Log::info` で可観測化（aigenba `:568-574` 同型） |
| **reserve TTL 30 分 < 長時間レンダ**。`releaseStale` が Running 中の予約を解放 → 解放枠が別 reserve に取られ、後で commit-wins が課金 → 一時的オーバーセル | aigenba と同じ既知窓。hold 側で TTL 切れを除外しない（枠を保持する）ことで窓を `releaseStale` の実行間隔（5 分 cron）に限定する。TTL 方針は現状維持（P5 のスコープを会計精緻化に閉じる） |
| **混在予約の `consume_expires_at` は最短 monthly 期限を採用**（`nearestMonthlyExpiry`）。実際には別の grant から消費していた場合に消費行が早く落ち残高が過大 | aigenba `:411-421` と同一近似。FIFO（最短期限先消費）が実装上の前提なので通常一致。over-grant 方向だが単調性 invariant（テスト #3）には適合。`ReleasedExpired` の `Log::warning` で観測 |
| commit の status guard 撤去で二重課金 | `idempotency_key` UNIQUE（`consume:{id}:{source}`）+ org 行ロックで DB 保証。`insertIdempotent` の挿入 0 行を `Log::warning` で可観測化（aigenba `:545-551` 同型） |
| **debt の回収漏れ / 二重回収** | 相殺を残高計算側の 1 箇所（`computeSnapshot`）に閉じ、grant 行の `delta` を書き換えない。`debt` は monthly 側からのみ控除し、`purchasedRaw < 0` のとき `purchasedPositive = 0` となるため二重には引かれない。テスト #2 が全 grant 経路を機械検証する |
| legacy 予約の仮配賦バグ | 影響はデプロイ後 TTL 30 分の Reserved 行のみ。読み取りは純粋計算（行を書き換えない）で revert 安全。専用テスト #5 で覆う |
| 呼び出し側 5 箇所（`BillingController:63` / `TicketPurchaseController:66` / `DashboardService:221` / `AnalysisJobService:81` / `RenderJobService:90`）の取りこぼし | `int → TicketBalanceDto` のシグネチャ変更で PHPStan level 10 が全箇所を機械検出する |
| revert 可能性 | additive 列 + 読み取り計算 + props 形状不変。旧コードは `consume_*` 列と `consume:*` 台帳行を無視するだけ（台帳の置換・二重書き・差分再同期は無い） |

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

### P8b: 課金 UI parity（Guest/Pricing 配置 + Billing/Plans + PlanCard + PurchaseTickets 状態機械 + Index 情報密度）+ 監査「判断不要 15 件」の消化

前提: P1〜P7 と P8a はマージ済み（`PlanCode`(Personal/Starter/Standard) / `plans.is_active` / `BillingAccess::effectivePlan()` の 4 variant / per-bucket `TicketBalanceDto`(debt 付き) / `?plan=` handoff / AutoRecharge が既に存在する）。本フェーズは **UI 層と、それを支える Controller / DTO / 露出制御のみ**を触る。会計 (`TicketLedgerService`) と Stripe 境界 (`*Gateway`) には手を入れない（監査 ticket-charge-11 の 4 分割境界を維持）。

**所管の境界**: **billing contact（列 / `BillingContactForm.svelte` / `billingContact` props / 更新 Action）・checkout 着地 feedback（`feedback` バナー / `resolveBillingFeedback`）・subscription checkout 用 attempt token（`subscriptionAttemptToken`）は P9 所管であり、本フェーズに一切登場しない**。一方 **チケット決済の attempt token（`ticketAttemptToken`）は既存の冪等マシンに必要なため維持・安定化する**（別物として扱う）。

##### 監査「A. 判断不要 = 機械的に aigenba へ寄せられる (15 件)」の消化台帳

| # | finding | 本フェーズでの対応 |
|---|---|---|
| 1 | registration-funnel-7 `EmailVerificationContinuation` | **P7 で完了**。P8b では触らない。 |
| 2 | registration-funnel-11 `OnboardingReturnResolver` | **P7 で完了**。P8b では触らない。 |
| 3 | registration-funnel-14 オンボ外枠の T071 primitive 整合 | **P3 で完了**（`Onboarding/{Checkout,BillingRequired}.svelte`）。P8b は同じ規約を新設 `Billing/Plans.svelte` に適用し、aigenba の `PageHeaderSection` + breadcrumbs / `max-w-6xl` 直書きは移植しない。 |
| 4 | pricing-plans-6 `Billing/_helpers/PlanCard.svelte` | **P8b で実施**（下記 (a)）。移植元 `/tmp/aigenba/resources/js/pages/Billing/_helpers/PlanCard.svelte`。 |
| 5 | pricing-plans-7 `PricingPlanCard` の headerBadges / contactLabel | **headerBadges snippet は P8b で追加**（Billing 側「現在のプラン」バッジに必要）。**contactLabel は追加しない** — AI-CUE は enterprise を Plan 行として持たず（`PlanSeeder` の 3 プラン = personal/starter/standard）、監査 action 自体が「enterprise プラン採否に連動」と条件付き。既存コメント（`/workspace/resources/js/components/molecules/PricingPlanCard.svelte:7-8`「大規模利用はカード外バナーの責務」）を維持し、Guest/Pricing の enterprise バナーを正とする。 |
| 6 | billing-subscription-4 `SubscriptionService` / `SubscriptionSnapshot` | **P2 で完了**。P8b は `BillingAccess::effectivePlan()` の返す `EffectivePlan` DTO を読むだけで、raw column 分岐（`plan_code` / `free_plan_code` の直参照）を書かない。 |
| 7 | billing-subscription-6 料金プラン画面 `Billing/Plans` | **P8b で実施**（下記 (a)）。 |
| 8 | billing-subscription-7 サブスク checkout の冪等・着地 feedback | **P9 へ移譲**。`BillingCheckoutSession` 相当 / `resolveBillingFeedback` / `BillingFeedbackShape` / `subscriptionAttemptToken` は P2 の非スコープで存在せず、P8b には生成根拠が無い。**`Billing/Plans` の POST body は `{plan_code}` のみ**とする。 |
| 9 | billing-subscription-10 `BillingCustomerSynchronizer` / 請求先情報 | **P9 へ移譲**（列 + 更新 Action + 同期 job + `BillingContactForm.svelte` + `billingContact` prop の全体）。P8b は請求先情報を扱わない。 |
| 10 | billing-subscription-11 Customer Portal の事前ガード | **P8b で実施**（下記 (d)）。 |
| 11 | billing-subscription-14 `Billing/Index` の構造と情報密度 | **P8b で実施**（下記 (e)）。外枠は既に T071 準拠のため是正不要。プラン一覧を `Billing/Plans` へ移設し、Index を請求ダッシュボード（effectivePlan / per-bucket 残高 / quota / 導線）へ寄せる。auto-recharge 設定カードは **P8a 所管**（P8b は差し込み位置 `#auto-recharge` anchor のみ用意）。 |
| 12 | billing-subscription-15 `billing-required` 画面 | **P3 で完了**。P8b では触らない。 |
| 13 | ticket-charge-5 購入フォームの状態機械 + attempt_token 安定化 | **P8b で実施**（下記 (c)）。対象は **`ticketAttemptToken`**（チケット決済の既存冪等キー）であり、P9 の `subscriptionAttemptToken` とは別物。 |
| 14 | ticket-charge-8 spot 単価の出典 | **合わせない（対応不要）**。監査 action の宿題「production の livemode/synced_at 必須チェックが AI-CUE にあるか」は実コードで確認済み = 有り（`/workspace/app/Models/Billing/TicketVolumePrice.php:91-96`、`app()->environment('production')` 下で `Assert::true($row->livemode && $row->synced_at !== null)`）。単一テーブル集約を維持。 |
| 15 | ticket-charge-11 サービス分割構造 | **合わせない（逆行しない）**。P8b は 4 分割境界を守る: 会計 = `TicketLedgerService`、導線 / 状態 = Controller + DTO、Stripe = `*Gateway`。`resolveResumablePurchase` は冪等 Checkout マシンの一部として `TicketCheckoutService` に置く。 |

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

**(a) プラン提示の専用ページ化（bs-6 / pp-6 / pp-7）**

- 新規 `/workspace/resources/js/pages/Billing/Plans.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/Plans.svelte`
  - `AppLayout > PageContainer > PageHeader > PageContent`（T071 primitive）。aigenba の `PageHeaderSection` + breadcrumbs は採らない（breadcrumbs 有無はサイト共通ナビ方針 = 監査 ticket-charge-7 の別判断）。
  - `data-testid="plans-grid"` に `PlanCard` を並べる。確認ダイアログは **AI-CUE 既存の `organisms/ConfirmDialog.svelte`**（aigenba の inline `Modal` + `@confirm-modal` selector は aigenba 側 browser test 都合の負債であり移植しない）。
  - 送信は既存 `POST /billing/checkout` のみ、**body は `{plan_code}` のみ**（subscription checkout 用 attempt token は P9 成果物）。`plan-change` / `upgrade-now` / `earlyUpgradePlanCodes` / `pendingPlanCode` は実装しない（監査 pricing-plans-9 / bs-5 = 要プロダクト判断）。
  - サーバ validation エラー（`errors.plan_code`）は ConfirmDialog 内に `Alert` で描画し、成功時のみ閉じる。
- 新規 `/workspace/resources/js/pages/Billing/_helpers/PlanCard.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/_helpers/PlanCard.svelte`
  - page-local adapter 規約（`Billing/Plans` 以外から import しない）をコメントごと踏襲。`PricingPlanCard` 分子に委譲。
  - **移植する**: `isCurrent`（headerBadges「現在のプラン」）/ `canSwitch` / `switchBlockedReason`（aigenba の `disabledReason` に相当）/ features 組み立て / `formatYen` / `formatLimit`。
  - **移植しない（データ源が AI-CUE に無い）**: `includedSeats` / `currentSeatAmount`（席課金 = スコープ外）、`isPending`（変更予約）、`isStarter` / `starterMigrationText`（Starter 自動移行）、`isEnterprise` / contact CTA（enterprise Plan 行なし）。features は AI-CUE の Plan 台帳（`monthlyTicketGrant` / `maxProjects` / `maxMembers` / `maxStorageGb`）で組む（`/workspace/resources/js/pages/Pricing.svelte:28-38` の `buildFeatures` と同一出典）。
  - **D4 適合**: aigenba は「変更不可」を `disabled` ボタン + `title` / `aria-label` で表現するが、**AGENTS.md 禁止事項 #8 / DESIGN.md L399-401 が parity に優先する**。`canSwitch=false` でも CTA は **enabled のまま描画**し、押下時に `switchBlockedReason` を Alert（`data-testid="plan-switch-blocked"`）で表示する。理由は同時にカード内 caption としても常時可視にする（情報は失わない）。`disabled` 属性は使わない。
- 変更 `/workspace/resources/js/components/molecules/PricingPlanCard.svelte`: `headerBadges?: Snippet` を追加し `<h3>` 行を `flex items-center justify-between` へ（← `/tmp/aigenba/resources/js/components/molecules/PricingPlanCard.svelte:29,:56-59`）。`contactLabel` は追加しない。既定未指定時の出力は不変。
- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php`: `plans()` を新設（← aigenba `BillingController::plans()`）。`index()` からプラン一覧構築（:44-63）を移す。
- 変更 `/workspace/routes/web.php`: `Route::get('/billing/plans', [BillingController::class, 'plans'])->name('billing.plans');` を billing 群（課金ゲート allowlist 内・**route parameter を持たない current-org スコープ**）へ追加。

**(b) **Starter のみ**の再公開（**Personal は P3 で公開済み**。D10）（D10 の解除 / D11 の完了）**

- 新規 `/workspace/database/migrations/2026_07_17_000800_republish_personal_starter_plans.php`（data migration）
  - 手順: (1) `plans` に **`code='starter'`** の行が存在することを**事前検証**し、無ければ **fail-closed**（黙って no-op しない）。(2) **`code='starter'` のみ**を `is_active=true` へ更新。(3) 末尾で `code='starter' AND is_active=true` が 1 件であることを検証。**(4) `code='personal' AND is_active=true` が 1 件であることも検証する（P3 で公開済みの前提を守る。ここでは更新しない）**。**`down()` は `code='starter'` のみを false へ戻す（Personal は絶対に触らない）**
  - `down()`: **`code='starter'` の 1 行のみ**を `is_active=false` へ戻す（**`personal` は絶対に触らない** — P4 後に本 migration だけを rollback すると無料導線が消えて F-07 変種が再発するため）。本 migration は config を触らないため `remove_free_plan_row` のような運用手順の分離は不要
- 変更 `/workspace/database/seeders/PlanSeeder.php`: **`starter` のみ** `is_active` を `false` → **`true`** へ（`personal` は **P3 の migration で既に true**。P8b の seeder/migration/`down()` は **Personal を一切触らない** — **P4 後に P8b だけを rollback すると無料導線が消えて F-07 変種が再発するため**。Codex Round 7 Critical）
- 露出制御そのもの（`PricingService::listPublicPlans()` の `where('is_active', true)`）は **P1 実装のまま変更しない**。P8b はデータ側の値だけを切り替える。

**(c) 購入画面: per-bucket 残高 + 状態機械 + ticketAttemptToken 安定化（tc-5 / tc-2 の表示面）**

- 新規 `/workspace/resources/js/pages/Billing/ticketCount.ts` ← `/tmp/aigenba/resources/js/pages/Billing/ticketCount.ts`（`parseTicketCount` を verbatim 移植。`^-?\d+$` の符号付き整数のみ許容、clamp / floor しない）。`/workspace/resources/js/pages/Billing/PurchaseTickets.svelte:43-48` のインライン正規表現を置換。
- 変更 `/workspace/resources/js/pages/Billing/PurchaseTickets.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/PurchaseTickets.svelte`
  - 残高カードを **per-bucket 表示**へ: 合計（`totalAvailable`）/ プラン付与残（`monthlyRemaining`・有効期限あり）/ 購入済み残（`purchasedRemaining`）/ **未回収の債務（`debt` > 0 のときのみ `data-testid="balance-debt"`）** / 次の失効（`balance-next-expire`）。出典は P5 の `TicketBalanceDto` のみ（画面で再計算しない）。
  - `formState` に応じて出し分け: `normal` = 従来の購入フォーム / `resume` = 進行中バナー + 「決済を続ける」（`resumeUrl` へ `window.location.href`）+ 「新しく購入し直す」（`newPurchaseUrl` = `?fresh=1`）/ `completed` = 完了バナー + 「もう一度購入する」（`newPurchaseUrl`）。**resume / completed では購入フォームを描画せず、`boundCount` を読み取りテキストとして表示**する（入力欄・ボタンを `disabled` にしない = 禁止事項 #8）。
  - **単位は「枚」を維持**（aigenba の「回」は移植しない = 監査 ticket-charge-10。AI-CUE の可変コスト消費という製品語彙）。per-bucket ラベルは aigenba の意味（プラン付与残 / 購入済み残 / 次の失効）を「枚」語彙で表現する。
  - 既存の「購入したチケットに有効期限はありません」は **purchased バケツの説明として「購入済み残」直下へ移す**（月次 / signup grant 分と誤読されない位置。tc-10 の誤読リスク解消）。
  - submit ボタンは `disabled` にしない（既存 `/workspace/tests/js/pages/PurchaseTickets.test.ts:86` の契約を維持。aigenba の `disabled={submitting || countError !== null}` は移植しない）。
  - POST body は既存契約どおり `{count, attempt_token}`（サーバ側 `TicketCheckoutRequest` の field 名は不変。props 名のみ `ticketAttemptToken` へ）。
- 変更 `/workspace/app/Http/Controllers/Billing/TicketPurchaseController.php:47-70` ← aigenba `BillingController::showPurchase()`
  - `attemptToken: (string) Str::ulid()` の毎 render 発行をやめ、**`canManage && ! $request->boolean('fresh')` のときのみ** `TicketCheckoutService::resolveResumablePurchase()` 由来の token を再利用（既存の replay 冪等がブラウザバック / bfcache で効くようになる）。
  - **非管理者には resume / completed を出さない**（`resumeUrl` は Stripe 直リンクで purchase gate を迂回する）。`canManage=false` は常に `normal` + fresh token へ縮退。
  - `balance:` を `int` から `TicketLedgerService::balance()` の `TicketBalanceDto` へ差し替える。
- 変更 `/workspace/app/Services/Billing/TicketCheckoutService.php`: `resolveResumablePurchase()` を追加（← aigenba `TicketService.php:1393-1417`）。会計には触れない。
- 変更 `/workspace/config/billing.php`: `purchase_resume_window_minutes`（既定 30）を追加。

**(d) Customer Portal の事前ガード（bs-11）**

- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php:98-104` ← aigenba `BillingController::redirectToPortal()`
  - `Gate::authorize('manageBilling')` の後、**`$organization->stripe_id !== null` かつ `effectivePlan()` が `PaidSubscriptionPlan`** を事前確認し、不成立なら `back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。')`。Cashier `ManagesCustomer::billingPortalUrl()` の `assertCustomerExists()`（例外 = 500）に到達させない。
- 変更 `/workspace/resources/js/pages/Billing/Index.svelte:121`: `{#if canManageBilling}` を `{#if canManageBilling && canOpenPortal}` へ（サーバ判定を prop で受け、UI 側で契約状態を再解釈しない）。

**(e) Billing/Index の情報密度（bs-14）**

- 変更 `/workspace/resources/js/pages/Billing/Index.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/Index.svelte`
  - `data-testid="plan-list"` のインラインプラン一覧（:139-171）と page-local `formatPrice` / `startCheckout` を**撤去**し、「プラン比較」導線（`billing.plans`・`data-testid="billing-plans-link"`）へ置換。
  - 現在プラン表示を **`effectivePlan`（DTO）由来**へ（`currentPlanCode` scalar での再判定をしない）。`kind` に応じた文言（有償プラン名 / Personal（無料） / 無料プラン（移行）/ 未契約）をサーバ提供の `currentPlanName` + `kind` で描画する。
  - 残高表示を per-bucket（P5 `TicketBalanceDto`。`debt` > 0 のときのみ債務行）へ。
  - quota 表示（`maxProjects` / `maxMembers` / `maxStorageGb` の現行値）を追加。
  - `#auto-recharge` anchor を追加（P8a の設定カードの差し込み位置。`?highlight=auto-recharge` の着地先）。カード実体は P8a 所管。
  - **feedback バナー / 請求先フォームは追加しない**（P9 所管）。

**(f) guest pricing の配置（pp-8 の配置部分のみ）**

- 移動 `/workspace/resources/js/pages/Pricing.svelte` → `/workspace/resources/js/pages/Guest/Pricing.svelte`（← `/tmp/aigenba/resources/js/pages/Guest/Pricing.svelte` の配置）。中身は不変（(b) により personal / starter が自動で 3 枚並ぶ）。
- 変更 `/workspace/app/Http/Controllers/Marketing/PricingController.php:73`: `Inertia::render('Pricing', …)` → `Inertia::render('Guest/Pricing', …)`。**route path `/pricing`・route 名 `pricing`・SEO メタは不変**。
- **三層構成（personal banner / corporate grid / enterprise banner）と `?plan=` CTA は本フェーズで入れない**（前者は監査 pricing-plans-8 = 要プロダクト判断、後者は P7 所管）。

#### 波及変更

**TypeScript 型定義**

- `/workspace/resources/js/types/billing.ts`:
  - 再利用 `TicketBalanceShape`（P5 が同ファイルへ追加済み。`debt: number` を含む）。`EffectivePlanShape`（P2 が追加済み）。
  - `PurchaseTicketsPageProps`: `balance: number` → `balance: TicketBalanceShape`、`attemptToken` → **`ticketAttemptToken`**、追加 `formState: 'normal'|'resume'|'completed'` / `boundCount: number | null` / `resumeUrl: string | null` / `newPurchaseUrl: string`。
  - 追加 `BillingPlanShape` / `BillingPlansPageProps` / `BillingIndexPageProps`。
- `/workspace/resources/js/components/molecules/PricingPlanCard.svelte` の `Props` に `headerBadges?: Snippet`。
- `/workspace/resources/js/types/marketing.ts`: 変更なし（Guest/Pricing は配置移動のみ）。

**DTO / JsonResource**

- 新規 `/workspace/app/DataTransferObjects/Billing/BillingPlanDto.php`（`@phpstan-type BillingPlanShape`）。
- 新規 `/workspace/app/DataTransferObjects/Billing/BillingPlansPageDto.php`（`@phpstan-type BillingPlansPageShape`）。
- 新規 `/workspace/app/DataTransferObjects/Billing/BillingDashboardDto.php`（Index。現行 4 props の array 直書き `BillingController::index():60-65` を DTO 化 = 禁止事項 4 の遵守）。
- 変更 `/workspace/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php`（`balance: int` → `TicketBalanceDto`、`attemptToken` → `ticketAttemptToken`、`formState` / `boundCount` / `resumeUrl` / `newPurchaseUrl` 追加。PHP shape / TS shape / constructor / テスト fixture を同時更新）。
- 新規 `/workspace/app/Enums/Billing/PurchaseFormState.php`（`Normal|Resume|Completed`。← aigenba `App\Enums\Billing\PurchaseFormState`）。
- JsonResource: 追加なし（本フェーズは Inertia のみ）。

**Inertia props**

- `Billing/Index`: 4 props → `['page' => BillingDashboardDto::toArray()]` 1 本（PurchaseTickets / Pricing と同じ `page` 規約に揃える）。
- `Billing/Plans`: 新規 `['page' => BillingPlansPageDto::toArray()]`。
- `Billing/PurchaseTickets`: `page` の shape 拡張。
- `Pricing` → `Guest/Pricing`: component 名のみ変更（props 不変）。

**テストファイル**

- 更新: `/workspace/tests/Feature/Billing/BillingPageTest.php`（:17 プラン一覧の期待を Plans へ移設 / :38 / :50 / :60 / :87 / :93 / :106 の props 名追随・portal 期待）
- 更新: `/workspace/tests/Feature/Billing/TicketCheckoutTest.php`（安定 token 経由の replay 期待を追加）
- 更新: `/workspace/tests/Feature/Billing/PortalConfigurationTest.php`（事前ガード導入後の到達条件）
- 更新: `/workspace/tests/Feature/Marketing/PricingPageTest.php`（**Starter 再公開**により **2 枚 → 3 枚**へ期待更新。**P3 後の基準は personal + standard の 2 枚**）
- 更新: `/workspace/tests/Feature/Billing/PlanActiveFilterTest.php`（P1 新規。`is_active=false` フィルタ自体の回帰は維持し、fixture を「非公開プランを別途作る」形へ更新）
- 更新: `/workspace/tests/js/pages/Pricing.test.ts` → `/workspace/tests/js/pages/Guest/Pricing.test.ts`（import path のみ。**削除しない**）
- 更新: `/workspace/tests/js/pages/PurchaseTickets.test.ts`（:57 残高 fixture を per-bucket へ / :102 の POST 契約は `attempt_token` のまま維持 / :86 の「disabled にしない」契約を維持 / formState ケース追加）
- 更新: `/workspace/tests/js/components/molecules/PricingPlanCard.test.ts`（headerBadges）
- 新規: `/workspace/tests/Feature/Billing/BillingPlansPageTest.php` / `BillingPortalGuardTest.php` / `TicketPurchaseResumeStateTest.php` / `/workspace/tests/Feature/Billing/PlanRepublishMigrationTest.php`
- 新規: `/workspace/tests/js/pages/Billing/Plans.test.ts` / `Billing/PlanCard.test.ts` / `Billing/ticketCount.test.ts` / `Billing/Index.test.ts`
- 影響（変更なしで pass すること）: `/workspace/tests/js/architecture/{page-shell-structure,ds-purity,atomic-import-graph,lucide-scoped-import}.test.ts`

#### 主要な契約

ルート（課金ゲート allowlist 内・**route parameter を持たない current-org スコープ**）

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
public function plans(Request $request, BillingAccess $access): Response;                        // ['page' => BillingPlansPageDto]
public function index(Request $request, TicketLedgerService $tickets, BillingAccess $access, QuotaService $quota): Response; // ['page' => BillingDashboardDto]
public function portal(Request $request, SubscriptionCheckoutGateway $g, BillingAccess $access): SymfonyResponse|RedirectResponse; // 事前ガードの back() のため union
```

判定は `BillingAccess::effectivePlan(Organization): EffectivePlan` の**単一の生成経路**のみを使う（`plan_code` / `free_plan_code` の raw 参照を Controller / UI に書かない）。

Service（4 分割境界を維持）

```php
// App\Services\Billing\TicketCheckoutService
public function resolveResumablePurchase(Organization $org, int $userId, int $windowMinutes): ?TicketCheckoutSession;
// 2 段取得: (1) live pending (status=Pending / expires_at > now / checkout_url <> '') 最新 → resume
//           (2) completed (completed_at > now - window) 最新 → completed / (3) null → normal
// いずれも organization_id + initiated_by_user_id スコープ (cross-user の resumeUrl 漏洩を構造的に封じる)
```

DTO 形状（要点）

```
EffectivePlanShape       = { kind: 'paid_subscription'|'activated_personal'|'grandfathered_legacy_free'|'no_plan',
                             planCode: string|null, grantsAccess: bool, deniedReason: string|null }   // P2 由来
TicketBalanceShape       = { monthlyRemaining: int, purchasedRemaining: int, totalAvailable: int,
                             debt: int, activeReservations: int, nextExpireAt: string|null }          // P5 由来。debt は DTO 境界で正数
BillingPlanShape         = { code: string, name: string, baseAmountJpy: int|null, monthlyTicketGrant: int,
                             maxProjects: int|null, maxMembers: int|null, maxStorageGb: int|null }
BillingPlansPageShape    = { plans: list<BillingPlanShape>, effectivePlan: EffectivePlanShape, canManage: bool }
BillingDashboardShape    = { effectivePlan: EffectivePlanShape, currentPlanName: string|null, balance: TicketBalanceShape,
                             quotas: QuotaSummaryShape, canManageBilling: bool, canOpenPortal: bool, plansUrl: string }
PurchaseTicketsPageShape = { tiers: list<PurchaseTierShape>, minCount: int, maxCount: int, defaultCount: int,
                             balance: TicketBalanceShape, canManage: bool, ticketAttemptToken: string, purchased: bool,
                             formState: 'normal'|'resume'|'completed', boundCount: int|null,
                             resumeUrl: string|null, newPurchaseUrl: string }
```

`totalAvailable` は債務控除後の非負値（`monthlySpendable + purchasedPositive`。`debtAmount = max(-purchasedRaw, 0)` は hold と分離して算出される P5 の単一 snapshot 由来）。UI は表示のみで再計算しない。

DB 列 / index: **追加なし**。データ変更は (b) の `plans.is_active` の値更新（data migration）のみ。`ticket_checkout_sessions` の既存 `UNIQUE(organization_id, attempt_token)` / `initiated_by_user_id` / `expires_at` / `completed_at` をそのまま使う。

config: `billing.purchase_resume_window_minutes` = 30（新規）。`config/quota.php` は触らない（P4 で `fallback_plan='personal'` 確定済み）。

#### PHPStan 適合チェック

- `plans()` / `index()` の戻り値は `Inertia\Response`、`portal()` は `SymfonyResponse|RedirectResponse`（`back()` 分岐のため union を明示。既存 `checkout()` と同型）。
- 全ページ props は `readonly` DTO の `toArray()` 経由（`response()->json()` 直書きなし）。各 DTO に `@phpstan-type …Shape` を付け、`@phpstan-import-type` で親 DTO へ合成（既存 `PurchaseTicketsPageDto` / `PricingPageDto` と同じ規約）。`EffectivePlanShape` / `TicketBalanceShape` は P2 / P5 の DTO から import する（再宣言しない）。
- `resolveResumablePurchase(): ?TicketCheckoutSession` の null は Controller 側 `match(true)` で `default => [PurchaseFormState::Normal, (string) Str::ulid(), null, null]` へ縮退。分岐値の list 分解は各腕で同じ arity・型順を返し list shape 推論を保つ。
- `config('billing.purchase_resume_window_minutes')` は `mixed` のため `config()->integer(...)` typed accessor で取得（`Webmozart\Assert` の既存パターンでも可）。
- `$request->user()` は `Assert::isInstanceOf($user, User::class)`（既存踏襲）。`$request->boolean('fresh')` は `bool` 確定。
- `Plan::query()->where('is_active', true)->orderBy('sort_order')->get()->map(...)->values()->all()` は `list<BillingPlanDto>`（既存 `PricingController` と同じ）。`is_active` は P1 で `casts()` 済みのため `bool`。
- `EffectivePlan` は abstract + final variant。Controller / DTO 側は `instanceof` narrowing で扱い、`match(true)` の網羅漏れを型で潰す。`kind()` は `EffectivePlanKind` enum のため props の string も型付けされる。
- data migration は `Schema` 変更なし。`DB::table('plans')->where(...)->update(...)` の戻り値 `int` を `Assert::same()` で検証する（fail-closed）。
- **禁止**: `@phpstan-ignore` / baseline 追加 / 戻り値 widen。

#### テスト計画

**先に red を作る（新規）**

1. `tests/Feature/Billing/BillingPortalGuardTest.php`（bs-11）
   - `stripe_id` null の組織の owner が `POST /billing/portal` → **302 back + `error` flash**（Fake gateway 未呼び出し = Stripe に到達しない）。現行は Cashier 例外 = red。
   - `ActivatedPersonalPlan` の org（stripe_id 有・有償サブスク無）の owner → 同じく back + error。
   - 有償サブスク保持 org の owner → 既存どおり `Inertia::location` で Portal URL。
2. `tests/Feature/Billing/TicketPurchaseResumeStateTest.php`（tc-5）
   - live pending session を持つ owner が `GET /purchase-tickets` → `formState='resume'` / **`ticketAttemptToken` が既存 session の `attempt_token` と一致**（token 再利用）/ `boundCount` = session の `ticket_count` / `resumeUrl` = `checkout_url`。現行は毎回 fresh ULID = red。
   - `?fresh=1` → `formState='normal'` かつ token が別値。
   - 完了済 session（窓内）→ `formState='completed'` / `resumeUrl` は null。
   - **非管理者（member）** → live pending が存在しても `formState='normal'` / `resumeUrl` null（resume 漏洩の回帰）。
   - **他 user の pending は resume しない**（`initiated_by_user_id` スコープ）。
   - 二重課金回帰: resume 表示 → 同 token で `POST /purchase-tickets/checkout` → 既存 replay で同一 checkout URL へ収束し Stripe session が増えない。
3. `tests/Feature/Billing/BillingPlansPageTest.php`（bs-6）
   - owner: `GET /billing/plans` 200 / `page.plans` に `is_active=true` の全プラン（personal / starter / standard）/ `page.effectivePlan.planCode` 一致 / `canManage=true`。
   - **POST body 契約**: `POST /billing/checkout` が `{plan_code}` のみで成立する（attempt token を要求しない）。
   - member: 200 だが `canManage=false`。
   - current org 無しユーザー: 404（既存 `BillingPageTest:87` と同型）。
4. `tests/Feature/Billing/PlanRepublishMigrationTest.php`（(b)）
   - migration 実行後、`personal` / `starter` の `is_active=true`・**残余（`is_active=false` の当該 code）0 件**。
   - `personal` 行が存在しない DB では migration が **fail-closed**（例外）で、黙って no-op しない。
   - `/pricing` に personal / starter / standard の 3 枚が露出する（**露出テスト**。**再公開前は personal + standard の 2 枚**（Personal は P3 で公開済み）= red）。
5. `tests/js/pages/Billing/PlanCard.test.ts`
   - `isCurrent` で「現在のプラン」バッジ（headerBadges）が出る。
   - `canSwitch=false` で **`switchBlockedReason` が可視テキストとして描画**され、かつ **`disabled` 属性の button が存在しない**。CTA 押下で理由 Alert（`plan-switch-blocked`）が出る（禁止事項 #8 / DESIGN.md L399 の機械保証）。
6. `tests/js/pages/Billing/ticketCount.test.ts`
   - `parseTicketCount`: `'10'→10` / `'-5'→-5` / `'1e3'|'0x10'|'1.5'|'Infinity'|'-'|'1.'|''→null` / `10(number)→10`（防御的 `String(raw)`）。
7. `tests/js/pages/Billing/Plans.test.ts`
   - 「このプランへ変更」→ `ConfirmDialog` 表示 → 確認で `POST /billing/checkout` に **`{plan_code}` のみ**を送る。
   - `errors.plan_code` があるとき dialog は開いたままサーバ文言を描画する。
8. `tests/js/pages/Billing/Index.test.ts`
   - `canOpenPortal=false` で portal ボタンを描画しない。
   - `plan-list` を持たず「プラン比較」リンク（`/billing/plans`）を出す。
   - `effectivePlan.kind` 別のプラン表示（`paid_subscription` / `activated_personal` / `grandfathered_legacy_free` / `no_plan`）。
   - per-bucket 残高: `debt=0` で債務行を描画せず、`debt>0` で `balance-debt` を描画する。

**既存テストの更新（削除しない）**

- `tests/Feature/Billing/BillingPageTest.php:17`「owner は /billing でプラン一覧・残高・管理フラグを見られる」→ プラン一覧の期待を `BillingPlansPageTest` へ移設し、本 test は `page.effectivePlan` / `page.currentPlanName` / `page.balance`(per-bucket) / `canManageBilling` / `canOpenPortal` の期待へ更新。:38 / :50 / :60 / :87 / :93 / :106 は props 名の追随のみ。
- `tests/Feature/Billing/PortalConfigurationTest.php`: 事前ガード導入後も Portal configuration の spec が変わらないこと（ガードは spec でなく到達条件の変更）。
- `tests/Feature/Billing/TicketCheckoutTest.php`: 画面 render 由来の安定 token を使う経路の期待を追加（既存 replay / stale ケースは維持）。
- `tests/Feature/Marketing/PricingPageTest.php`: 料金表のカード件数を **2 → 3**（**P3 後は personal + standard の 2 枚**。P8b の Starter 再公開で 3 枚になる）。**加えて、本 migration の `down()` 後も personal + standard の 2 枚が維持される**（= Personal が非公開へ戻らない）テストを追加する（Codex Round 8）
- `tests/Feature/Billing/PlanActiveFilterTest.php`: 「`is_active=false` のプランは `/pricing` に出ない」という回帰そのものは維持し、fixture を専用の非公開プランへ置き換える（personal / starter は再公開済みのため）。
- `tests/js/pages/PurchaseTickets.test.ts`: `:57` の `balance` fixture を `TicketBalanceShape` へ、per-bucket 3 値 + `balance-debt` + `balance-next-expire` の描画期待を追加。`:86`（範囲外でも disabled にしない）・`:102`（`count` + `attempt_token` を POST）は**契約として維持**。`formState='resume'|'completed'` の描画ケース（フォーム非描画・`boundCount` 表示・CTA 2 種）を追加。
- `tests/js/components/molecules/PricingPlanCard.test.ts`: `headerBadges` を渡すと header 右へ描画され、渡さない場合は既存出力が不変（回帰）。
- `tests/js/pages/Pricing.test.ts` → `tests/js/pages/Guest/Pricing.test.ts`: import path のみ変更（内容不変 = 配置移動が挙動を変えないことの回帰）。

**arch テスト（変更せず pass）**: `page-shell-structure`（新設 `Billing/Plans.svelte` が PageContainer / PageHeader / PageContent を使う。`_helpers/PlanCard.svelte` は AppLayout を import しないため対象外）/ `ds-purity`（hex 直書き禁止。aigenba の `bg-primary/10` は AI-CUE の `bg-primary-soft` へ写す = 既存 `Pricing.svelte:165` の先例）/ `atomic-import-graph`（`_helpers` は pages 層。features → pages の逆参照なし）/ `lucide-scoped-import`（アイコンは `@lucide/svelte` のみ）。

#### リスク

| リスク | 緩和 |
|---|---|
| **Index からプラン一覧（`data-testid="plan-list"` / `checkout-{code}`）を撤去**すると既存 Feature / bug-hunt シナリオが参照喪失する | 撤去前に `grep -rn 'plan-list\|checkout-' tests/ devnotes/` で参照を洗い出し、期待を `Billing/Plans` へ**移設**（削除しない）。Index には `billing-plans-link` を残し導線を切らない。 |
| **Starter 再公開で購入できないプランが料金表に出る**（**Personal は P3 で公開済み**） | 再公開は購入導線（P3 activate-personal / P7 handoff / P8b Plans）が全て揃った本フェーズでのみ行う。`PlanRepublishMigrationTest` が露出と導線の同時成立を固定する。migration は行不在なら fail-closed で、導線未整備の環境で先走らない。 |
| **ticketAttemptToken 安定化で正当な追加購入を握り潰す**（completed 直後に別枚数で買えない） | `?fresh=1`（`newPurchaseUrl`）を `resume` / `completed` の両状態から必ず露出する。窓は `purchase_resume_window_minutes`(30) で有限化。 |
| **resume の Stripe 直リンクが purchase gate を迂回** | `canManage=false` では常に `normal` + fresh token へ縮退し、`resumeUrl` を props に載せない。`initiated_by_user_id` スコープで cross-user 漏洩も構造的に封じる。Feature テストで固定。 |
| **per-bucket 残高（debt 含む）が P5 未マージだと成立しない** | P8b は P5 の後段。`TicketBalanceDto` を DTO 境界でのみ参照し、`TicketLedgerService` の計算には触れない（債務数式は P5 の単一 snapshot が唯一の出典）。 |
| **`debt` を UI に出すことで残高が減ったように誤解される** | 「今すぐ使える残高」= `totalAvailable`（債務控除後）を主表示にし、`debt` は `> 0` のときのみ補足行として出す。表示は P5 の値をそのまま描画し、画面側で clamp / 再計算しない。 |
| **Guest/Pricing 移動で SSR / e2e の component 名参照が壊れる** | route path・route 名・SEO メタは不変。`grep -rn "'Pricing'\|\"Pricing\"" app/ tests/ resources/` で参照を全置換し、既存 `Pricing.test.ts` を移設して回帰にする。 |
| **Index の props 一括変更（4 props → `page`）の破壊範囲** | 同一 PR で Feature / JS 両テストを更新。DTO 化は禁止事項 4 の遵守でもあり後戻りしない。 |
| **P8a（auto-recharge カード）と Index を同時に触る競合** | P8b は `#auto-recharge` anchor と差し込みスロットのみ用意し、カード実体は P8a 所管（マージ順は P8a → P8b）。 |
| **P9（feedback / 請求先）が Index を再度触る** | P8b は Index を `BillingDashboardDto` 1 props に整えるところまでで止め、P9 は同 DTO への additive な追加（`feedback` / `billingContact`）で完結する。P8b 側に placeholder props を先置きしない。 |

---

### P9: サブスク checkout の冪等・着地 feedback + 請求先情報

前提: P1〜P8b がマージ済み（`PlanCode`(Personal/Starter/Standard) / `EffectivePlan` 4 variant / `SubscriptionService` / `StripeGatewayInterface`+`CashierStripeGateway` / `BillingCustomerSynchronizer`+`SyncBillingCustomerDetails` / `BillingDashboardDto`・`BillingPlansPageDto`(`page` 1 props) / `Billing/Plans.svelte` が既に存在する）。

**DoD**: サブスク checkout が **`ticket_checkout_sessions` と同型の冪等マシン**（`TicketCheckoutService` の防御 4 層）を持ち、二重 subscription 作成が構造的に不能。`billing_contact_email` / `billing_contact_name` は **CipherSweet 暗号化で保存**され、平文 DB 非保存・平文 where 不 hit が Feature/Architecture テストで固定される。**金銭の付与経路には一切触らない**（D7 維持: 付与は `invoice.paid`、`plan_code` 同期は `customer.subscription.*`。本フェーズの追跡行は着地 feedback と冪等の真実源であって台帳の出典ではない）。

**token 型名の分離（交渉不可）**: チケット決済の `ticketAttemptToken` / `ticket_checkout_sessions.attempt_token` / Stripe key `purchase:{token}` は **P8b までで確定済みの別物**。P9 が導入するのは `subscriptionAttemptToken` / `subscription_checkout_sessions.subscription_attempt_token` / Stripe key `subscription:{token}`。両者を同一 DTO・同一列・同一 key 空間に混ぜない。

#### 変更箇所

| ファイル (AI-CUE) | 何をするか | 移植元 (aigenba) |
|---|---|---|
| `database/migrations/2026_07_xx_xxxxxx_create_subscription_checkout_sessions_table.php` (新規) | サブスク Checkout 追跡表。`UNIQUE(organization_id, subscription_attempt_token)` / `UNIQUE(stripe_session_id)` / `UNIQUE(stripe_idempotency_key)` / `index(organization_id, initiated_by_user_id, status, expires_at)` | `BillingCheckoutSession` の列（AI-CUE に対象の無い `seats` / `funding_choice` / `topup_count` / `applied_campaign_id` / `applied_trial_days` / `credit_count` / `pm_reuse_dispatched_at` は原則 4 により移植しない） |
| `app/Models/Billing/SubscriptionCheckoutSession.php` (新規) | `$fillable = []`（全列 Service 明示代入）。`organization()` / `initiatedBy()` relation、`isLivePending(CarbonImmutable): bool` | `/tmp/aigenba/app/Models/Billing/BillingCheckoutSession.php`（`isReplayablePending()` は `TicketCheckoutSession::isLivePending()` 形へ寄せる = AI-CUE の expires_at pin 方式） |
| `app/Enums/Billing/SubscriptionCheckoutSessionStatus.php` (新規) | `Pending` / `Completed` / `Failed` / `Expired` | `/tmp/aigenba/app/Enums/CheckoutSessionStatus.php`（AI-CUE 規約の `App\Enums\Billing` 名前空間へ） |
| `database/factories/Billing/SubscriptionCheckoutSessionFactory.php` (新規) | 既定 = live pending。state: `forOrganization()` / `initiatedBy()` / `completed()` / `failed()` / `expired()` / `stale()`（= pending のまま `expires_at` 過去） | `TicketCheckoutSessionFactory` と同型 |
| `app/Services/Billing/SubscriptionCheckoutService.php` (新規) | 冪等マシン本体。`startCheckout()` / `attemptTokenIsForeign()` / `confirmsCheckoutReturn()`。`Cache::lock("billing:subscription-checkout:{$org->id}")` で org 直列化 | `TicketCheckoutService`（AI-CUE 側の先例が正。aigenba に対応する単独 Service は無い） |
| `app/Services/Billing/SubscriptionService.php` (改修) | **`startCheckout()` を撤去**し `SubscriptionCheckoutService` へ移設（生成経路を 2 本にしない）。`assertPriceSynced()` / `effectivePlan()` / `deriveEntitlement()` / `applySubscriptionSnapshot()` は据置。`createPortalSession()` の return URL に `?portal=1` を付す責務は Controller 側 | — |
| `app/Services/Billing/Contracts/StripeGatewayInterface.php` (改修) | `createSubscriptionCheckout(Organization, string $stripePriceId, string $successUrl, string $cancelUrl, string $idempotencyKey, array $metadata): CreatedCheckoutSession` へシグネチャ変更（戻り値 `ExternalBillingRedirect` → `CreatedCheckoutSession` = session id / url / expires_at の pin が必須になったため）。`expireCheckoutSession(string $sessionId): string` を追加。`createPortalSession` / `syncCustomerDetails` は据置 | `TicketCheckoutGateway` の同型メソッド |
| `app/Services/Billing/CashierStripeGateway.php` (改修) | `newSubscription('default',…)->checkout()` をやめ、`$org->stripe()->checkout->sessions->create($payload, ['idempotency_key' => $key])` 直呼びへ（**Cashier の `checkout()` ヘルパは per-request idempotency key を公開しない** = `CashierTicketCheckoutGateway` と同一理由・同一コメントを持ち込む）。`buildSubscriptionSessionPayload()` を public pure メソッドで切り出す | `CashierTicketCheckoutGateway::buildSessionPayload()` |
| `app/Services/Billing/Fakes/FakeStripeGateway.php` (改修) | 新シグネチャに追随。`CreatedCheckoutSession` を決定的に返し、同一 `idempotencyKey` の再呼び出しで**同一 sessionId を返す**（Stripe の idempotency 挙動を fake でも再現しないと要件 5 のテストが本物にならない）。`expireCheckoutSession()` は `'expired'` を返す | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php` |
| `app/Exceptions/Billing/SubscriptionAttemptPlanMismatchException.php` (新規) | 同 token・別 plan での再利用。Controller が `ValidationException::withMessages(['plan_code' => …])` = **422** へ変換 | — |
| `app/Exceptions/Billing/StaleCheckoutAttemptException.php` (再利用) | 既存クラスをサブスク側でも使う（期限切れ / 非 replayable 行）。**新設しない** | — |
| `app/Http/Requests/Billing/BillingCheckoutRequest.php` (改修) | `subscription_attempt_token => ['required','ulid']` を追加。`ProhibitsProtectedKeys` は据置（`organization_id` / `initiated_by_user_id` / `plan_id` は `missing` = **要件 9**） | `/tmp/aigenba/app/Http/Requests/Organizations/Billing/UpdateBillingContactRequest.php` の trait 使用形 |
| `app/Enums/Billing/BillingFeedbackKind.php` (新規) | `PurchaseReceived` / `PurchaseProcessing` / `PurchaseAlreadyReceived` / `PurchasePaymentFailed` / `CheckoutRetryRequired` / `PortalReturned` | `/tmp/aigenba/app/Enums/Billing/BillingFeedbackKind.php`（`PurchasePaymentFailed` のみ AI-CUE 追加。理由は下記） |
| `app/DataTransferObjects/Billing/BillingFeedbackDto.php` (新規) | `private __construct` + `simple(kind, message)` + `toArray(): BillingFeedbackShape` を verbatim 移植 | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingFeedbackDto.php` |
| `app/Http/Controllers/Billing/BillingController.php` (改修) | `index` に private `resolveBillingFeedback(Request, Organization): ?BillingFeedbackDto` を追加。`checkout` を `SubscriptionCheckoutService` 委譲へ（404 → 認可 → 開始の順）。`portal` の return URL を `route('billing.index', ['portal' => 1])` へ。`plans` に `subscriptionAttemptToken` を載せる。`updateBillingContact` を追加 | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:195,318-393` |
| `database/migrations/2026_07_xx_xxxxxx_add_billing_contact_columns_to_organizations_table.php` (新規) | `billing_contact_email` / `billing_contact_name` を **`text()->nullable()`**（CipherSweet ciphertext のため `string(255)` を使わない。`inquiries` の先例と同一判断）。**blind index 列は作らない**（共有 `blind_indexes` morph テーブルを使う既存規約） | `/tmp/aigenba/database/migrations/2026_04_14_011301_add_cashier_columns_to_organizations_table.php:16-17`（**列型は非 verbatim**。aigenba は平文 `string`） |
| `app/Models/Organization.php` (改修) | `implements CipherSweetEncrypted` + `use UsesCipherSweet` + `configureCipherSweet()`。`routeNotificationForMail()` を `billing_contact_email` 正本 → owner email fallback へ。**両列とも `$fillable` 外**（`UpdateBillingContactAction` が明示代入） | `/tmp/aigenba/app/Models/Organization.php:119-138`（fallback 意味論のみ。`$fillable` 掲載は移植しない） |
| `app/DataTransferObjects/Billing/UpdateBillingContactData.php` (新規) | `fromRequest()` で `EmailNormalizer::normalize()` + `Assert::stringNotEmpty()`、name は空文字を null へ畳む | `/tmp/aigenba/app/DataTransferObjects/Billing/UpdateBillingContactData.php` |
| `app/Http/Requests/Billing/UpdateBillingContactRequest.php` (新規) | `billing_contact_email => ['required','email:rfc','max:255']` / `billing_contact_name => ['nullable','string','max:255']` + `protectedKeyMissingRules()`。**`array_replace` ではなく `array_merge`**（AI-CUE 規約 = 保護キー後勝ち） | `/tmp/aigenba/app/Http/Requests/Organizations/Billing/UpdateBillingContactRequest.php`（namespace は current-org スコープに合わせ `App\Http\Requests\Billing`） |
| `app/Actions/Billing/UpdateBillingContactAction.php` (新規) | `DB::transaction` 内で両列代入 → **`save()` 前に `isDirty('billing_contact_email')` 判定** → `save()` → email dirty 時のみ `BillingCustomerSynchronizer::dispatchFor()`（IV-2/3/5/6 を docblock ごと移植） | `/tmp/aigenba/app/Actions/Billing/UpdateBillingContactAction.php` |
| `app/Services/Billing/StripeWebhookProcessor.php` (改修) | `checkout.session.completed` の match arm を **purpose ディスパッチ**へ: `ticket_purchase` → 既存 `grantPurchasedTickets()`（**無改変**）/ `subscription_start` → 新設 `settleSubscriptionCheckout()`。`stripe_id` 同期は `CashierStripeGateway::syncCustomerDetails()` 経由のまま | `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php` の session 状態遷移部 |
| `app/DataTransferObjects/Billing/BillingDashboardDto.php` (改修) | additive: `feedback: BillingFeedbackShape|null` / `billingContact: BillingContactShape`（P8b が placeholder を先置きしない前提どおり、ここで初めて生える） | — |
| `app/DataTransferObjects/Billing/BillingPlansPageDto.php` (改修) | additive: `subscriptionAttemptToken: string`（render ごとの ULID） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingPlansDto.php` |
| `resources/js/components/features/billing/BillingContactForm.svelte` (新規) | 請求先メール / 宛名の更新フォーム。`@lucide/svelte` の `Receipt` アイコン、DS token のみ | `/tmp/aigenba/resources/js/pages/Billing/_helpers/BillingContactForm.svelte` |
| `resources/js/pages/Billing/Index.svelte` (改修) | `page.feedback` バナー（`kind` で variant 決定・**raw query を UI が見ない**）と `BillingContactForm` を `PageHeaderSection` 配下に追加 | `/tmp/aigenba/resources/js/pages/Billing/Index.svelte` |
| `resources/js/pages/Billing/Plans.svelte` (改修) | POST body を `{plan_code}` → `{plan_code, subscription_attempt_token}` へ | — |
| `routes/web.php` (改修) | `PATCH /billing/contact` → `billing.contact.update`（課金ゲート allowlist 内・**route parameter なし** = current-org スコープ） | — |

**非スコープ（P9 で持ち込まない）**: `CheckoutIntent` enum（AI-CUE の追跡表は 2 本に分かれており、purpose は metadata 文字列 + テーブル自体が intent を表す。`SignupFunding` / `SetupPaymentMethod` / `CreditPurchase` は対象機能が無い = 原則 4）/ `resolveAutoRechargeLanding`（`setup_session_id` / `funding_choice` 経路。P8a は PM 流用 Job を持たない）/ `resolveOnboardingContinue`（`OnboardingReturnResolver` は P7 所管で `?session_id` 非依存に配線済み）/ `pm_reuse_dispatched_at` / `checkout.session.expired` の購読（expires_at pin による決定的回収で足り、Stripe 照会を増やさない）/ `billing_contact_email` の NOT NULL 化・backfill（S1/S2 相当。fallback が生きている限り不要）。

#### 波及変更

- **TypeScript 型定義** `resources/js/types/billing.ts`:
  - 追加 `BillingFeedbackKind`（`'purchase_received'|'purchase_processing'|'purchase_already_received'|'purchase_payment_failed'|'checkout_retry_required'|'portal_returned'`）/ `BillingFeedbackShape { readonly kind: BillingFeedbackKind; readonly message: string }` / `BillingContactShape { readonly email: string | null; readonly name: string | null; readonly fallbackEmail: string | null }`。
  - `BillingIndexPageProps` に `feedback: BillingFeedbackShape | null` / `billingContact: BillingContactShape` を追加。`BillingPlansPageProps` に `subscriptionAttemptToken: string` を追加。PHP `@phpstan-type` と exact 対で保守する既存規約を維持。
- **Inertia props**: `Billing/Index` の `page` shape 拡張（DTO `toArray()` 経由。`response()->json()` 直書きなし）/ `Billing/Plans` の `page` shape 拡張。新規ページなし。
- **DTO**: 新規 `BillingFeedbackDto` / `UpdateBillingContactData` / `BillingContactDto`。改修 `BillingDashboardDto` / `BillingPlansPageDto`。`CreatedCheckoutSession`（既存）をサブスク側でも再利用（新設しない）。
- **Factory**: 新規 `SubscriptionCheckoutSessionFactory`。`OrganizationFactory` に `withBillingContact(?string $email = null, ?string $name = null)` state を追加（テストデータ手組み禁止）。
- **config**: `config/cashier.php` の購読集合は `HandledStripeWebhookEvent` 由来の既存導出のまま（**case を増やさない** = `WebhookEventSubscriptionInvariantTest` は無変更で green）。
- **テストファイル（更新・削除しない）**: `tests/Feature/Billing/BillingPageTest.php`（Index props に `feedback` / `billingContact`）/ `tests/Feature/Billing/BillingPlansPageTest.php`（`subscriptionAttemptToken`）/ `tests/Feature/Billing/PortalConfigurationTest.php`（return URL の `?portal=1`）/ `tests/Feature/Billing/WebhookIdempotencyTest.php`・`WebhookEventSubscriptionInvariantTest.php`（期待不変）/ `tests/Feature/Billing/TicketPurchaseWebhookTest.php`（purpose ディスパッチ後も **ticket 経路が無改変で green** であることの回帰）/ `tests/js/pages/Billing/Index.test.ts`・`Plans.test.ts`。
- **Architecture テストへの影響**: `MassAssignmentSafetyTest`（`billing_contact_*` は `$fillable` 外）/ `FormRequestProhibitedKeyTest`（新 FormRequest 2 本が `protectedKeyMissingRules()` を張る）/ `ManageRouteAuthGuardTest`（`billing.contact.update`）/ `OrganizationRouteParamWebOnlyInvariantTest`（route param 無しのため対象外を確認）。

#### 主要な契約

**ルート**（課金ゲート allowlist 内・route parameter を持たない current-org スコープ）

```
GET   /billing            billing.index           BillingController@index          … 既存 (?session_id / ?portal / ?replayed / ?retry を解釈)
GET   /billing/plans      billing.plans           BillingController@plans          … 既存 (subscriptionAttemptToken を発行)
POST  /billing/checkout   billing.checkout        BillingController@checkout       … 既存 (body: {plan_code, subscription_attempt_token})
POST  /billing/portal     billing.portal          BillingController@portal         … 既存
PATCH /billing/contact    billing.contact.update  BillingController@updateBillingContact  ← 新規 (manageBilling)
```

**DB 列 / index**

```sql
-- subscription_checkout_sessions (冪等マシンの真実源。金銭の出典ではない)
id, organization_id FK cascade, initiated_by_user_id FK nullOnDelete (監査行のため null 化),
plan_code varchar, stripe_price_id varchar, unit_amount unsigned int, currency varchar(8),   -- 作成時 pin (webhook 照合の出典)
stripe_session_id varchar UNIQUE,
stripe_idempotency_key varchar UNIQUE,                                                        -- 要件 5
subscription_attempt_token varchar(26),
checkout_url varchar(2048), status varchar, expires_at timestamp, completed_at timestamp null, timestamps
UNIQUE (organization_id, subscription_attempt_token)                                          -- 要件 1
INDEX  (organization_id, initiated_by_user_id, status, expires_at)                            -- 要件 2 の引き

-- organizations (additive)
billing_contact_email text null,  billing_contact_name text null   -- CipherSweet ciphertext
```

**冪等状態機械（要件 1-9 の契約。`TicketCheckoutService` の防御 4 層と同型）**

```php
// App\Services\Billing\SubscriptionCheckoutService
final class SubscriptionCheckoutService
{
    private const int LOCK_SECONDS = 10;
    private const int LOCK_WAIT_SECONDS = 5;
    /** Stripe idempotency key の派生規則 (plan code を含めない = 要件 6 と矛盾させないため) */
    public static function idempotencyKeyFor(string $attemptToken): string { return 'subscription:'.$attemptToken; }

    /** 要件 7: (org, user) スコープ外に同 token 行が在るか。true なら Controller が認可より前に 404 */
    public function attemptTokenIsForeign(string $attemptToken, Organization $org, User $user): bool;

    /** @throws SubscriptionAttemptPlanMismatchException|StaleCheckoutAttemptException|CheckoutInProgressException|StripePriceNotSyncedException */
    public function startCheckout(Organization $org, User $user, Plan $plan, string $attemptToken): SubscriptionCheckoutRedirect;

    /** 着地の表示専用検証 (org スコープ。fail-closed) */
    public function confirmsCheckoutReturn(Organization $org, ?string $sessionId): ?SubscriptionCheckoutSession;
}

final readonly class SubscriptionCheckoutRedirect {
    public function __construct(public ?string $url, public bool $alreadyCompleted) {}   // url null + alreadyCompleted = 受付済み着地
}
```

`startCheckout()` の手順（org 単位 `Cache::lock` 内。`LockTimeoutException` は fail-closed で `CheckoutInProgressException` = ロックなし実行へフォールバックしない）:

| # | 段 | 挙動 |
|---|---|---|
| 0 | 期限切れ pending の回収 | `status=Pending AND expires_at <= now` を **`Expired` へ一括更新**（dedup の前。`expires_at` pin で決定的 = Stripe 照会不要） |
| 1 | **同 token 行** (`org` + `subscription_attempt_token`) | `Completed` → `SubscriptionCheckoutRedirect(url: null, alreadyCompleted: true)`（**要件 4**: 新規 Checkout を作らない）<br>`live pending` **かつ `plan_code === $plan->code`** → `Redirect(url: $row->checkout_url)`（**要件 4**: 既存 Checkout URL へ収束）<br>`live pending` **かつ plan 不一致** → `SubscriptionAttemptPlanMismatchException` → **422**（**要件 6**）<br>`Failed` / `Expired` / 期限切れ pending → `StaleCheckoutAttemptException` |
| 2 | **live pending dedup** (`org` + `initiated_by_user_id`) | 同 plan → 既存 URL へ収束 / 別 plan → `gateway->expireCheckoutSession()` が `'complete'` を返したら `CheckoutInProgressException`、それ以外は行を `Expired` にして続行（**別タブ・新 token でも live session は 1 本** = 二重 subscription 作成の構造的封じ。**要件 2** の actor scope はここで効く） |
| 3 | Stripe 作成 → DB 記録 | `assertPriceSynced()`（`SubscriptionService`）→ `gateway->createSubscriptionCheckout(..., idempotencyKey: idempotencyKeyFor($token), metadata: ['purpose' => 'subscription_start', 'org_ref' => (string) $org->id, 'plan_code' => $plan->code])`。**要件 5**: `stripe_idempotency_key` 列に同値を保存し UNIQUE を張る（Stripe 側 key と自 DB 行が 1:1 であることを DB が保証する） |
| 4 | `UniqueConstraintViolationException` の re-read 収束 | `orWhere` を使わず 2 段の確定クエリ: (1) `UNIQUE(org, token)` → 高々 1 行 / (2) `UNIQUE(stripe_session_id)` → 引けても **自 org 行でなければ replay しない**（fail-closed）。replay 不能なら `StaleCheckoutAttemptException`（500 にしない） |

**状態遷移（要件 3。`Completed` は終局）**

```
Pending ──(checkout.session.completed / payment_status=paid|no_payment_required)──▶ Completed
Pending ──(checkout.session.completed / payment_status=unpaid)──────────────────▶ Failed      // 初回請求未決済 = subscription incomplete
Pending ──(expires_at <= now の回収 / 別 plan での明示 expire)──────────────────▶ Expired
Expired ──(遅延到着した completed webhook)─────────────────────────────────────▶ Completed   // 要件 8: 回収と webhook の競合。金銭の真実は Stripe が終局
Failed  ──(同 session の completed 再送)───────────────────────────────────────▶ Completed
Completed ──────────────────────────────────────────────────────────────────▶ (終局。他へ遷移しない)
```

**Controller の実行順（要件 7 = セキュリティ不変条件 #2「不整合は認可より前に 404」）**

```php
public function checkout(BillingCheckoutRequest $request, SubscriptionCheckoutService $checkout): SymfonyResponse|RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $user = $request->user();  Assert::isInstanceOf($user, User::class);
    $attemptToken = $request->validated('subscription_attempt_token');  Assert::string($attemptToken);

    // (1) 他 org / 他 user の token は 404 (403 にしない = 存在オラクル封じ)。Gate より前。
    abort_if($checkout->attemptTokenIsForeign($attemptToken, $organization, $user), 404);
    // (2) 認可
    Gate::authorize('manageBilling', $organization);
    // (3) plan 解決 → 冪等開始
    $planCode = $request->validated('plan_code');  Assert::string($planCode);
    $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

    try {
        $redirect = $checkout->startCheckout($organization, $user, $plan, $attemptToken);
    } catch (SubscriptionAttemptPlanMismatchException $e) {
        throw ValidationException::withMessages(['plan_code' => $e->getMessage()]);        // 422
    } catch (StaleCheckoutAttemptException $e) {
        return redirect()->route('billing.index', ['retry' => 1]);                          // → checkout_retry_required
    } catch (CheckoutInProgressException|StripePriceNotSyncedException $e) {
        return back()->with('error', $e->getMessage());
    }

    return $redirect->url === null
        ? redirect()->route('billing.index', ['replayed' => 1])                             // → purchase_already_received
        : Inertia::location($redirect->url);
}
```

`success_url = route('billing.index').'?session_id={CHECKOUT_SESSION_ID}'` / `cancel_url = route('billing.plans')`。**禁止事項 #7**（`redirect()->intended()`）は使わない。**禁止事項 #8**: `Billing/Plans` の申込ボタンは token / plan の状態で disabled にせず、押下時に上記のエラー・422 を表示する。

**着地 feedback（`resolveBillingFeedback`。UI は raw query を見ない）**

| query | 条件 | kind / 文言 |
|---|---|---|
| `?portal` | **`session('error')` が文字列なら `null`**（成功偽装の抑止。aigenba F-2-03 を移植） | `portal_returned` /「お支払い管理画面から戻りました。」 |
| `?session_id=` | `$org->subscriptionCheckoutSessions()->where('stripe_session_id', …)` で **org スコープ** 一致（未知 / 他 org は `null` = 偽 success 排除） | `Completed` → `purchase_received` /「お支払いを受け付けました。プランへの反映には数分かかる場合があります。」<br>`Pending` → `purchase_processing` /「お支払いを確認しています。プラン反映までしばらくお待ちください。」<br>`Failed` → `purchase_payment_failed` /「お支払いを完了できませんでした。カード情報をご確認のうえ、もう一度お申し込みください。」<br>`Expired` → `checkout_retry_required` |
| `?replayed` | — | `purchase_already_received` /「この内容のお申し込みは既に受け付け済みです。」 |
| `?retry` | — | `checkout_retry_required` /「お手続きの有効期限が切れました。画面を再読み込みして再試行してください。」 |

**aigenba からの非 verbatim 点と根拠**: (a) `isSubscription` による文言出し分けを移植しない — AI-CUE はチケット着地が `billing.tickets.show`、サブスク着地が `billing.index` と route レベルで分離済みのため、`billing.index` の `session_id` は subscription 専用（分岐は PHPStan level 10 で常時 true の死枝になる）。(b) `PurchasePaymentFailed` を追加 — aigenba は `Failed` 着地で `null` を返し「何も起きていない画面」に着地する（ユーザーが詰む）。fail-closed かつ再試行導線を出す方が正しい。

**webhook（金銭に触れない境界）**

```php
// StripeWebhookProcessor::settleSubscriptionCheckout(array $payload): void
// (1) purpose ガード: metadata.purpose !== 'subscription_start' → 受理のみ / mode !== 'subscription' → 受理のみ
//     (既存 grantPurchasedTickets の 'ticket_purchase' + mode=payment ガードは無改変。相互に排他)
// (2) 真実源は自 DB 行。行不在 → throw = retryable failure (crash 先着 webhook は再試行で行が入った後 Stripe 再送で収束)
// (3) tenant キー不信: payload の customer / metadata.org_ref は照合のみ (不一致は throw)。org 解決には使わない
// (4) payment_status: 'paid'|'no_payment_required' → Completed / 'unpaid' → Failed
// (5) 遷移は Completed 終局を守る (Completed 行への再送は no-op = 冪等)。
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
}
```

- **検索契約**: `billing_contact_email` の検索は **`Organization::whereBlind('billing_contact_email', 'organization_billing_contact_email_index', $value)` のみ**。平文 `where('billing_contact_email', …)` は hit しない。保存値は `EmailNormalizer::normalize()` 済みのため、検索入力も**同一正規化を通す**（`inquiries` と同じ規約。`users.email` の raw 保存規約とはここで意図的に異なる — `UpdateBillingContactData` が正規化を保証する）。
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

**DTO 形状（要点。PHP `@phpstan-type` と `resources/js/types/billing.ts` を exact 対で保守）**

```
BillingFeedbackShape     = { kind: 'purchase_received'|'purchase_processing'|'purchase_already_received'
                                  |'purchase_payment_failed'|'checkout_retry_required'|'portal_returned',
                             message: string }
BillingContactShape      = { email: string|null, name: string|null, fallbackEmail: string|null }   // fallbackEmail = owner email (未設定時の実宛先を UI に明示)
BillingDashboardShape    = { …P8b の全項目, feedback: BillingFeedbackShape|null, billingContact: BillingContactShape }
BillingPlansPageShape    = { plans: list<BillingPlanShape>, effectivePlan: EffectivePlanShape, canManage: bool,
                             subscriptionAttemptToken: string }
```

**UI**: `Billing/Index.svelte` は `templates/PageContainer` / `molecules/PageHeaderSection` / `templates/PageContent`（T071 primitive）配下に feedback バナーと `BillingContactForm` を置く。DS token のみ（hex 直書き禁止）。アイコンは `@lucide/svelte`（`CircleCheck` / `Clock` / `TriangleAlert` / `Receipt`）。判定源は `page.effectivePlan`（`state()` という名前を使わない・`OnboardingBillingState` は存在しない）。

#### PHPStan 適合チェック

- `SubscriptionCheckoutSession::$status` は `SubscriptionCheckoutSessionStatus` へ cast（`protected function casts()` 方式 = `TicketCheckoutSession` と同型）。`expires_at` / `completed_at` は `immutable_datetime` → `CarbonImmutable` / `?CarbonImmutable` を `@property` で宣言。
- `Cache::lock()->block()` は `mixed` を返すため、`TicketCheckoutService` と同じく `Assert::isInstanceOf($redirect, SubscriptionCheckoutRedirect::class)` で絞る（`@var` コメントでの黙らせをしない）。
- `attemptTokenIsForeign()` は `->exists()` を返す `bool`。`whereNot(fn (Builder $q) => …)` の closure 引数に `@param Builder<SubscriptionCheckoutSession>` を付す。
- `BillingFeedbackDto::toArray()` は `@phpstan-type SimpleBillingFeedbackKind` + `@return BillingFeedbackShape`。`$this->kind->value` は `string` に広がるため、aigenba と同じく `/** @var SimpleBillingFeedbackKind $kindValue */` で literal union へ narrow（型の widen ではなく enum → literal の再表明）。
- `resolveBillingFeedback()` の `$request->query('session_id')` は `mixed` → `is_string($x) && $x !== ''` で narrow してから使う（`?->` で握り潰さない）。
- `Organization::routeNotificationForMail(): ?string` — `billing_contact_email` は `?string`。`EmailNormalizer::normalize(string): string` は非 null 引数を要求するため、`$this->billing_contact_email` を `is_string() && trim() !== ''` で narrow してから渡す（aigenba の `normalize(?string)` シグネチャへ寄せない = AI-CUE の既存 `EmailNormalizer` を改変しない）。
- `UpdateBillingContactData::fromRequest()` は `$request->string(…)->toString()` + `Assert::stringNotEmpty()`、name は `$request->input()` の `mixed` を `is_string() && trim() !== ''` で narrow。
- `StripeGatewayInterface::createSubscriptionCheckout()` の `array $metadata` は `@param array<string, string>`。`CashierStripeGateway::buildSubscriptionSessionPayload()` は戻り値 array shape を `@return array{mode: 'subscription', customer: string, line_items: …, subscription_data: array{metadata: array<string, string>}, …}` で固定（`CashierTicketCheckoutGateway::buildSessionPayload()` と同様式）。
- webhook 側の `data_get()` の `mixed` は既存 `stringAt()` helper で narrow。`payment_status` は `match(true)` ではなく `in_array($status, ['paid', 'no_payment_required'], true)` で判定（Stripe 値集合は enum 化しない = payload 由来の外部語彙）。
- 型を緩めた回避・baseline 化は行わない（禁止事項 2）。

#### テスト計画

**テストファースト**。`RefreshDatabase` グローバル + `--parallel`（個別 `DatabaseTransactions` 禁止）。テストデータは Factory のみ。

新規 `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php`（**要件 1-8**）:
1. 同一 `subscription_attempt_token` + 同一 plan の 2 連投で **`subscription_checkout_sessions` が 1 行**、2 回目は**既存 `checkout_url` へ収束**し `FakeStripeGateway` の作成呼び出しが **1 回**（要件 1 / 4）。
2. 同一 token + **別 plan_code** → **422**（`assertInvalid(['plan_code'])`）。行は増えず Stripe 呼び出しも増えない（要件 6）。
3. `stripe_idempotency_key === 'subscription:'.$attempt_token`、かつ同 key の再呼び出しで fake が**同一 sessionId** を返す（要件 5）。
4. **他 org の token** で POST → **404**（`Gate` 到達前。`manageBilling` を持つ owner でも 404）。**同 org の他 user の token** → **404**（要件 7 / 2）。
5. **他 org の token を持つ owner が新規 checkout を作れてしまわない**こと（404 で止まり、行が作られない = silent fallthrough の回帰防止）。
6. `completed()` 行の token 再送 → `billing.index?replayed=1` へ redirect、Stripe 呼び出し 0（要件 4）。
7. `stale()`（pending / `expires_at` 過去）→ `billing.index?retry=1` へ redirect + 行が `Expired` へ回収される（要件 3）。
8. 同 user の live pending が別 plan であるとき、`expireCheckoutSession` が `'complete'` を返すと `CheckoutInProgressException` → `back()->with('error')`、**新規行は作られない**（二重 subscription 作成の封じ）。
9. `UniqueConstraintViolationException` 注入（並行 race 模擬）→ 500 にならず replay / stale へ収束。

新規 `tests/Feature/Billing/SubscriptionCheckoutWebhookRaceTest.php`（**要件 8**）:
10. `checkout.session.completed`（purpose=subscription_start / mode=subscription / payment_status=paid）→ 行 `Completed` + `completed_at` 設定。**チケット付与も `plan_code` 書き換えも起きない**（`ticket_ledger_entries` 0 件 / `organizations.plan_code` 不変 = D7 境界の回帰）。
11. 同一 event の **再送**（event_id 違いを含む）→ 冪等（行は Completed のまま、二重処理なし）。
12. **回収 → 遅延 completed の競合**: 行を `Expired` にした後 completed webhook → `Completed` へ遷移する（決済成立を取りこぼさない）。
13. `payment_status=unpaid` → `Failed`。その後同 session の `paid` 再送 → `Completed`。
14. **cancel 相当**（ユーザー離脱 → `expires_at` 経過）→ 次回 `startCheckout` で `Expired` 回収され、新 token で新規 Checkout が作れる。
15. 行不在の completed → throw = retryable failure（既存 `handle()` の catch で `failed` 記録。**silent 付与しない**）。
16. `customer` / `metadata.org_ref` 不一致 → throw（tenant キー不信）。
17. **purpose ディスパッチの排他**: `purpose=ticket_purchase` の payload は `settleSubscriptionCheckout` に入らず既存 `grantPurchasedTickets` が従来どおり動く（`TicketPurchaseWebhookTest` が**無改変で green**）。

新規 `tests/Feature/Billing/BillingFeedbackTest.php`:
18. `?session_id=` が自 org の Completed 行 → props `page.feedback.kind === 'purchase_received'`。Pending → `purchase_processing`。Failed → `purchase_payment_failed`。Expired → `checkout_retry_required`。
19. **他 org / 未知の `session_id`** → `page.feedback === null`（偽 success 排除）。
20. `?portal` + `session('error')` あり → `null`（成功偽装の抑止）。error 無し → `portal_returned`。
21. `?replayed` → `purchase_already_received` / `?retry` → `checkout_retry_required`。

新規 `tests/Feature/Billing/BillingContactPiiTest.php`（**不変条件 #6**）:
22. `PATCH /billing/contact` 後、**`DB::table('organizations')` の生値が `billing_contact_email` / `billing_contact_name` の平文と一致しない**（両方）。model 経由の読み出しは平文に復号される。
23. **平文 where が hit しない**: `Organization::query()->where('billing_contact_email', $plain)->exists()` が false。`whereBlind('billing_contact_email', 'organization_billing_contact_email_index', $plain)` が該当 org を引く。
24. **`billing_contact_name` の blind index 行が存在しない**（`blind_indexes` に `name = 'organization_billing_contact_name_index'` が 0 件 = 検索契約の固定）。
25. 大文字混じり入力で保存 → 正規化後の小文字で `whereBlind` が hit（`EmailNormalizer` 経路の固定）。

新規 `tests/Feature/Billing/UpdateBillingContactTest.php`:
26. **email 変更時のみ** `SyncBillingCustomerDetails` job が dispatch される（`Queue::fake()`）。**name のみ変更では dispatch されない**（IV-5 / IV-6）。
27. `stripe_id === null` の org では job が dispatch されない（`BillingCustomerSynchronizer` の no-op 契約）。
28. **認可**: member（非 owner/admin）は 403。未ログインは redirect。**他 org のデータは触れない**（current-org scope のため route 上指定不能であることを、org 切替後の PATCH が切替後 org のみを更新することで固定）。
29. **payload 契約**（要件 9 / 不変条件 #1）: `organization_id` / `initiated_by_user_id` / `plan_id` を body に混ぜると **422**（`ProhibitsProtectedKeys`）。`billing_contact_email` 欠落 → 422。
30. `routeNotificationForMail()` が `billing_contact_email` 正本 → 未設定時に owner email へ fallback。

新規 `tests/Feature/Architecture/BillingContactEncryptionInvariantTest.php`:
31. `Organization` が `CipherSweetEncrypted` を実装し、`configureCipherSweet()` に `billing_contact_email` / `billing_contact_name` の**両方**が登録されている（列を足して暗号化を忘れる回帰の構造的封じ）。
32. `organizations` の `billing_contact_*` 列型が `text`（`string(255)` への差し戻しで ciphertext が切れる回帰の封じ）。
33. `billing_contact_*` が `$fillable` に無い（`MassAssignmentSafetyTest` と重複しない範囲で、本フェーズの列を明示 assert）。

更新 `tests/Feature/Billing/BillingCheckoutRequestTest.php` 相当:
34. `subscription_attempt_token` 欠落 / 非 ULID → 422。

JS（Vitest）:
35. 新規 `tests/js/pages/Billing/BillingContactForm.test.ts` — 未入力でも **submit ボタンが disabled にならない**（禁止事項 #8）。押下時にサーバ 422 の `errors.billing_contact_email` が表示される。
36. 更新 `tests/js/pages/Billing/Index.test.ts` — `feedback` の 6 kind が対応バナーを描画し、`feedback: null` で何も描画しない。**raw query（`session_id` 等）を参照しない**。
37. 更新 `tests/js/pages/Billing/Plans.test.ts` — POST body に `subscription_attempt_token` が載る。ボタンは常に enabled。
38. 影響（無変更で green）: `tests/js/architecture/{page-shell-structure,ds-purity,atomic-import-graph,lucide-scoped-import}.test.ts`。

#### リスク

| リスク | 緩和 |
|---|---|
| **`CashierStripeGateway` が `newSubscription()->checkout()` を捨てることで、Cashier の webhook が `subscriptions` 行を作れなくなる**（Cashier は `subscription_data.metadata.{name,type}` を見て行を作る。ここを落とすと**課金成立なのに subscription 行が無い** = `effectivePlan()` が `NoPlan` に落ち P4 後に締め出し） | `buildSubscriptionSessionPayload()` を public pure メソッドにし、**`subscription_data.metadata.name='default'` / `type='default'` を含むことを gateway ユニットテストの invariant として固定**（`CashierTicketCheckoutGateway::buildSessionPayload()` の promo/tax invariant と同じ様式）。加えて Feature テスト 10 で「completed webhook 後に `customer.subscription.created` が来ると `subscriptions` 行が作られる」ことを確認する。**この invariant テストが payload 変更の唯一の入口**。 |
| **`checkout.session.completed` の purpose ディスパッチ改修がチケット付与を壊す**（P5/T007 の金銭経路） | `grantPurchasedTickets()` の本体は**無改変**で、match arm の手前に purpose 分岐を足すだけに留める。`TicketPurchaseWebhookTest` / `WebhookIdempotencyTest` を**無変更で green** に保つことを DoD にする（変更が要るなら設計が誤り）。 |
| **`Organization` への CipherSweet 導入が既存の org 検索・Filament を壊す** | 暗号化するのは新規 additive 2 列のみ。`name` / `slug` は平文のまま（暗号化すると `slug` の route 解決・`unique` 制約・既存 `OrganizationFactory` が全滅する）。既存 org 行は `billing_contact_*` が null のため `addOptionalTextField` で素通し = backfill 不要。 |
| **`billing_contact_email` を Stripe へ同期することで PII が外部へ出る** | 元々 Stripe customer は課金主体として email を保持しており（現行 `syncStripeCustomerDetails()` が owner email を送っている）、送信先・送信内容は不変。**`billing_contact_name` は Stripe へ送らない**（aigenba IV-6 を移植）ため PII 露出面は増えない。CipherSweet は保管時（自 DB）の保護であり、この境界は変わらない。 |
| **feedback バナーが「成功」を偽装する**（webhook 未達で行が Pending のまま「受け付けました」と出る） | `session_id` は**自 org の DB 行と照合できたときのみ** feedback を出し、行の `status` を文言の唯一の根拠にする（Pending は「確認しています」= 確定表現を使わない）。任意 query（`?replayed` / `?retry`）は状態を主張しない中立文言のみに割り当てる。 |
| **`SubscriptionService::startCheckout()` 撤去が P2 の契約を壊す** | 撤去は P9 の同一 PR 内で呼び出し元（`BillingController::checkout` の 1 箇所）を差し替える。thin delegate を残さない理由（生成経路が 2 本になると要件 4 の「新規 Checkout を作らない」保証が片方だけに掛かる）を docblock に明記。`StripePriceNotSyncedException` の catch と**現行同一文言**の flash は `SubscriptionCheckoutService` 経由でも維持する。 |
| **live pending dedup の `expireCheckoutSession` 失敗で checkout が詰む** | fail-closed（新規作成せずエラー着地）は二重 live session を作らないための意図的挙動。ユーザー側の出口は (a) 元の `checkout_url` へ収束する同 plan 再送、(b) `expires_at` 経過後の自動回収（pin 由来で決定的）の 2 本があり、**恒久的に詰む経路は無い**ことをテスト 8 / 14 で固定する。 |
| **`?session_id` の org スコープが actor スコープでないため、同 org の別 member に「お支払いを受け付けました」が出る** | 意図的。課金は org 単位の事実であり member 全員が閲覧できる（`billing.index` の閲覧権は既に org メンバー全員）。**actor scope を課すのは冪等マシン（token）側のみ**で、feedback は org 事実の表示に留める。`checkout_url` は actor scope の外へ一切出さない（`resolveResumablePurchase` と同じ判断）。 |
