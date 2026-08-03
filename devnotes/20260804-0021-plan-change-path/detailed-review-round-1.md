**全体判定**  
- **CHANGES_REQUESTED**

**レビュー方針（仮説）**  
- 仮説1: 「課金変更の冪等性」と「単一 writer（webhook）」を壊していないこと。  
- 仮説2: stale UI 検知が UX を守りつつ、正当な再操作を不必要に拒否しないこと。  
- 仮説3: PHPStan Lv10 / DTO-Inertia / 既存Billing作法との整合が保たれること。  

**施策別判定**  
- **A: Gateway swap 追加** → **APPROVE**  
- **B: `SubscriptionService::changePlan()` 新設** → **REQUEST_CHANGES**  
- **C: route/FormRequest/Controller** → **APPROVE**  
- **D: Plans画面の分岐/文言** → **REQUEST_CHANGES**  
- **E: docs/bug-hunt inventory** → **APPROVE**

**指摘（Critical / Warning / Suggestion）**  
- **[Critical]** `@throws StaleP1anChangeException` の型名タイポは PHPDoc 型解決エラーの原因になります（`app/Services/Billing/SubscriptionService.php:changePlan` 付近）。  
  - 修正案: `StalePlanChangeException` に修正し、IDE/静的解析で参照解決できる状態に統一。  
- **[Warning]** `current_plan_code` を `required|string|enum` にしているため、`currentPlanCode === null` かつ `hasChangeableSubscription === true` のケースで恒常的422になり得ます（`app/Http/Requests/Billing/ChangePlanRequest.php:1`, `resources/js/pages/Billing/Plans.svelte:1`）。  
  - 修正案: `current_plan_code` を `nullable` にし、`changePlanLocked` 側で「nullならstale判定スキップ（または別メッセージ）」へ分岐。  
- **[Warning]** stale判定を「同一プラン no-op」より先に置くと、実態が既に目標プランでも stale で弾くUXになります（`app/Services/Billing/SubscriptionService.php:changePlanLocked`）。  
  - 修正案: 先に `if ($org->plan_code === $plan->code) return AlreadyOnTargetPrice;` を評価し、その後 stale 判定。  
- **[Warning]** swap payload で `quantity=1` を強制する一方、remote item の `quantity` 妥当性を検証していません（`app/Services/Billing/CashierStripeGateway.php:swapSubscriptionPrices`）。将来/手動操作で `quantity!=1` が入ると暗黙補正になります。  
  - 修正案: `normalizeItems` に `quantity` を含め、`count===1 && quantity===1` 以外は `UnexpectedSubscriptionShapeException` で fail-closed。  
- **[Suggestion]** 確認ダイアログの「即時反映」と成功flashの「数分かかる場合」が認知上ぶつかります（`resources/js/pages/Billing/Plans.svelte:confirmMessage`）。  
  - 改善案: 「Stripe側には即時反映され、画面反映は数分かかる場合があります」に統一。

**総評**  
- 設計の骨格（Service委譲、DTO/Inertia、idempotency二層、ProhibitsProtectedKeys、単一writer維持）は非常に良いです。  
- ただし上記 **Critical 1件 + Warning 3件** は本番運用時の詰まり/誤拒否に直結するため、修正後に再レビュー推奨です。  
- 特に追加テストとして、`current_plan_code=null`、`already-target + stale expected`、`remote quantity!=1` を必須ケースに入れてください。