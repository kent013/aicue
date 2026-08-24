<?php

declare(strict_types=1);

use Tests\Support\RawEnv\RawEnvDirectWriteScanner;
use Tests\Support\RawEnv\RawEnvWriteSite;

/*
 * `Tests\Support\RawEnv\RawEnvDirectWriteScanner` の自己検査 (走査器の検出力の裏取り)。
 *
 * ★AGENTS.md「静的検査 (gate) と走査器の共通規約」(c)(e) に従い、**正例と負例の両方向**を固定する。
 *   負例には**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く (`myputenv` / `not_putenv` /
 *   `putenv_safe`)。素の部分文字列一致で書くとこの 3 形まで一緒に消えて検出漏れになる、が
 *   本リポジトリの実測である。
 * ★**負例・正例は fixture ファイルを置かず、ナウドキュメント (`<<<'PHP'`) のソース文字列を
 *   走査器へ直接渡す**。fixture ファイルを置くと `RawEnvDirectWriteGateTest` の母集団に入り、
 *   許可箇所を増やすことになる。ナウドキュメントの本文は `token_get_all()` では 1 トークン
 *   (`T_ENCAPSED_AND_WHITESPACE`) になり中の綴りが見えないため、**この自己検査ファイル自身は
 *   gate に対して違反にならない** (実測で確認済み)。
 * ★**母集団の非空は契約しない**。空入力でも例外にせず 0 件を返す
 *   (非空を要求するのは検出器を**使う側**の gate である)。
 */

/**
 * 走査結果を種別の綴りの列へ落とす (位置ではなく分類の裏取りに使う)。
 *
 * @return list<string>
 */
function rawEnvScannerKinds(string $source): array
{
    return array_map(
        static fn (RawEnvWriteSite $site): string => $site->kind->value,
        RawEnvDirectWriteScanner::scan($source),
    );
}

// ── 正例 ──

test('正例 1: 代入系 14 種はすべて element_assign として検出される', function (string $operator): void {
    $source = "<?php\n\$_SERVER['K'] {$operator} 'v';\n";

    expect(rawEnvScannerKinds($source))->toBe(['element_assign']);
})->with(['=', '.=', '+=', '-=', '*=', '/=', '%=', '**=', '??=', '|=', '&=', '^=', '<<=', '>>=']);

test('正例 2: 前置・後置インクリメントと多段添字も element_assign', function (): void {
    $source = <<<'PHP'
    <?php
    ++$_SERVER['K'];
    $_ENV['K']--;
    $_SERVER['a']['b'] = 'v';
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['element_assign', 'element_assign', 'element_assign']);
});

test('正例 3: unset の引数に並んだ面は 2 件とも element_unset', function (): void {
    $source = <<<'PHP'
    <?php
    unset($_SERVER['K'], $_ENV['K']);
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['element_unset', 'element_unset']);
});

test('正例 4: 面そのものへの代入は whole_assign', function (): void {
    $source = <<<'PHP'
    <?php
    $_SERVER = [];
    $_ENV += [];
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['whole_assign', 'whole_assign']);
});

test('正例 5: 面と面の要素への参照の取得は reference_taken', function (): void {
    $source = <<<'PHP'
    <?php
    $r = &$_SERVER['K'];
    $s = &$_ENV;
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['reference_taken', 'reference_taken']);
});

test('正例 6: 分割代入の左辺に現れる面は destructuring_target', function (): void {
    $source = <<<'PHP'
    <?php
    [$_SERVER['K']] = $v;
    list($_ENV['K']) = $v;
    [[$_ENV['K']]] = $v;
    PHP;

    expect(rawEnvScannerKinds($source))
        ->toBe(['destructuring_target', 'destructuring_target', 'destructuring_target']);
});

test('正例 7: putenv は両形とも検出される', function (): void {
    $source = <<<'PHP'
    <?php
    putenv('K=V');
    putenv('K');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['putenv', 'putenv']);
});

test('正例 8: 完全修飾の呼び出しも検出される', function (): void {
    $source = <<<'PHP'
    <?php
    \putenv('K=V');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['putenv']);
});

test('正例 9: 別名つき取り込みを解いて検出される', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Probe;

    use function putenv as setRawEnv;

    setRawEnv('K=V');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['putenv']);
});

test('正例 10: グローバル名前空間の namespace\\putenv は検出される', function (): void {
    $source = <<<'PHP'
    <?php
    namespace\putenv('K=V');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['putenv']);
});

// ── 負例 (誤検出しない) ──

test('負例 1: 完全修飾名が putenv にならない別名は検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Probe;

    use function Acme\{putenv as p2};

    p2('K=V');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('負例 2: 名前空間の中の namespace\\putenv は検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Probe;

    namespace\putenv('K=V');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('負例 3: 面の読み出しは検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    $a = $_SERVER['K'] ?? null;
    foreach ($_SERVER as $k => $v) {
    }
    f($_SERVER);
    g($_ENV, $_SERVER);
    $b = array_key_exists('K', $_ENV);
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('負例 4: unset の中でも面が書き換え対象の根に無ければ検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    unset($other[$_SERVER['K']]);
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('負例 4b: 分割代入の範囲内でも lvalue の根でなければ検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    [$other[$_SERVER['K']]] = $v;
    list($other[$_SERVER['K']]) = $v;
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('負例 5: 同名のメソッド呼び出しは検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    $x->putenv('K=V');
    X::putenv('K=V');
    $y?->putenv('K=V');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('負例 6: 接頭辞・打ち消し・接尾辞つきの別識別子は検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    myputenv('K=V');
    not_putenv('K=V');
    putenv_safe('K=V');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('負例 7: 文字列リテラルとコメントの中の綴りは検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    $a = 'putenv($_SERVER)';
    // putenv('K=V'); $_SERVER['K'] = 'v';
    /* $_ENV['K'] = 'v'; */
    $b = "putenv";
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('正例 11: 連想の分割代入と参照つきの分割代入も検出される', function (): void {
    $keyed = <<<'PHP'
    <?php
    ['key' => $_SERVER['K']] = $value;
    PHP;

    $byReference = <<<'PHP'
    <?php
    [&$_ENV['K']] = $value;
    PHP;

    expect(rawEnvScannerKinds($keyed))->toBe(['destructuring_target'])
        ->and(rawEnvScannerKinds($byReference))->toBe(['reference_taken']);
});

test('負例 8: 分割代入の鍵の側にある面は読み出しなので検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    [$_SERVER['K'] => $v] = $value;
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('負例 9: 別名を putenv 以外へ向けた取り込みは名前空間をまたいでも誤検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Probe;

    use function Acme\noop as setRawEnv;

    setRawEnv('K=V');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

test('負例 10: putenv を 1 度も指さない別名は、名前空間をまたいでも候補にしない', function (): void {
    // ★`unresolved` が立つファイルで**全別名**を候補にすると、無関係な別名関数の呼び出しまで
    //   違反になる。候補にするのは「どこかの領域で putenv を指した別名」だけである。
    $source = <<<'PHP'
    <?php
    namespace A;

    use function Acme\noop as helper;

    helper();

    namespace B;
    PHP;

    expect(rawEnvScannerKinds($source))->toBe([]);
});

// ── 解決できない形は落とす (fail-closed) ──

test('未解決 1: 自前で putenv を宣言したファイルの非修飾呼び出しは unresolved', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Probe;

    function putenv(string $assignment): bool
    {
        return true;
    }

    putenv('K=V');
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['unresolved']);
});

test('未解決 2: 名前空間宣言が 2 つ / 波括弧つきの名前空間は unresolved', function (): void {
    $twoDeclarations = <<<'PHP'
    <?php
    namespace A;

    putenv('K=V');

    namespace B;

    putenv('K=V');
    PHP;

    $braced = <<<'PHP'
    <?php
    namespace A {
        putenv('K=V');
    }
    PHP;

    // ★ここが load-bearing である。取り込み対応表をファイルに 1 つしか持たないと、
    //   2 つ目の名前空間の `setRawEnv` が 1 つ目を上書きし、1 つ目の呼び出しが
    //   「putenv 相当ではない」と判定されて**黙って見逃される** (fail-open)。
    $shadowedAlias = <<<'PHP'
    <?php
    namespace A;

    use function putenv as setRawEnv;

    setRawEnv('K=V');

    namespace B;

    use function Acme\noop as setRawEnv;

    setRawEnv('K=V');
    PHP;

    expect(rawEnvScannerKinds($twoDeclarations))->toBe(['unresolved', 'unresolved'])
        ->and(rawEnvScannerKinds($braced))->toBe(['unresolved'])
        ->and(rawEnvScannerKinds($shadowedAlias))->toBe(['unresolved', 'unresolved']);
});

test('未解決 3: 読み出しとも書き込みとも決まらない単独の出現は unresolved', function (): void {
    $source = <<<'PHP'
    <?php
    $_SERVER;
    PHP;

    expect(rawEnvScannerKinds($source))->toBe(['unresolved']);
});

// ── 母集団 ──

test('母集団: 空入力でも例外にせず 0 件を返す', function (string $source): void {
    expect(RawEnvDirectWriteScanner::scan($source))->toBe([]);
})->with([
    'empty string' => [''],
    'open tag only' => ["<?php\n"],
]);
