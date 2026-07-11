# 対応マトリクス: design-review Round 2

## [Warning] 施策3: PricingPlanDto::baseAmountJpy の null と PricingPlanCard の「0 = 無料」規約が不一致
- 判断: 対応する（null の意味を確定し、カード側の契約を AI-CUE 用に定義）
- 根拠と決定:
  - AI-CUE のプラン台帳の既存意味論は「**価格行 (plan_prices) を持たないプラン = Checkout 対象外の無料プラン**」（PlanSeeder docblock: 「free プランは Stripe Price を持たない (Checkout 対象外。未契約の既定)」）。既存 `Billing/Index.svelte` も `formatPrice(null) => "無料"` を表示しており、**null → 無料が既存の表示契約**。
  - プラン名でのコード分岐は禁止規約のため `isFree` 導出に plan_code は使えず、`is_free` カラム追加は本フィーチャには過剰。
- 対応内容:
  - DTO 契約を確定: `baseAmountJpy: int|null`、**null = 基本料金なし（無料表示）**。docblock に「価格未設定 (null) は AI-CUE の台帳では無料プランを意味する。『お問い合わせ』種別のプランを将来 Plan 行として導入する場合は、この契約（表示状態の明示フィールド追加）を先に見直すこと」を明記。
  - `PricingPlanCard`（AI-CUE 版 molecule）の props 契約を aigenba から変更: `priceAmount: number | null` を受け、**null → 「無料」表示**（0 は AI-CUE では発生しないが、防御的に 0 も「無料」= 同一表示とし Vitest で固定）。aigenba の `contactLabel`（null=お問い合わせ）分岐は移植しない（該当プランが無い機能を作らない。大規模利用の問い合わせはカード外の静的バナーが担う）。
  - Vitest `Pricing.test.ts` / `PricingPlanCard` テストで `null → 無料` / `4980 → ¥4,980／月` を固定。

## [Suggestion] resolveForSource の fragment 対応（`/contact?foo=1#form`）
- 判断: 対応する
- 対応内容: 実装は「`#` があれば fragment 直前に query を挿入」する。テストケースに `/contact#form → /contact?source=landing#form` / `/contact?foo=1#form → /contact?foo=1&source=landing#form` を追加。
