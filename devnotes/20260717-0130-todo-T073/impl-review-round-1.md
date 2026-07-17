以下、提示差分ベースで **P2 設計一致性 + 安全性重視**でレビューします。  
（実行・書込なし、読み取りレビューのみ）

**app/Actions/Organizations/RenameOrganizationAction.php:1** — REQUEST_CHANGES
- [Warning] P2の主眼（サブスク層+判定モデル）に対して、組織名変更時のStripe同期導入はスコープ拡張です。設計本文には入っていますが、**「aigenba verbatim + 必須逸脱のみ」方針**から見ると side-effect を増やす変更。
- 修正案: P2から外してP9以降へ分離、または「P2必須理由（どのDoD/不変条件を満たすために必須か）」を設計書P2節へ明記し、PRを分割。

**app/Services/Billing/BillingPermissionService.php:1 / app/Policies/OrganizationPolicy.php:37 / database/seeders/PermissionSeeder.php:42** — REQUEST_CHANGES
- [Warning] `manage-billing` 直接付与モデルは **P2 DoD外** の機能追加。aigenba parity観点でも必須ではなく、レビュー観点1「設計にないものを足さない」に抵触気味。
- 修正案: 本PRから除外（P8b/P9等へ分離）。少なくともP2で必要な最小差分（state/deriveEntitlement/hasActiveAccess）に絞る。

**app/Services/Billing/BillingAccess.php:1** — APPROVE
- [Good] `state()` が `plan_code` で判定していない点、read経路no-write、stale境界の排他（`<` vs `>=`）は要件通り。
- [Good] `hasActiveAccess()` の移行ORが1行で、P4削除点が明確。

**app/Services/Billing/SubscriptionService.php:1** — APPROVE
- [Good] `deriveEntitlement()` は cohort C/D 反転要件に整合。
- [Good] `recordPaymentMethodSnapshot()` の monotonic + 行不在 early return + tx/lockForUpdate は要求通り。
- [Suggestion] `ACTIVE_SUBSCRIPTION_STATUSES` を BillingAccess/設計語彙と対応づける短い定数コメント追記があると将来差分監査しやすい。

**app/Services/Billing/StripeWebhookProcessor.php:1** — APPROVE
- [Good] materialize責務をCashier側に維持し、listener側で create しない方針は妥当（subscription_items生成阻害回避）。
- [Good] 冪等マシン周辺を壊していない。

**app/Models/Billing/BillingCheckoutSession.php:1** — APPROVE
- [Good] `organization_id` / `initiated_by_user_id` を fillable 除外は不変条件 #1 に適合。
- [Good] 逸脱報告(1)は妥当。

**database/migrations/2026_07_17_000200_create_billing_checkout_sessions_table.php:1** — APPROVE
- [Good] P2前倒しテーブルとして必要最小列でまとまっている。

**database/migrations/2026_07_17_000210_add_has_payment_method_to_subscriptions.php:1**  
**database/migrations/2026_07_17_000220_backfill_has_payment_method_on_subscriptions.php:1** — APPROVE
- [Good] cohort C空化のための分離backfill、冪等ガードありで妥当。

**app/Http/Controllers/Billing/BillingController.php:1** — APPROVE
- [Good] Service委譲化、価格未同期時のハンドリング維持。
- [Suggestion] `InvalidArgumentException` をドメイン例外へ置換すると意図が明確（必須ではない）。

**テスト群（BillingAccessState/SubscriptionEntitlement/SubscriptionSnapshotSync/HasPaymentMethodBackfill 等）** — APPROVE
- [Good] cohort A〜I 固定、特に C遮断・D許可・I移行OR を明示。
- [Good] stale境界、read no-write、monotonic、materialize順序リスクの固定ができている。
- [Suggestion] Architecture test に「`state()`で`plan_code`判定禁止」を文字列検査で追加すると将来回帰にさらに強い。

**実装者申告の4逸脱妥当性**
- 1) fillable除外: **妥当（必須）**
- 2) `assertCheckoutReady()` 非移植: **妥当**
- 3) `applySubscriptionSnapshot` void化: **妥当**
- 4) `current_period_end` null時は不書込: **妥当**

**全体判定**
- **CHANGES_REQUESTED**

理由は主に1点です。  
P2の本筋（判定モデル移植）自体は高品質ですが、`BillingPermissionService` と `RenameOrganizationAction` 系が **P2の最小必要差分を超える機能拡張** になっており、今回の最重要方針「aigenba verbatim / 不要追加なし」と緊張します。  
この2塊を分離（別PR化）すれば、P2本体は **APPROVED** 相当です。