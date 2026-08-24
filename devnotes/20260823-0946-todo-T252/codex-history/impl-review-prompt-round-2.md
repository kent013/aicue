Round 1 の指摘に対する対応を報告する。以下の対応マトリクスと修正内容を確認し、再レビューせよ。
出力形式は Round 1 と同じ (ファイルごとの判定 / [Critical] [Warning] [Suggestion] 分類 /
最後に `APPROVED` または `CHANGES_REQUESTED` の 1 語)。

---

# 対応マトリクス: impl-review Round 1

## [Critical] 設計固有の PHPStan コマンドが 4 エラー残る (ArchBaselineTest)

- 判断: **反論する** (実装は変えない。根拠を検証可能な形で残す)
- 根拠:
  - 4 エラーはすべて禁止表明 4 行に対するもので、**本アプリのコードを 1 行も含まない
    次の 2 行で完全に再現する**:

    ```php
    $call = arch('x');
    $call->expect(['sha1'])->not->toBeUsed()->ignoring([]);
    ```

    実測: `vendor/bin/phpstan analyse --level=10` に上記だけのファイルを渡すと
    `method.notFound` 1 + `property.nonObject` 1 + `method.nonObject` 2 = **同じ 4 件**。
    つまり「本実装の書き方」ではなく **Pest arch を使う限りどう書いても出る vendor 側の
    型情報の欠落**である (`Pest\Arch\Autoload` が `Plugin::uses(Architectable::class)` で
    実行時に生やすため、`TestCall` に静的な型が無い)。
  - 消す手段は 3 つしかなく、すべて禁止か設計違反である:
    (1) `@phpstan-ignore` / baseline → **禁止事項 2**
    (2) `mixed` への widen / `TestCall` を universalObjectCrate 化 → **禁止事項 2** かつ
        `phpstan.neon` の変更 (詳細設計が「設定ファイルは変更しない」と明記)
    (3) チェーンを書き換える → S4 が `EXPECTED_CHAIN_TOKENS` で pin している形を崩す。
        そもそも Pest arch を使わない実装は TODO 自体の否定になる
  - pest の `extension.neon` を include しても解消しない (登録するのは
    `HigherOrderTapProxy` / `Expectation` の universal object crate だけで `TestCall` は対象外)。
  - 詳細設計の受入条件の文言は「**1 度確認する**」であり、0 エラーを要求していない。
    意味のある部分 (`tests/Support/Architecture/` の走査器 3 本 + 共通入口 +
    gate の自己検査部 + 走査器の負例) は **0 エラー**である。
- 対応内容: gate の docblock に **2 行の再現手順**と「pest の extension.neon でも解消しない」
  事実を書き足し、レビュアーが数秒で追試できるようにした。実装は変えていない。

## [Warning] S4 がチェーンの文しか固定しておらず、7 規則が実際に登録されたことを固定していない

- 判断: **対応する**
- 根拠: 指摘のとおり `if (false) { … }` で囲めば**綴りを 1 文字も変えずに** 7 本の表明を
  無効化できる。S1〜S5 はすべて緑のままなので、gate が静かに無力化する経路が実在した。
- 対応内容:
  - `ArchSurfaceScanner::tokensBefore()` (直前 N トークンの綴り列。範囲外は例外) と
    `ArchSurfaceScanner::braceDepthAt()` (その位置で開いたままの波括弧の深さ) を新設
  - `ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS` を追加
    (`foreach ( ArchBaseline :: ruleIds ( ) as $ruleId ) {` の 11 トークン)
  - gate に **S4-3b** を追加: 唯一の `arch` 呼び出しの直前 11 トークンが期待形と完全一致し、
    その `foreach` の位置で**波括弧の深さが 0** (= ファイル最上位)、`arch` の位置で深さ 1 であること
  - 走査器の負例を追加: **13b** (tokensBefore の正例と範囲外例外) /
    **13c** (`if (false) {` で囲むと**綴りは同一のまま**深さだけが 0 → 1 に変わることを固定) /
    **13d** (文字列補間の `{$a}` の開き波括弧を数えないと深さが -1 になることを固定)
  - `braceDepthAt` に「深さが負」の分岐は**置かない**。`TOKEN_PARSE` を通った入力では
    波括弧の対応が構文として保証され到達しないため (共通規約 (d))。docblock に明記した
  - 残る限界 (Pest への**登録件数そのもの**を実行時に数えてはいない) は指摘の
    「少なくとも構造まで検査せよ」の線で止めた。実行時に数えるには
    `Pest\TestSuite` の内部表現へさらに結合する必要があり、費用に見合わないと判断した

## [Warning] ArchTokenStream 共有後の fail-closed 契約が公開入口ごとに固定されていない

- 判断: **対応する**
- 根拠: 妥当。現状の不正 PHP 負例は `GlobalFunctionCallScanner` 経由の 1 本だけで、
  将来 `ArchSurfaceScanner` / `VendorArchPresetReader` が共通入口を外しても正例は通り得る。
- 対応内容: 負例 **7b** を追加し、`identifierSites` / `functionNameSites` /
  `dynamicMemberSites` / `statementTokens` / `tokensBefore` / `braceDepthAt` /
  `VendorArchPresetReader::forbiddenSymbolsFromSource` の **公開境界 7 つすべて**で
  トークン化できない入力が `RuntimeException` になることを固定した。

## [Suggestion] ProcessBarrier のコメントの主張が広すぎる

- 判断: **対応する**
- 根拠: `$reader(...)` 自体が可変 callable の第一級 callable 構文であり、S4 が明示的に
  保証範囲外とする経路である。「callable 経由の迂回口を塞ぐ」は誇張だった
  (AGENTS.md §検出力の主張の書き方に照らして不適切)。
- 対応内容: 「S4 は `fromCallable` **という綴り**を 0 件に固定するのでここでは使わない。
  可変 callable 経路そのものは S4 の保証範囲外であり、この書き換えで塞がるものではない」
  へ記述を狭めた。

## [その他] `pnpm test` の 1 件失敗

- 判断: **本 TODO の範囲外として報告する** (実装は変えない)
- 根拠: `tests/js/architecture/file-input-accept-source-inventory.test.ts` の失敗は
  **clean な main (worktree ではないリポジトリルート・作業ツリー無変更) で同じ内容が再現する
  先行破損**である (`pages/Settings/Security.svelte` の生 HTML 免除が実測に無い /
  件数 pin 不一致)。本実装は `resources/js` を 1 行も変更しておらず、因果関係が無い。
  直すには `tests/js/support/file-input-accept-inventory.ts` の目録更新か
  `Settings/Security.svelte` の是正が要り、どちらも T252 の設計に無い別件である
  (詳細設計は「アプリコード・`resources/` は 1 行も変更しない」と明記)。
- 対応内容: 親エージェントへ「main 先行破損 / 別 TODO で追跡すべき」として報告する。

---

## 修正後の実測

- `composer test -- --filter=ArchBaseline`: **78 tests / 78 passed / 160 assertions**
  (Round 1 時点は 73 tests。gate に S4-3b の 1 本、走査器の負例に 7b / 13b / 13c / 13d の 4 本を追加)
- `vendor/bin/phpstan analyse --level=10 tests/Support/Architecture tests/Architecture/ArchBaselineTest.php tests/Unit/Architecture/ArchBaselineScannerTest.php`:
  **4 errors** (Round 1 と同じ 4 件。すべて禁止表明 4 行。走査器・共通入口・自己検査部・負例は 0 件)
- `vendor/bin/pint --test`: passed
- `composer phpstan` (level 10 / app config database routes): No errors

## PHPStan 4 エラーの再現 (本アプリのコードを 1 行も含まない)

ファイル `/tmp/.../probe.php`:

```php
<?php

declare(strict_types=1);

$call = arch('x');
$call->expect(['sha1'])->not->toBeUsed()->ignoring([]);
```

実行結果 (`vendor/bin/phpstan analyse --level=10 --no-progress probe.php`):

```
errors: 4
  line 6: Call to an undefined method Pest\PendingCalls\TestCall::expect().   [method.notFound]
  line 6: Cannot access property $not on mixed.                              [property.nonObject]
  line 6: Cannot call method ignoring() on mixed.                            [method.nonObject]
  line 6: Cannot call method toBeUsed() on mixed.                            [method.nonObject]
```

`vendor/pestphp/pest-plugin-arch/src/Autoload.php` は `Plugin::uses(Architectable::class)` で
実行時に concern を混ぜており、`Pest\PendingCalls\TestCall` の宣言 (`@mixin HigherOrderCallables|TestCase|Testable`)
には `expect()` が現れない。`vendor/pestphp/pest/extension.neon` が登録するのは
`Pest\Support\HigherOrderTapProxy` と `Pest\Expectation` の universalObjectCrate だけで `TestCall` は対象外。

---

## 修正後の全文 (変更した箇所)

### `tests/Support/Architecture/ArchBaseline.php` の追加定数

```php
    public const array EXPECTED_CHAIN_HEADER_TOKENS = [
        'foreach', '(', 'ArchBaseline', '::', 'ruleIds', '(', ')', 'as', '$ruleId', ')', '{',
    ];
```

### `tests/Support/Architecture/ArchSurfaceScanner.php` の新設メソッド 2 本

```php
    /**
     * 指定位置の**直前** `$length` 個の有意トークンの綴り列を返す (チェーンを囲む構文の照合用)。
     *
     * ★範囲外は**黙って短い列を返さず例外**にする (fail-closed)。
     *
     * @return list<string>
     */
    public static function tokensBefore(string $source, int $index, int $length): array
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);

        Assert::greaterThanEq($length, 0, "取り出す長さが負である: {$length}");
        Assert::greaterThanEq($index - $length, 0, "走査開始位置が範囲外である: {$index} - {$length}");
        Assert::lessThanEq($index, count($tokens), "走査開始位置が範囲外である: {$index}");

        $texts = [];
        for ($cursor = $index - $length; $cursor < $index; $cursor++) {
            $texts[] = $tokens[$cursor]['text'];
        }

        return $texts;
    }

    /**
     * 指定位置の時点で**開いたままの波括弧の深さ**を返す。
     *
     * ★「チェーンがファイル最上位にあること」を固定するために使う。
     *   `if (false) { … }` のような**実行されない位置**へチェーンを移す形は、
     *   綴り列の照合だけでは見抜けない (チェーン自身の綴りは変わらない) ため、
     *   囲みの深さを別に測る。
     * ★文字列補間の `{$x}` / `${x}` も**開き波括弧として数える** (対応する `}` が
     *   単一文字トークンとして現れるので、数えないと深さがずれる)。
     * ★**深さが負になる場合を扱う分岐は持たない**。入力は `TOKEN_PARSE` を通っており
     *   波括弧の対応は構文として保証されるので、その状態は到達しない
     *   (到達できない結果を作らない = 共通規約 (d))。トークン化できない入力は
     *   `ArchTokenStream` が例外にする。
     */
    public static function braceDepthAt(string $source, int $index): int
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);

        Assert::greaterThanEq($index, 0, "走査位置が範囲外である: {$index}");
        Assert::lessThanEq($index, count($tokens), "走査位置が範囲外である: {$index}");

        $depth = 0;
        for ($cursor = 0; $cursor < $index; $cursor++) {
            $token = $tokens[$cursor];

            if ($token['id'] === T_CURLY_OPEN || $token['id'] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }

            if ($token['id'] !== null) {
                continue;
            }

            if ($token['text'] === '{') {
                $depth++;
            } elseif ($token['text'] === '}') {
                $depth--;
            }
        }

        return $depth;
    }
```

### `tests/Architecture/ArchBaselineTest.php` の新設テスト S4-3b

```php
test('S4-3b: 唯一の arch チェーンがファイル最上位の foreach 直下にある', function (): void {
    // チェーンの綴りだけを pin すると `if (false) { … }` へ丸ごと移して
    // 7 本の表明を無効化できる (綴りは 1 文字も変わらない)。囲みの綴りと
    // **波括弧の深さ 0** を併せて固定し、「最上位で全規則 ID をちょうど 1 周する」形だけを許す。
    $host = dirname(__DIR__, 2).'/'.ArchBaseline::CHAIN_HOST_FILE;
    $source = archBaselineReadSource($host);

    $sites = ArchSurfaceScanner::functionNameSites($source, ArchBaseline::SINGLE_FUNCTION_NAMES);
    $calls = array_values(array_filter($sites, static fn (array $site): bool => $site['status'] === 'call'));

    expect($calls)->toHaveCount(1);

    /** @var array{status: 'call', name: string, line: int, index: int} $call */
    $call = $calls[0];
    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);

    expect(ArchSurfaceScanner::tokensBefore($source, $call['index'], $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        ->and(ArchSurfaceScanner::braceDepthAt($source, $call['index'] - $headerLength))->toBe(0)
        ->and(ArchSurfaceScanner::braceDepthAt($source, $call['index']))->toBe(1);
});
```

### `tests/Unit/Architecture/ArchBaselineScannerTest.php` の新設負例 7b / 13b / 13c / 13d

```php
test('7b: 走査器の公開入口はすべてトークン化できない入力を例外にする (fail-closed)', function (): void {
    // ★共通入口 (`ArchTokenStream`) を信頼するだけにせず、**公開境界ごとに**固定する。
    //   将来どれかが共通入口を外しても、正例だけでは気付けないからである。
    $broken = '<?php final class {{{';

    expect(fn (): array => ArchSurfaceScanner::identifierSites($broken, ['preset']))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => ArchSurfaceScanner::functionNameSites($broken, ['arch']))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => ArchSurfaceScanner::dynamicMemberSites($broken))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => ArchSurfaceScanner::statementTokens($broken, 0))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => ArchSurfaceScanner::tokensBefore($broken, 1, 1))
        ->toThrow(RuntimeException::class)
        ->and(fn (): int => ArchSurfaceScanner::braceDepthAt($broken, 0))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => VendorArchPresetReader::forbiddenSymbolsFromSource($broken, 1))
        ->toThrow(RuntimeException::class);
});

test('13b: tokensBefore が直前のトークン列を返し、範囲外は例外にする', function (): void {
    $source = archBaselineExpectedChainSource();
    $index = archBaselineChainIndex($source);
    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);

    expect(ArchSurfaceScanner::tokensBefore($source, $index, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        ->and(fn (): array => ArchSurfaceScanner::tokensBefore($source, $index, $index + 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => ArchSurfaceScanner::tokensBefore($source, 100_000, 1))
        ->toThrow(InvalidArgumentException::class);
});

test('13c: 実行されない位置へチェーンを移すと波括弧の深さが増える', function (): void {
    // ★チェーンの綴りは 1 文字も変えずに 7 本の表明を無効化する形。
    //   綴り列の照合だけでは見抜けないので braceDepthAt が要る。
    $topLevel = archBaselineExpectedChainSource();

    $guarded = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        if (false) {
            foreach (ArchBaseline::ruleIds() as $ruleId) {
                arch(ArchBaseline::descriptionOf($ruleId))
                    ->expect(ArchBaseline::symbolsOf($ruleId))
                    ->not->toBeUsed()
                    ->ignoring(ArchBaseline::exceptionsOf($ruleId));
            }
        }
        PHP;

    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);
    $topLevelHeader = archBaselineChainIndex($topLevel) - $headerLength;
    $guardedHeader = archBaselineChainIndex($guarded) - $headerLength;

    // 囲みの**綴り**は両方とも同じ (だから綴り照合では見抜けない)。
    expect(ArchSurfaceScanner::tokensBefore($guarded, $guardedHeader + $headerLength, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        ->and(ArchSurfaceScanner::braceDepthAt($topLevel, $topLevelHeader))->toBe(0)
        ->and(ArchSurfaceScanner::braceDepthAt($guarded, $guardedHeader))->toBe(1);
});

test('13d: 文字列補間の開き波括弧も深さに数える', function (): void {
    // `{$a}` は T_CURLY_OPEN + `}` の組なので、開き側を数えないと深さが -1 になり、
    // その後ろにある最上位のチェーンが「囲まれている」と誤判定される。
    $source = <<<'PHP'
        <?php

        $a = 'x';
        $label = "値は {$a} です";
        arch($label)->expect(['sha1'])->not->toBeUsed();
        PHP;

    expect(ArchSurfaceScanner::braceDepthAt($source, archBaselineChainIndex($source)))->toBe(0);
});

// ---------------------------------------------------------------------------
```

### `tests/Support/Concurrency/ProcessBarrier.php` のコメント縮小

```php
        // 第一級 callable 構文で Closure 化する (`Closure::fromCallable()` と等価)。
        // ArchBaselineTest の S4 は `fromCallable` **という綴り**を tests/ 全数で 0 件に
        // 固定するので、ここでは使わない。可変 callable 経路そのものは S4 の保証範囲外であり
        // (同 gate の docblock「保証しないもの」5 項)、この書き換えで塞がるものではない。
        $this->reader = $reader === null ? null : $reader(...);
```

### `tests/Architecture/ArchBaselineTest.php` の docblock (PHPStan の節を書き足した)

```
 * ★**PHPStan の走査域外である**。`phpstan.neon` の `paths` は `app / config / database / routes` で
 *   `tests/` を含まない (既存方針。本 gate は変えない)。実装時に新設 3 パスを
 *   `vendor/bin/phpstan analyse --level=10` へ**コマンドライン引数で**渡して確認したところ、
 *   `tests/Support/Architecture/` と本ファイルの自己検査部・走査器の負例は**すべて 0 件**で、
 *   残るのは上の禁止表明 4 行 (`TestCall::expect()` が未定義、以降が mixed) だけである。
 *   これは **Pest arch のチェーンが `Pest\Arch\Autoload` の `Plugin::uses(Architectable::class)`
 *   という実行時 mixin で生えており静的に型が付かない**ためである。
 *   **本アプリのコードを 1 行も含まない次の 2 行で同じ 4 エラーが再現する**:
 *
 *       $call = arch('x');
 *       $call->expect(['sha1'])->not->toBeUsed()->ignoring([]);
 *
 *   すなわち Pest arch を使う限りどう書いても消えない vendor 側の型情報の欠落であり、
 *   本実装の書き方に由来しない。`phpstan.neon` に pest の `extension.neon` を足しても
 *   解消しない (同 neon が登録するのは `HigherOrderTapProxy` / `Expectation` の
 *   universal object crate だけで `TestCall` は対象外)。
 *   `@phpstan-ignore` / baseline / `mixed` への widen は禁止事項であり、
 *   チェーンの形は S4 が `ArchBaseline::EXPECTED_CHAIN_TOKENS` で pin しているので、
 *   型を通すために書き換えることもしない。**走査器と自己検査部は 0 エラーである**。
```
