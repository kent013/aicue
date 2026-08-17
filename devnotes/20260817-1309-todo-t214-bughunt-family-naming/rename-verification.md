# T214 改名の差分検証 (A-6)

`php devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php` の出力そのままである。

| ファイル | 状態 | 判定 |
|---|---|---|
| `.env.bughunt.local.example` | M | A-6a 合格 (名前の置換のみ) |
| `app/Providers/AppServiceProvider.php` | M | A-6a 合格 (名前の置換のみ) |
| `app/Providers/BughuntFakesServiceProvider.php` | R | A-6a 合格 (名前の置換のみ) |
| `app/Services/AI/Testing/CannedPromptFake.php` | M | A-6a 合格 (名前の置換のみ) |
| `app/Services/AI/Testing/CannedPromptFakeRegistrar.php` | M | A-6a 合格 (名前の置換のみ) |
| `app/Services/AI/Testing/CannedPromptResponses.php` | M | A-6a 合格 (名前の置換のみ) |
| `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php` | M | A-6a 合格 (名前の置換のみ) |
| `app/Services/Billing/Fakes/FakeStripeGateway.php` | M | A-6a 合格 (名前の置換のみ) |
| `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` | M | A-6a 合格 (名前の置換のみ) |
| `app/Services/Billing/TicketLedgerService.php` | M | A-6a 合格 (名前の置換のみ) |
| `app/Support/ExternalFakes/ExternalFakeDeclaration.php` | M | A-6a 合格 (名前の置換のみ) |
| `app/Support/FakeStorageGate.php` | M | A-6a 合格 (名前の置換のみ) |
| `bootstrap/providers.php` | M | A-6a-imports 合格 (import 順のみ) |
| `database/seeders/BughuntStripeSyncSeeder.php` | R | A-6a 合格 (名前の置換のみ) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-decisions-round-1.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-decisions-round-2.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-prompt-round-1.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-prompt-round-2.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-prompt-round-3.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/impl-review-round-1.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/impl-review-round-2.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/impl-review-round-3.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/rename-verification.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php` | A | A-6e (比較対象外) |
| `docs/architecture.md` | M | A-6a 合格 (名前の置換のみ) |
| `docs/testing-browser.md` | M | A-6a 合格 (名前の置換のみ) |
| `scripts/bug-hunt-shard.sh` | M | A-6a 合格 (名前の置換のみ) |
| `tests/Architecture/BughuntNamingResidualTest.php` | A | A-6c (比較対象外) |
| `tests/Architecture/ExternalFakeWiringInvariantTest.php` | M | A-6b 合格 (許可した追加のみ) |
| `tests/Architecture/FakeClassReferenceInvariantTest.php` | M | A-6b 合格 (許可した追加のみ) |
| `tests/Architecture/LaneExternalFakeBindingTest.php` | M | A-6a 合格 (名前の置換のみ) |
| `tests/Feature/Auth/FakeSocialiteWiringTest.php` | M | A-6a 合格 (名前の置換のみ) |
| `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` | M | A-6a 合格 (名前の置換のみ) |
| `tests/Feature/Billing/TicketBalanceAccountingTest.php` | M | A-6a 合格 (名前の置換のみ) |
| `tests/Feature/Billing/TicketCheckoutTest.php` | M | A-6a 合格 (名前の置換のみ) |
| `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php` | M | A-6a 合格 (名前の置換のみ) |
| `tests/Feature/Database/BughuntStripeSyncSeederTest.php` | R | A-6a 合格 (名前の置換のみ) |
| `tests/Feature/Llm/CannedAnalysisPipelineTest.php` | M | A-6a 合格 (名前の置換のみ) |
| `tests/Feature/Providers/BughuntFakesServiceProviderTest.php` | R | A-6a 合格 (名前の置換のみ) |
| `tests/Pest.php` | M | A-6a 合格 (名前の置換のみ) |
| `tests/Support/Bughunt/BughuntSeedWiringInventory.php` | M | A-6a-imports 合格 (import 順のみ) |
| `tests/Support/ExternalFakes/FakeClassCatalog.php` | M | A-6b 合格 (許可した追加のみ) |
| `tests/Support/ExternalFakes/FakeWiringSourceScanner.php` | M | A-6a 合格 (名前の置換のみ) |

- 対象ファイル数: 43
- 不合格: 0

判定: 合格 (意図しない実行コード差分は無い)。

> 保証範囲: 示せるのはここまでである。振る舞いの同値性そのものは証明しない
> (autoload・キャッシュ・リポジトリ外の実行手順・動的に組み立てるクラス名は対象外)。
