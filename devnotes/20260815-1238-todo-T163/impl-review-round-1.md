提供 diff の範囲では、設計逸脱・課金不変条件違反・PHPStan 回避・DTO/JsonResource 規約違反は見つかりませんでした。

**ファイル別判定**

- `app/Console/Commands/Billing/ReconcileSubscriptionStatus.php`: 問題なし。単一 writer 経由、PII ログ回避、未確認/失敗の扱い、ロック・時間上限の設計に沿っています。
- `app/DataTransferObjects/Billing/RemoteSubscriptionState.php`: 問題なし。
- `app/Enums/Billing/EntitlementDeniedReason.php`: 問題なし。非露出前提の docblock 更新も妥当です。
- `app/Enums/Billing/SubscriptionState.php`: 問題なし。`Unpaid` 分離と `hasUnsettledPayment()` は設計意図どおりです。
- `app/Exceptions/Billing/SubscriptionLookupFailedException.php`: 問題なし。
- `app/Http/Controllers/Onboarding/OnboardingController.php`: 問題なし。支払い未解決時の導線が新規契約でなく支払い更新へ向いています。
- `app/Models/Billing/Subscription.php`: 問題なし。
- `app/Services/Billing/AccountDeletionBillingGuard.php`: 問題なし。docblock の状態名更新のみ。
- `app/Services/Billing/BillingAccess.php`: 問題なし。無料枠すり抜け防止は設計どおりです。
- `app/Services/Billing/CashierStripeGateway.php`: 問題なし。Stripe SDK 例外を境界内で変換し、SDK 型を漏らしていません。
- `app/Services/Billing/Contracts/StripeGatewayInterface.php`: 問題なし。
- `app/Services/Billing/Fakes/FakeStripeGateway.php`: 問題なし。
- `app/Services/Billing/StripeWebhookProcessor.php`: 問題なし。webhook と突き合わせの mapper 共通化は妥当です。
- `app/Services/Billing/SubscriptionService.php`: 問題なし。`past_due_since` 打刻、猶予切れ判定、二重契約 guard、突き合わせ差分判定はいずれも設計と一致しています。
- `app/Services/Billing/SubscriptionSnapshotMapper.php`: 問題なし。
- `app/Support/Billing/PaymentGracePolicy.php`: 問題なし。境界時刻の扱いも設計どおりです。
- `config/billing.php`: 問題なし。
- `database/migrations/*past_due_since*`: 問題なし。backfill の冪等性と no-op down は妥当です。
- `routes/console.php`: 問題なし。daily / `onOneServer` / `withoutOverlapping` / failure report が入っています。
- `tests/Architecture/*`: 問題なし。負のコントロールがあり、空振りしにくい構成です。
- `tests/Feature/Billing/*`, `tests/Feature/Onboarding/*`, `tests/Unit/Billing/*`, `tests/Support/FakeStripeGateway.php`: 問題なし。施策ごとの正負ケース、境界、未確認/失敗、PII、二重契約、金銭非変更まで押さえています。
- `docs/architecture.md`, `docs/billing-gate-inversion-runbook.md`: diff 本体は提示外ですが、補足どおり施策 12 の内容が入っている前提なら問題なしです。

[Critical] なし  
[Warning] なし  
[Suggestion] なし

APPROVED