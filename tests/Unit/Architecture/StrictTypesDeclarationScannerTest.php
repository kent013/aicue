<?php

declare(strict_types=1);

use Tests\Support\StrictTypesDeclarationScanner;
use Tests\Support\StrictTypesRuntimeProbe;

/*
 * `StrictTypesDeclarationScanner` の正負両方向と、実測器との乖離の向きを固定する。
 *
 * ★負のコントロール (コメント内・文字列リテラル内の記述) が本 scanner の存在理由である。
 *   部分文字列判定で書くと、宣言を消してコメントに残しただけのファイルが緑になり
 *   「そのファイルで厳密化が効いている」という保証にならない。
 *
 * ★**乖離は片方向しか許さない**。scanner が true と言ったら実測も必ず true でなければ
 *   ならない (= scanner は実効性の下界)。逆向き (scanner true / 実測 false) は
 *   gate が嘘をつくことになるので 1 件も許さない。安全側の乖離
 *   (実効だが scanner false) は下の検体表に明示して固定する。
 *
 * ★検体は**自分では何も出力せず** `exit` / `?>` / `__halt_compiler()` を持たない
 *   完全な PHP ソースとして書く。この制約が破れると実測器が例外を投げるので、
 *   破れたまま緑になることはない。
 */

/**
 * 検体表。
 *
 * @return list<array{0: string, 1: string, 2: bool, 3: bool}>
 *                                                             [ラベル, 完全な PHP ソース, scanner の期待値, 実測の期待値]
 */
function strictTypesScannerSpecimens(): array
{
    return [
        // --- 正の対照 (scanner true / 実測 true) ---
        ['正準形', "<?php\n\ndeclare(strict_types=1);\n\n\$x = 1;\n", true, true],
        ['正準形 + 先頭コメント', "<?php\n\n/* まえおき */\ndeclare(strict_types=1);\n", true, true],
        ['大文字', "<?php\n\nDECLARE(STRICT_TYPES=1);\n", true, true],
        ['空白揺れ', "<?php\n\ndeclare ( strict_types = 1 ) ;\n", true, true],
        // 後続の declare でも strict_types を含まないものは拒否しない (厳密化に関係しないため)
        ['後続 declare(ticks=1)', "<?php\n\ndeclare(strict_types=1);\ndeclare(ticks=1);\n", true, true],

        // --- 負の対照 (scanner false / 実測 false) ---
        ['コメント内の記述のみ', "<?php\n\n// declare(strict_types=1);\n\$x = 1;\n", false, false],
        ['文字列リテラル内の記述のみ', "<?php\n\n\$x = 'declare(strict_types=1);';\n", false, false],
        ['値 0', "<?php\n\ndeclare(strict_types=0);\n", false, false],
        ['ブロック形', "<?php\n\ndeclare(strict_types=1) { }\n", false, false],
        ['後置 (namespace の後)', "<?php\n\nnamespace A;\n\ndeclare(strict_types=1);\n", false, false],
        ['別指令のみ', "<?php\n\ndeclare(ticks=1);\n", false, false],
        ['宣言なし', "<?php\n\n\$x = 1;\n", false, false],
        ['<?php の前に文字', "X<?php\n\ndeclare(strict_types=1);\n", false, false],
        ['配列リテラルのキー', "<?php\n\n\$x = ['strict_types' => 1];\n", false, false],

        // --- 安全側の乖離 (実効だが scanner は未宣言に倒す) ---
        // 冒頭より後ろの strict_types 再宣言。現行 PHP の実効は strict のままだが、
        // 表記を 1 つに揃える規約として拒否し、「後に書いた方が勝つ」へ仕様が
        // 変わったときの fail-open も同時に塞ぐ。
        ['後続の再宣言 (値 0)', "<?php\n\ndeclare(strict_types=1);\ndeclare(strict_types=0);\n", false, true],
        ['後続の再宣言 (値 1)', "<?php\n\ndeclare(strict_types=1);\ndeclare(strict_types=1);\n", false, true],
        ['値 01', "<?php\n\ndeclare(strict_types=01);\n", false, true],
        ['値 0x1', "<?php\n\ndeclare(strict_types=0x1);\n", false, true],
        ['値 0b1', "<?php\n\ndeclare(strict_types=0b1);\n", false, true],
        ['複合指令', "<?php\n\ndeclare(ticks=1, strict_types=1);\n", false, true],
        ['冗長な括弧', "<?php\n\ndeclare(strict_types=(1));\n", false, true],
        ['2 文目の declare', "<?php\n\ndeclare(ticks=1);\ndeclare(strict_types=1);\n", false, true],
        // 引数部を括弧の深さで追わないと、後続の strict_types を取りこぼす形
        ['入れ子括弧つき複合指令', "<?php\n\ndeclare(ticks=(1), strict_types=1);\n", false, true],
    ];
}

test('strict_types 判定器: 検体表どおりに判定する', function (): void {
    foreach (strictTypesScannerSpecimens() as [$label, $source, $expected]) {
        expect(StrictTypesDeclarationScanner::declaresStrictTypes($source))
            ->toBe($expected, "検体『{$label}』の判定が期待と違います");
    }
});

test('strict_types 判定器: 実効性の下界である (逆向きの乖離が 0 件)', function (): void {
    foreach (strictTypesScannerSpecimens() as [$label, $source, $expectedDeclared, $expectedInEffect]) {
        $inEffect = StrictTypesRuntimeProbe::strictTypesInEffect($source);

        expect($inEffect)->toBe($expectedInEffect, "検体『{$label}』の実測が期待と違います");

        if ($expectedDeclared) {
            expect($inEffect)->toBeTrue(
                "検体『{$label}』は判定器が宣言済みと言うのに厳密化が効いていません (gate が嘘をつく向きの乖離)"
            );
        }
    }
});

test('strict_types 実測器: 読み込めないソースは false を返す', function (): void {
    // declare(strict_types=true); は Fatal error になり読み込めない = 厳密化が成立しない
    expect(StrictTypesRuntimeProbe::strictTypesInEffect("<?php\n\ndeclare(strict_types=true);\n"))
        ->toBeFalse();
});

test('strict_types 実測器: 検体自身の出力を判定材料にしない', function (): void {
    // 検体が 'STRICT' と出力して exit しても真にならない (プローブへ到達していない)
    expect(fn () => StrictTypesRuntimeProbe::strictTypesInEffect(
        "<?php\n\ndeclare(strict_types=1);\necho 'STRICT';\nexit;\n"
    ))->toThrow(RuntimeException::class);

    /*
     * PHP の閉じタグで閉じた検体は、追記したプローブが PHP コードとして解釈されず
     * そのまま出力される = 実測不能として例外にする。
     * (このコメントを 1 行コメントで書かないこと。閉じタグは 1 行コメントを終端する。)
     */
    expect(fn () => StrictTypesRuntimeProbe::strictTypesInEffect(
        "<?php\n\ndeclare(strict_types=1);\n?>\n"
    ))->toThrow(RuntimeException::class);
});

test('strict_types 判定器: 実ファイルに対して疎通する', function (): void {
    $declared = file_get_contents(base_path('tests/Support/PhpTokenScan.php'));
    expect($declared)->not->toBeFalse();
    expect(StrictTypesDeclarationScanner::declaresStrictTypes((string) $declared))->toBeTrue();

    // blade は PHP ソースファイルではない (gate の母集団にも入らない)
    $blade = file_get_contents(base_path('resources/views/app.blade.php'));
    expect($blade)->not->toBeFalse();
    expect(StrictTypesDeclarationScanner::declaresStrictTypes((string) $blade))->toBeFalse();
});
