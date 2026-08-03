## 総合判定
APPROVED

## [Critical] なし
- マージ前に必須修正となる欠陥は確認できませんでした。

## [Warning] なし

## [Suggestion]
- ローリングデプロイ窓の説明責務をさらに強めるなら、`customer.subscription.created` と `invoice.paid(subscription_create)` を同一 org へ順序違いで連続投入し、「signup は高々 1 回」を明示固定するテストを 1 本追加すると、将来の文脈共有がより堅くなります（現状実装でもロジック上は安全）。

## 良い点
- 登録経路から grant/marker を一体撤去できており、「marker だけ立つ」後退を避けています（`app/Actions/Fortify/CreateNewUser.php:103`）。
- paid 側を `customer.subscription.created` 契機へ移し、org 行 lock + 条件付き marker claim + 同一 tx grant の対称パターンを実装できています（`app/Services/Billing/SubscriptionService.php:45`）。
- `invoice.paid` から signup grant を除去し、月次付与責務に限定できています（`app/Services/Billing/StripeWebhookProcessor.php:343`）。
- `claimSignupGrantMarker()` の private 化が完了し、移行専用 API の露出が閉じています（`app/Services/Billing/PersonalPlanService.php:126`）。
- LP 文言が実挙動（有効化時・初回1回）へ整合し、Free 名称も Personal へ揃っています（`resources/js/pages/Welcome.svelte:348`、`resources/js/pages/Pricing.svelte:166`）。
- 追加/更新テストが不変条件を実際に刺しており、特に rollback 時 marker 非残存、re-subscribe 非再付与、free/paid 先後で非二重付与を押さえられています（`tests/Feature/Billing/SignupGrantOnActivationTest.php:79`）。