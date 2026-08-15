<?php

declare(strict_types=1);

use Tests\Support\StrictTypesDeclarationScanner;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * Architecture invariant: **git 追跡下の PHP ソース全数**が冒頭で
 * `declare(strict_types=1);` を宣言している。
 *
 * なぜ全数か: PHP は既定で "1" と 1 を黙って行き来させる。宣言を欠くファイルが 1 枚あると
 * そこだけ暗黙変換が復活し、取り違えが実行時まで表に出ない。容量予約 (bytes) や
 * チケット枚数のように数値と文字列の取り違えが金額・容量の誤りになる領域を持つため、
 * 「どこか 1 枚だけ緩い」状態を構造的に作らない。
 *
 * **免除の登録簿 (baseline / allow-list) を持たない**。導入時点の未宣言 32 本を同一変更で
 * 是正して 0 件から始めるので、登録簿は 1 件も守らないまま複雑さだけを足すことになる
 * (`QueueDispatchAtomicityInventoryTest` と同じ形 = 免除機構そのものが無い)。
 * **どうしても宣言できないファイルが将来出た場合も、なし崩しに allow-list を足さない。
 * 設計レビュー (app-design) を通してから機構を新設すること。**
 *
 * 走査域 (追跡下 `*.php` − `*.blade.php`) の定義と限界は
 * `Tests\Support\TrackedPhpSourceFiles` の docblock が正本。
 * 判定の正準形と「実効だが受理しない形」は `Tests\Support\StrictTypesDeclarationScanner` が正本。
 *
 * 家系との関係: laravel-claude-template は `StrictTypesBaselineInvariantTest` で
 * **app のみ**を走査し空の baseline を持つ。本 gate は走査域が広く baseline を持たない
 * (`docs/template-divergence.md` D15)。
 */

test('git 追跡下の PHP は全数 declare(strict_types=1) を宣言している', function (): void {
    $targets = TrackedPhpSourceFiles::all(base_path());

    // 空振り防止 1: 走査対象が 0 件なら赤 (走査域が消えても緑にならないようにする)
    expect($targets)->not->toBeEmpty();

    // 空振り防止 2: 母集団の床値 (実測 1543)。走査域が黙って狭まると赤くなる
    expect(count($targets))->toBeGreaterThanOrEqual(1400);

    // 空振り防止 3: 代表ディレクトリが母集団に含まれること
    //   (prefix ごとに個別の失敗メッセージを出す = どの走査域が消えたか分かるようにする)
    $prefixes = ['app/', 'tests/', 'config/', 'database/', 'routes/', 'bootstrap/', 'public/'];
    foreach ($prefixes as $prefix) {
        $found = array_filter($targets, fn (array $target): bool => str_starts_with($target['relative'], $prefix));
        expect($found)->not->toBeEmpty("走査域から {$prefix} が消えています");
    }

    // 空振り防止 4: 判定器が壊れていない (自己検査ファイルを消されても gate 単独で気付く)
    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n"))->toBeFalse();
    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n\ndeclare(strict_types=1);\n"))->toBeTrue();
    expect(StrictTypesDeclarationScanner::declaresStrictTypes(
        "<?php declare(strict_types=1); declare(strict_types=0);\n"
    ))->toBeFalse();

    $undeclared = [];
    foreach ($targets as $target) {
        $source = file_get_contents($target['absolute']);
        if ($source === false) {
            // 無音 skip すると宣言漏れを見逃す (fail-open) ため、読めないファイルは落とす
            throw new RuntimeException("読み取れないファイルがあります: {$target['relative']}");
        }
        if (! StrictTypesDeclarationScanner::declaresStrictTypes($source)) {
            $undeclared[] = $target['relative'];
        }
    }

    expect($undeclared)->toBe([], strictTypesGateFailureMessage($undeclared));
});

/**
 * 失敗メッセージ (直し方まで書く)。
 *
 * @param  list<string>  $undeclared
 */
function strictTypesGateFailureMessage(array $undeclared): string
{
    $list = implode(PHP_EOL, array_map(static fn (string $path): string => "  - {$path}", $undeclared));

    return 'declare(strict_types=1) を欠く PHP ファイルがあります ('.count($undeclared).' 件):'.PHP_EOL
        .$list.PHP_EOL
        .'直し方: 各ファイルの <?php の直後に次の 1 行を置く (前に他の文・出力を置かない):'.PHP_EOL
        .'  '.StrictTypesDeclarationScanner::CANONICAL_DECLARATION.PHP_EOL
        .'補足 1: 01 / 0x1 / declare(ticks=1, strict_types=1) / 冒頭より後ろでの strict_types の'.PHP_EOL
        .'        再宣言などは PHP としては有効だが、本リポジトリは表記を上の正準形 1 つに揃えるため'.PHP_EOL
        .'        受理しない。'.PHP_EOL
        .'補足 2: `php artisan vendor:publish` の直後は骨組み由来ファイルが宣言を失う。'.PHP_EOL
        .'        publish した内容を確認したうえで宣言を足してから commit すること。'.PHP_EOL
        .'補足 3: 免除の登録簿は意図的に持たない。宣言できない事情ができたときは'.PHP_EOL
        .'        allow-list を足す前に設計レビューを通すこと。';
}
