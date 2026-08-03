了解しました。**提供された差分テキストのみ**で静的レビューしました（コマンド実行なし）。

**ファイル別判定**
- `app/Services/Billing/Contracts/StripeGatewayInterface.php:37` — 実装契約Aに一致。SDK例外/オブジェクト非露出、`swapSubscriptionPrices` 追加は妥当。指摘なし。
- `app/Services/Billing/CashierStripeGateway.php:24` — Aの中核要件（remote照合→同一時update抑止、idempotency key透過、fail-closed shape検証、API例外変換）を満たす。`final`解除＋seam化は逸脱理由が技術的に妥当。指摘なし。
- `app/Services/Billing/Fakes/FakeStripeGateway.php:38` — Fake実装追加は契約整合。指摘なし。
- `app/Enums/Billing/SubscriptionSwapOutcome.php:1` — 設計どおり。指摘なし。
- `app/Exceptions/Billing/PlanChangeFailedException.php:1` / `app/Exceptions/Billing/PlanChangeNotAllowedException.php:1` / `app/Exceptions/Billing/StalePlanChangeException.php:1` — 例外境界設計に一致、内部情報漏洩防止も適切。指摘なし。
- `app/Services/Billing/SubscriptionService.php:560` — Bの段0〜5順序・ロック・2層冪等・`organizations.plan_code`非更新を満たす。**非交渉事項（writer一本化/二重proration防止）を保持**。指摘なし。
- `app/Http/Requests/Billing/ChangePlanRequest.php:1` — C要件（`present+nullable`、ULID、保護キー拒否）を満たす。指摘なし。
- `app/Http/Controllers/Billing/BillingController.php:289` — C要件（薄いController、例外変換境界、422/flash運用、`response()->json()`非使用）を満たす。指摘なし。
- `routes/web.php:327` — `billing.plan.change` 追加は運用契約と整合。Portal `subscription_update` 無効方針も維持。指摘なし。
- `app/DataTransferObjects/Billing/BillingPlansPageDto.php:23` / `resources/js/types/billing.ts:95` / `resources/js/pages/Billing/Plans.svelte:34` / `app/Http/Controllers/Billing/BillingController.php:151` — D要件（DTO↔TS shape同期、送信先分岐、stale期待値分離、CTA disabled禁止維持）を満たす。指摘なし。
- `docs/architecture.md:285` / `.claude/skills/app-bug-hunt/operations.md:9` / `app/Services/Billing/PortalConfigurationSpec.php:8` / `lang/ja/validation.php:213` — E要件・関連ドキュメント整合を満たす。指摘なし。
- `tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php:1` / `tests/Feature/Billing/SubscriptionSwapGatewayTest.php:1` / `tests/Feature/Billing/SubscriptionPlanChangeTest.php:1` / `tests/Feature/Billing/PlanChangeEndpointTest.php:1` / `tests/Feature/Billing/BillingPlansPageTest.php:105` / `tests/js/pages/Billing/Plans.test.ts:43` — 設計test_planを高い粒度でカバー。逸脱2（Cashier `valid()`意味論反映）は妥当。

**指摘（分類）**
- [Suggestion] `app/Http/Requests/Billing/ChangePlanRequest.php:40`  
  `plan_code` が `exists` のみで、`is_active` 判定はController側の `firstOrFail` 依存です。UX一貫性を高めるなら Request 側で active 条件まで吸収し、404ではなく422に寄せる選択肢があります（非ブロッカー）。
- [Suggestion] `tests/Feature/Billing/SubscriptionPlanChangeTest.php:241`  
  `enterprise` 422 未テストは、現Seeder制約の説明が成立しているため現状許容。ただし将来 `enterprise` seed 導入時は即テスト追加推奨。

**全体判定**
- **APPROVED**（Blockingな [Critical]/[Warning] は見当たりません）