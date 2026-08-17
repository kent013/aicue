前提: コマンド実行は禁止なので、提示された `git diff -M` と設計文だけを静的に読んだレビューです。

**指摘**

Warning: `devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php` が `echo` を使っています。  
AGENTS.md の「PHP の `echo` / `goto` / `global` は書かない」に反します。devnotes が一部検査から除外されるとしても、規約上は `fwrite(STDOUT, ...)` などへ置き換えるべきです。

Warning: A-6 がまだ未完了です。  
実装報告で「A-6 検証スクリプトの実行結果は未実行」と明記されており、提示 diff にも `rename-verification.md` が見えません。一方で `verify-rename-only.php` の `META_FILES` には同ファイルが必須として載っているため、このままでは A-6d / A-6e / A-10 は満たしたと判定できません。

Suggestion: `BughuntNamingResidualTest` に `docs/TODO.md` を pin 対象として追加した逸脱は妥当です。ただし N-4 の負のコントロールは `docs/TODO-closed.md` だけを使っています。今回増やした `docs/TODO.md` の 1/1 pin についても、同じ述語で件数ずれを検出するケースを 1 つ足すと、逸脱ぶんの保証がより明確になります。

Suggestion: `verify-rename-only.php` の `META_ALLOWED_PREFIXES` で `docs/TODO.md` / `docs/TODO-closed.md` を `str_starts_with()` 判定しているため、理屈上は `docs/TODO.md.backup` のような文字列も許可されます。現状 `META_FILES` にそのような値は無いので実害はありませんが、TODO 2 ファイルは完全一致扱いの方が意図に合います。

**ファイル別判定**

| ファイル | 判定 |
|---|---|
| `.env.bughunt.local.example` | OK。コメント上の旧名追従のみ |
| `app/Providers/BughuntFakesServiceProvider.php` | OK。クラス名変更のみで register/boot の意味差分なし |
| `app/Providers/AppServiceProvider.php` | OK。コメント追従のみ |
| `app/Services/AI/Testing/CannedPromptFake.php` | OK |
| `app/Services/AI/Testing/CannedPromptFakeRegistrar.php` | OK |
| `app/Services/AI/Testing/CannedPromptResponses.php` | OK |
| `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php` | OK |
| `app/Services/Billing/Fakes/FakeStripeGateway.php` | OK |
| `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` | OK |
| `app/Services/Billing/TicketLedgerService.php` | OK |
| `app/Support/ExternalFakes/ExternalFakeDeclaration.php` | OK |
| `app/Support/FakeStorageGate.php` | OK |
| `bootstrap/providers.php` | OK。provider 登録順は維持されている |
| `database/seeders/BughuntStripeSyncSeeder.php` | OK。CLI 文言の名前変更以外の挙動差分なし |
| `scripts/bug-hunt-shard.sh` | OK。provision / reseed とも新 seeder 名に追従 |
| `docs/architecture.md` | OK |
| `docs/testing-browser.md` | OK |
| `tests/Architecture/BughuntNamingResidualTest.php` | ほぼ OK。`docs/TODO.md` 追加は妥当だが、負のコントロール追加を推奨 |
| `tests/Architecture/ExternalFakeWiringInvariantTest.php` | OK。`placementExceptions()` を候補集合に足す設計逸脱は妥当 |
| `tests/Architecture/FakeClassReferenceInvariantTest.php` | OK。候補集合 assertion が入っている |
| `tests/Architecture/LaneExternalFakeBindingTest.php` | OK |
| `tests/Feature/Auth/FakeSocialiteWiringTest.php` | OK |
| `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` | OK |
| `tests/Feature/Billing/TicketBalanceAccountingTest.php` | OK |
| `tests/Feature/Billing/TicketCheckoutTest.php` | OK |
| `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php` | OK |
| `tests/Feature/Database/BughuntStripeSyncSeederTest.php` | OK |
| `tests/Feature/Llm/CannedAnalysisPipelineTest.php` | OK |
| `tests/Feature/Providers/BughuntFakesServiceProviderTest.php` | OK |
| `tests/Pest.php` | OK。fake provider の手動起動経路も追従 |
| `tests/Support/Bughunt/BughuntSeedWiringInventory.php` | OK |
| `tests/Support/ExternalFakes/FakeClassCatalog.php` | OK。配置例外の意味拡張は docblock と assertion で補強されている |
| `tests/Support/ExternalFakes/FakeWiringSourceScanner.php` | OK |
| `devnotes/.../verify-rename-only.php` | 要修正。`echo` 使用と A-6 未完了が残る |

**逸脱 3 点の判定**

1. `docs/TODO.md` の件数 pin 追加: 妥当。丸ごと除外ではなく旧名ごとの件数 pin なので、保証範囲は誇張されていません。
2. `A-6a-imports` 追加: 妥当。import 並べ替え以外を許さない構成なので、A-6a の意図は保たれています。
3. `composer dump-autoload` 必要: 妥当。追跡外 classmap の実行環境問題で、差分としては問題なし。

全体判定: **CHANGES_REQUESTED**  
主な理由は `verify-rename-only.php` の `echo` 規約違反と、A-6 検証が未完了で受け入れ条件を満たしたと判定できないためです。