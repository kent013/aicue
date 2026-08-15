<?php

declare(strict_types=1);

use Tests\Support\ForbiddenStatement\ForbiddenStatementKind;
use Tests\Support\ForbiddenStatement\ForbiddenStatementScanner;

/*
 * `ForbiddenStatementScanner` の自己検査 (正例 / 負の対照 / 取りこぼし対照)。
 *
 * ★gate 本体 (`tests/Architecture/ForbiddenStatementTokenInvariantTest.php`) は
 *   「素の main では赤にならない」種類のテストである。空振りしていないことは
 *   本ファイルの正例と取りこぼし対照が担保する。
 *
 * ★**検体はすべてナウドキュメント文字列で書く**。ナウドキュメント本文は
 *   `T_START_HEREDOC` / `T_ENCAPSED_AND_WHITESPACE` / `T_END_HEREDOC` になり、
 *   本文中の綴りにトークン種別が割り当てられない (下の N10 が実測として固定する)。
 *   したがって**本ファイル自身は gate の走査対象でありながら違反にならず、
 *   例外へ登録する必要が無い**。逆に検体を「実行される PHP コード」として書くと
 *   自分が違反になる。
 *
 * ★**全検体は構文として成立する PHP である** (実装時に `php -l` で 1 件ずつ確認済み)。
 *   半予約語をメンバ名に使えるのはクラス / 列挙の中だけなので、文脈を切り落とした断片は
 *   実在しない書き方になり「その規則が現実の誤検出を防いでいる」ことの証明にならない。
 *   検体ごとに `php -l` を起動する自動検査は作らない (本題である走査器の検出力と
 *   関係のないコストになるため)。
 */

// ---------------------------------------------------------------------------
// 正例 — 検出できること
// ---------------------------------------------------------------------------

test('4 つの語彙をそれぞれ単独で検出する', function (): void {
    $specimens = [
        ForbiddenStatementKind::EchoStatement->value => <<<'PHP'
        <?php echo "x";
        PHP,
        ForbiddenStatementKind::GotoStatement->value => <<<'PHP'
        <?php goto end; end: $x = 1;
        PHP,
        ForbiddenStatementKind::GlobalStatement->value => <<<'PHP'
        <?php global $x;
        PHP,
        ForbiddenStatementKind::ShortEchoTag->value => <<<'PHP'
        <?= $x ?>
        PHP,
    ];

    foreach ($specimens as $expected => $specimen) {
        $sites = ForbiddenStatementScanner::sites('fixture.php', $specimen);

        expect($sites)->toHaveCount(1, "検体 {$expected} が 1 件で検出されていません");
        expect($sites[0]->kind->value)->toBe($expected);
    }
});

test('1 つの断片に複数あればすべて検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    function f(): void { echo "a"; echo "b"; }
    function g(): void { global $x; }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(3);
});

test('大文字小文字を区別しない', function (): void {
    $specimen = <<<'PHP'
    <?php ECHO "x"; GLOBAL $y;
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(2);
});

test('ファイル先頭の開始タグ付き出力記法を検出する', function (): void {
    $specimen = <<<'PHP'
    <?= $x ?>
    PHP;

    $sites = ForbiddenStatementScanner::sites('fixture.php', $specimen);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->kind)->toBe(ForbiddenStatementKind::ShortEchoTag);
    expect($sites[0]->line)->toBe(1);
});

test('行番号が正しい', function (): void {
    $specimen = <<<'PHP'
    <?php
    $x = 1;
    echo "x";
    PHP;

    $sites = ForbiddenStatementScanner::sites('fixture.php', $specimen);

    expect($sites)->toHaveCount(1);
    expect($sites[0]->line)->toBe(3);
    expect($sites[0]->describe())->toBe('fixture.php:3 → echo 文');
});

test('テンプレート風の断片でも開始タグで開いた区間は検出する', function (): void {
    $specimen = <<<'PHP'
    @section('body')
    <?= $x ?>
    <?php echo "y"; ?>
    @endsection
    PHP;

    $sites = ForbiddenStatementScanner::sites('fixture.blade.php', $specimen);

    expect($sites)->toHaveCount(2);
    expect($sites[0]->kind)->toBe(ForbiddenStatementKind::ShortEchoTag);
    expect($sites[1]->kind)->toBe(ForbiddenStatementKind::EchoStatement);
});

// ---------------------------------------------------------------------------
// 負の対照 — 検出してはいけないこと (誤検出の回避)
// ---------------------------------------------------------------------------

test('静的呼び出し / クラス定数の取得 / 第一級呼び出し可能を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    Foo::goto();
    $c = Foo::echo;
    $f = Foo::echo(...);
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('メソッド宣言を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    class Foo { public function echo(): void {} public function global(): void {} }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('クラス定数の宣言を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    class Foo { const echo = 1; const ECHO = 2; }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('列挙の場合分けを誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    enum E: string { case Echo = 'e'; case Global = 'g'; }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('クラス定数経由の場合分けの値を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    class A { const ECHO = 1; }
    switch ($x) { case A::ECHO: break; }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('属性の名前つき引数を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    #[Attr(echo: 1)]
    class Foo {}
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('名前つき引数を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    function f(int $global, int $goto): void {}
    f(global: 2, goto: 3);
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('オブジェクトのメソッド呼び出しを誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    class A { public function echo(): void {} public function global(): void {} }
    $o = new A();
    $o->echo();
    $o?->global();
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('コメント / DocComment / 文字列リテラルの中の綴りを誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    // echo "x";
    /** goto */
    $s = "echo global goto";
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

/*
 * ★この検体は「本ファイル自身が gate の走査対象でありながら違反にならない」ことの根拠でもある。
 */
test('ナウドキュメントの本文の綴りを誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    $body = <<<'INNER'
    echo "x"; global $y;
    INNER;
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('読点で繋いだ複数のクラス定数の宣言を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    class A { const echo = 1, goto = 2, global = 3; }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('トレイト取り込みの別名指定を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    trait T { public function m(): void {} }
    class A { use T { m as echo; } }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

test('トレイト取り込みの別名指定 (可視性つき) を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    trait T { public function m(): void {} }
    class A { use T { m as protected global; } }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

/*
 * ★定数の初期化式の配列の読点を名前位置扱いしていないこと (R3b が広がりすぎていない証明)。
 */
test('定数の初期化式の配列の読点は名前位置にならない', function (): void {
    $specimen = <<<'PHP'
    <?php
    class A { const X = [1, 2], Y = 3; }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

/*
 * ★型つきクラス定数では綴りの直前が `const` ではなく**型の綴り**になる (実測)。
 *   直前トークンだけで狭めると誤検出になるため、定数宣言の区間で判定している。
 */
test('型つきクラス定数の宣言を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    class A {
        public const string echo = 'x';
        public const ?string goto = null;
        public const A|string global = 'g';
    }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

/*
 * ★参照を返すメソッドの宣言では綴りの直前が `function` ではなく**参照の記号**になる (実測)。
 */
test('参照を返すメソッドの宣言を誤検出しない', function (): void {
    $specimen = <<<'PHP'
    <?php
    class A { public function &echo(): mixed { $x = 1; return $x; } }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toBe([]);
});

// ---------------------------------------------------------------------------
// 取りこぼし対照 — 読み飛ばし規則の近傍でも検出できること
// ---------------------------------------------------------------------------

test('無名関数の中の違反を検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    $fn = function (): void { echo "x"; };
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

test('定数宣言の直後の違反を検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    class Foo { const A = 1; }
    echo "x";
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

test('場合分けの本体の違反を検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    switch ($x) { case 1: echo "x"; }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

test('属性付き宣言の直後の違反を検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    #[Attr]
    class Foo {}
    echo "x";
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

test('属性の名前つき引数の直後の違反を検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    #[Attr(echo: 1)]
    class Foo {}
    global $x;
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

test('静的呼び出しの直後の違反を検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    Foo::bar();
    echo "x";
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

test('名前つき引数の直後の違反を検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    f(a: 1);
    global $x;
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

test('型つきクラス定数の直後の違反を検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    class A { public const string X = 'x'; }
    echo "y";
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

test('参照を返すメソッドの本体の違反を検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    class A { public function &m(): mixed { echo "x"; $x = 1; return $x; } }
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

test('括弧付きの出力する文も検出する', function (): void {
    $specimen = <<<'PHP'
    <?php
    echo("x");
    PHP;

    expect(ForbiddenStatementScanner::sites('fixture.php', $specimen))->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// 写像の網羅
// ---------------------------------------------------------------------------

test('禁止する語彙は 4 件ちょうどである', function (): void {
    // ★増やすときは家系の機能台帳の議題を先に起こすこと (正典が `print` を対象外と定めている)。
    expect(ForbiddenStatementKind::cases())->toHaveCount(4);
});

test('トークン ID の写像が全 case を覆う', function (): void {
    $tokenIds = [
        ForbiddenStatementKind::EchoStatement->value => T_ECHO,
        ForbiddenStatementKind::ShortEchoTag->value => T_OPEN_TAG_WITH_ECHO,
        ForbiddenStatementKind::GotoStatement->value => T_GOTO,
        ForbiddenStatementKind::GlobalStatement->value => T_GLOBAL,
    ];

    expect(array_keys($tokenIds))
        ->toBe(array_map(static fn (ForbiddenStatementKind $kind): string => $kind->value, ForbiddenStatementKind::cases()));

    foreach ($tokenIds as $value => $tokenId) {
        expect(ForbiddenStatementKind::fromTokenId($tokenId)?->value)->toBe($value);
    }

    expect(ForbiddenStatementKind::fromTokenId(T_STRING))->toBeNull();
    expect(ForbiddenStatementKind::fromTokenId(T_PRINT))->toBeNull();
    expect(ForbiddenStatementKind::fromTokenId(null))->toBeNull();
});
