# design-review Round 3: Round 2 指摘への対応報告

## 対応マトリクス

### [Warning] 施策3: PricingPlanDto::baseAmountJpy (null) と PricingPlanCard (0=無料) の受け渡し規約不一致 → 対応

null の意味を確定し、カード契約を AI-CUE 用に定義した:

- **DTO 契約**: `baseAmountJpy: int|null` を維持し、**null = 基本料金なし = 無料表示**。根拠: AI-CUE のプラン台帳の既存意味論は「plan_prices (base) を持たないプラン = Checkout 対象外の無料プラン」（PlanSeeder docblock）で、既存 `Billing/Index.svelte` も `formatPrice(null) => "無料"` を表示済み = **null → 無料は既存の表示契約**。プラン名でのコード分岐は禁止規約のため plan_code からの isFree 導出はせず、`is_free` カラム追加は本フィーチャに過剰。
- **将来ガード**: 「『お問い合わせ』種別のプランを Plan 行として導入する場合は、null の多義化を避けるため表示状態の明示フィールド追加を先に行うこと」を DTO docblock（設計書とも同文）に明記。
- **カード契約**: AI-CUE 版 `PricingPlanCard` は `priceAmount: number | null` を受け、**null → 「無料」**（0 も防御的に同一表示）。aigenba の `contactLabel`（null=お問い合わせ）分岐は移植しない — 該当プランが存在しない機能を作らない（大規模利用の問い合わせはカード外の静的バナー）。
- **テスト固定**: Vitest で `null → 無料` / `0 → 無料` / `4980 → ¥4,980／月` を PricingPlanCard 単体 + Pricing ページ双方で固定、と設計に明記。

### [Suggestion] resolveForSource の fragment 対応 → 対応
`#fragment` があれば fragment 直前に query を挿入する実装とし、テストに `/contact#form → /contact?source=landing#form` / `/contact?foo=1#form → /contact?foo=1&source=landing#form` を追加（波及変更に明記）。

再判定（APPROVED / CHANGES_REQUESTED）を依頼する。
