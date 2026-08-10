**指摘**

[Warning] [tests/Architecture/BillingRetentionTargetInventoryTest.php]  
詳細設計 C1d は「起算点 / 補助時計の実在列照合」を `BillingRetentionTargetInventoryTest` の責務として指定していますが、実装ではこの検査を `tests/Feature/Billing/BillingRetentionHorizonTest.php` に移しています。保証自体は存在するため merge block ではありませんが、設計との配置差分です。意図的な逸脱なら実装ノートかテストコメントで「C1d から Feature lane に移した理由」を明記した方がよいです。

**ファイル別判定**

- `app/Console/Commands/Billing/PurgeBillingRetentionCommand.php`: OK
- `app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php`: OK
- `app/Enums/Billing/BillingRetentionExclusion.php`: OK
- `app/Enums/Billing/BillingRetentionTarget.php`: OK
- `app/Models/Billing/StripeWebhookEvent.php`: OK
- `app/Services/Billing/Contracts/BillingRetentionPurger.php`: OK
- `app/Services/Billing/Retention/*`: OK
- `app/Support/Legal/BillingRetention.php`: OK
- `config/legal.php`: OK
- `database/factories/Billing/StripeWebhookEventFactory.php`: OK
- `docs/factories.md`: OK
- `tests/Architecture/BillingRetentionConfigSingleSourceTest.php`: OK
- `tests/Architecture/BillingRetentionTargetInventoryTest.php`: 要修正
- `tests/Feature/Billing/BillingRetentionHorizonTest.php`: OK
- `tests/Feature/Billing/BillingRetentionPurgeTest.php`: OK
- `tests/Support/Billing/BillingRetentionFixtures.php`: OK
- `tests/Unit/DataTransferObjects/Billing/BillingRetentionPurgeResultDtoTest.php`: OK

**総評**

C1 の範囲は守られています。`--apply` なし、console schedule なし、privacy 未編集、TicketLedgerEntry pending は設計どおりです。件数 DTO も任意 metadata なしで、fail-closed / unexpected / expiredRemaining の公開条件は明確です。PII 出力も target 別件数に閉じています。

全体判定: APPROVED