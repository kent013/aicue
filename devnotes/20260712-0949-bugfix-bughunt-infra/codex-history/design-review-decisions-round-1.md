# 対応マトリクス: design-review Round 1

## [Critical] A3: FakeTicketCheckoutGateway の sessionId が idempotencyKey ほぼ生使用
- 判断: 対応する
- 根拠: attempt_token は ULID 前提だが、gateway 契約上 idempotencyKey の文字種・長さは呼び出し側依存。固定長トークン化が downstream (stripe_session_id 列・URL) に対して安全。
- 対応内容: `hash('sha256', $idempotencyKey)` の先頭 32 桁で `cs_bughuntfake_{token}` を生成 (決定論・固定長)。設計のコード例を修正。

## [Critical] B1: paidPlanCodes() の pluck が list<mixed> になり型保証が弱い
- 判断: 対応する
- 対応内容: `->map(fn (Plan $plan): string => $plan->code)->values()->all()` を正式のコード例に昇格 (「補足があれば置換」ではなく最初から map で書く)。

## [Warning] A2: CashierSubscriptionCheckoutGateway に PortalConfigurationSpec の use が無い
- 判断: 反論する (事実誤認)
- 根拠: `PortalConfigurationSpec` は `App\Services\Billing` 名前空間にあり、`CashierSubscriptionCheckoutGateway` も同一名前空間のため `use` 不要 (未解決参照にならない)。設計書に「同一名前空間のため use 不要」を注記して誤読を防ぐ。

## [Warning] A2: BillingController::checkout の戻り型の意図明確化
- 判断: 対応する — docblock に「price 不在時の back() 分岐のため RedirectResponse を含む」を追記。

## [Warning] A3: FakeExternalsServiceProvider サンプルの未使用 import
- 判断: 対応する — コード例から `use App\Services\Billing\CashierTicketCheckoutGateway;` を削除 (既に注記済みだったが例自体を清書)。

## [Warning] A3: FakeExternalUrl::neutralReturn の空文字防御
- 判断: 対応する — `Assert::stringNotEmpty($appUrl)` を追加。

## [Warning] B1: run() の method injection の慣習
- 判断: 反論する (現状維持)
- 根拠: Laravel 公式の Seeder DI 作法であり、型安全性で `app()` 呼びより優位 (レビュー自身も「型安全性は method injection 優位」と認める)。既存 ManualTestSeeder は `app(OrganizationProvisioningService::class)` を使うが、これはループ内 1 箇所のみの利用。新規 seeder は run 全体で使うため signature injection が明確。

## [Warning] B1: stripe_id 決定論値の一意性説明
- 判断: 対応する — 「`sub_bughunt_{org id}` は org 単位一意 (subscriptions.stripe_id UNIQUE と両立)」コメントを追記。

## [Warning] C2: filament_version_from_lock が空文字時の可観測性
- 判断: 対応する — 空文字時に「composer.lock から filament version を解決できず毎回 publish 判定になる」旨の echo (stderr) を追加。

## [Warning] C2: die メッセージの運用案内
- 判断: 対応する — 「artisan filament:assets の出力を確認すること」を die メッセージへ追記。

## [Suggestion] A1: config:cache 運用への明示 → 対応する (A1 リスク欄に「bughunt provision は clear_stale_config 済み・本番は ProductionEnvGuard が検査」を明記)
## [Suggestion] A2: DTO の URL 形式検証 (filter_var) → 見送る (内部 DTO。呼び出し元は route()/Cashier 由来の URL のみで、stringNotEmpty で十分。過剰検証は複雑化)
## [Suggestion] A3: provider テストの app refresh → 対応不要の注記 (Pest はテスト毎に app を再構築するため register() 再実行の汚染はテスト間に漏れない)。テスト内での env 変更は try/finally 復元を維持
## [Suggestion] B1: 「fake_externals=true でも env=testing なら no-op」ケース → 対応する (BughuntBillingSeederTest / BughuntOAuthSeederGuardTest に 1 ケース追加)
## [Suggestion] C1: shouldSeed の意図を class docblock へ → 対応する
## [Suggestion] C2: cmd_assets_check への filament marker 整合チェック → 見送る (self-test フィクスチャ改修が必要になりスコープ超過。レビューも「今回必須ではない」)
