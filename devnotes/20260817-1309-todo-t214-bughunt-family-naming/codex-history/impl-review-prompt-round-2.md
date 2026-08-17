Round 1 の指摘 4 件をすべて対応した。再レビューを依頼する。

## 対応マトリクス

| # | 分類 | 指摘 | 対応 |
|---|---|---|---|
| 1 | Warning | `verify-rename-only.php` の `echo` が AGENTS.md「禁止する文」に反する | 出力を `fwrite(STDOUT, ...)` の 1 関数 `out()` へ集約した |
| 2 | Warning | A-6 が未実行で受け入れ条件を判定できない | 実装をコミットしたうえで実走した。出力は下記のとおり終了コード 0 (不合格 0 件)。母集団を `main...HEAD` から取る設計上、コミット前には実行できない |
| 3 | Suggestion | N-4 の負のコントロールに `docs/TODO.md` の 1/1 pin のケースが無い | N-4 に (f) を追加した |
| 4 | Suggestion | `META_ALLOWED_PREFIXES` の接頭辞判定だと `docs/TODO.md.backup` まで通る | TODO 台帳 2 冊を `META_ALLOWED_EXACT` の完全一致へ分離した |

## 対応 1 の差分 (verify-rename-only.php)

```php
/** 標準出力へ書く (AGENTS.md が `echo` を禁じているため `fwrite` を使う) */
function out(string $text): void
{
    fwrite(STDOUT, $text);
}

out("# T214 改名の差分検証 (A-6)\n\n");
out('`php devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php`'
    ." の出力そのままである。\n\n");
out("| ファイル | 状態 | 判定 |\n|---|---|---|\n");
foreach ($rows as [$path, $symbol, $verdict]) {
    out("| `{$path}` | {$symbol} | {$verdict} |\n");
}
```
(以降の出力もすべて `out()` 経由。`echo` は 1 つも残っていない)

## 対応 3 の差分 (tests/Architecture/BughuntNamingResidualTest.php の N-4)

```php
    // (f) もう 1 冊の TODO 台帳 (docs/TODO.md は旧名ごとに 1 件ずつ) でも同じ境界が働く
    expect(bughuntNamingViolationsIn('docs/TODO.md', "{$seeder} {$provider}"))->toBe([]);
    expect(bughuntNamingViolationsIn('docs/TODO.md', "{$seeder} {$seeder} {$provider}"))->toHaveCount(1);
    expect(bughuntNamingViolationsIn('docs/TODO.md', "{$seeder} {$seeder}"))->toHaveCount(2);
```

## 対応 4 の差分 (verify-rename-only.php)

```php
/** A-6e に載せてよいパスの接頭辞 (これ以外が META_FILES にあれば不合格) */
const META_ALLOWED_PREFIXES = [
    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/',
];

/**
 * A-6e に載せてよい TODO 台帳 (**完全一致**で扱う)。
 *
 * 接頭辞判定にすると `docs/TODO.md.backup` のような別ファイルまで通ってしまうため、
 * 2 冊だけを名指しする。
 *
 * @var list<string>
 */
const META_ALLOWED_EXACT = [
    'docs/TODO.md',
    'docs/TODO-closed.md',
];
```
```php
    $allowed = in_array($path, META_ALLOWED_EXACT, true);
    foreach (META_ALLOWED_PREFIXES as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $allowed = true;
        }
    }
```

## A-6 検証スクリプトの実走結果 (終了コード 0)

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
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/codex-history/impl-review-prompt-round-1.md` | A | A-6e (比較対象外) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/impl-review-round-1.md` | A | A-6e (比較対象外) |
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

- 対象ファイル数: 38
- 不合格: 0

判定: 合格 (意図しない実行コード差分は無い)。

> 保証範囲: 示せるのはここまでである。振る舞いの同値性そのものは証明しない
> (autoload・キャッシュ・リポジトリ外の実行手順・動的に組み立てるクラス名は対象外)。

## 検証スクリプトが空振りしていないことの実測 (負のコントロール)

実際に 2 つの改変を入れて赤くなることを確認した (確認後に戻し、作業ツリーは clean に戻した)。

1. `tests/Pest.php` へ `use RuntimeException;` を 1 行足す
   → `A-6a: 逆置換しても旧内容とバイト一致しない: tests/Pest.php`
2. `tests/Architecture/ExternalFakeWiringInvariantTest.php` の 3-10 へ `$unused = 1;` を足す
   → `A-6b: 許可した追加以外の実行トークン差分がある: tests/Architecture/ExternalFakeWiringInvariantTest.php`

同時に段 0 の clean 検査も `段 0: 作業ツリーが clean ではない` で赤くなった (不合格 3 件)。

## その他の検証結果 (再掲・すべて green)

- `composer test`: 5636 tests / 5634 passed / 0 failed / 2 skipped
- `composer phpstan`: 987 files / No errors
- `vendor/bin/pint --test`: pass
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages`: pass
- `tests/Architecture/BughuntNamingResidualTest.php`: N-1〜N-5 の 5 test / 27 assertions すべて緑

## 質問

上記対応で全体判定を APPROVED にできるか。残る Critical / Warning があれば指摘してほしい。
