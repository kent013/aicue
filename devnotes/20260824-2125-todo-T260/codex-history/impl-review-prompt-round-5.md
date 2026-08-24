Round 4 の指摘 2 件に対応した。

## [Warning] `resolveClassName()` の `namespace\X` 解決

先頭要素が `namespace` の綴りを、取り込み表より先に**現在の名前空間からの相対参照**として解くようにした。
相対参照で要素が続かない形 (`namespace\` 単体) は例外にした (fail-closed)。

```php
        $segments = explode('\\', $spelling);
        $first = strtolower($segments[0]);

        // `namespace\X` は現在の名前空間からの相対参照である (`namespace` は予約語なので
        // 本物の名前空間の要素にはならない。取り込み表より先に解く)。
        if ($first === 'namespace') {
            array_shift($segments);
            $rest = implode('\\', $segments);

            if ($rest === '') {
                throw new RuntimeException('unresolvable relative class name: '.$spelling);
            }

            return $resolver['namespace'] === '' ? $rest : $resolver['namespace'].'\\'.$rest;
        }
```

docblock には「解ける形は 3 つ — 完全修飾 / 現在の名前空間からの相対 / 取り込み表かグローバル
fallback で解ける非修飾・限定」と明記した。

自己検査に正例を追加した (`tests/Unit/Architecture/RawEnvGuardStructureTest.php`):

```php
test('正例 7: namespace\ で始まる相対参照を現在の名前空間から解く', function (): void {
    $relative = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(): void
    {
        $x = new namespace\RawEnvChannels();
    }
    PHP);

    $other = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(): void
    {
        $x = new namespace\RawEnvWriteSite();
    }
    PHP);

    expect(RawEnvGuardStructure::constructions($relative, RawEnvSnapshot::class, RawEnvChannels::class))->toHaveCount(1)
        ->and(RawEnvGuardStructure::constructions($other, RawEnvSnapshot::class, RawEnvChannels::class))->toBe([]);
});
```

## [Warning] 全数 green

修正後に全数を 2 回走らせ、いずれも green を確認した。

| 実行 | 結果 |
|---|---|
| 本修正の 1 つ前の状態 | passed — 7834 tests / 7832 passed / 2 skipped / 5 risky / **0 failed** |
| 本修正を含む最終状態 | passed — 7835 tests / 7833 passed / 2 skipped / 5 risky / **0 failed** |

検証コマンド全数 (最終状態):

| コマンド | 結果 |
|---|---|
| `composer test` | passed — 7835 tests / 0 failed |
| `composer phpstan` (level 10) | OK — No errors |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | passed |
| `pnpm typecheck` | passed |
| `pnpm test` | passed — 179 files / 2398 tests |
| `pnpm build` | passed |
| `pnpm typecheck:packages` | passed |
| `pnpm build:packages` | passed |
| `pnpm test:packages` | passed — 10 files / 106 tests |

先に 1 度落ちた `tests/Architecture/BughuntSelfTestExecutionTest.php` は、同一コンテナで
他プロセスが走っているときにプロセスグループの所有確認が揺れる**既存の不安定テスト**である
(失敗メッセージは `echo: write error: Broken pipe` と
`pid=... は存在するが所有確認できない — kill せず pidfile 保持`)。単体では 3 passed、
全数でも 2 回連続 green である。本差分は bug-hunt 基盤に 1 行も触れていない。

## 判定の依頼

残る指摘があれば **具体的に列挙** (分類 / 対象ファイルと関数 / 最小の PHP 断片 / 期待する挙動) すること。
無ければ **APPROVED** と書くこと。判定の 1 語だけの返答はしないこと。
