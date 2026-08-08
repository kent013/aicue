<?php

declare(strict_types=1);

use Tests\Support\PestTestNameScanner;

/*
 * `PestTestNameScanner` の正負両方向を固定する。
 *
 * ★負のコントロール (テスト 2) が本 scanner の存在理由である。委譲先 gate の test 名同定を
 *   単純な `str_contains()` で書くと、test を改名しても旧名がコメントや別のリテラルに残れば
 *   緑になってしまい「その名前の test が実在する」という保証にならない。
 */

test('test 名スキャナ: test() / it() の第 1 引数を抽出する', function (): void {
    $source = <<<'PHP'
    <?php
    test('最初のテスト', function (): void {});
    it('二番目のテスト', function (): void {});
    PHP;

    expect(PestTestNameScanner::names($source))->toBe(['最初のテスト', '二番目のテスト']);
});

test('test 名スキャナ: コメントにだけある名前は抽出しない', function (): void {
    $source = <<<'PHP'
    <?php
    // test('消えた名前', function (): void {});
    /* test('別の消えた名前', function (): void {}); */
    test('生きている名前', function (): void {});
    PHP;

    expect(PestTestNameScanner::names($source))->toBe(['生きている名前']);
});

test('test 名スキャナ: 文字列リテラル中の test( は抽出しない', function (): void {
    $source = <<<'PHP'
    <?php
    $x = "test('偽物')";
    PHP;

    expect(PestTestNameScanner::names($source))->toBe([]);
});

test('test 名スキャナ: メソッド呼び出しの test() は抽出しない', function (): void {
    $source = <<<'PHP'
    <?php
    $object->test('偽物1');
    $object?->test('偽物2');
    SomeClass::test('偽物3');
    PHP;

    expect(PestTestNameScanner::names($source))->toBe([]);
});

test('test 名スキャナ: function test() の宣言は抽出しない', function (): void {
    $source = <<<'PHP'
    <?php
    function test(string $name): void {}
    PHP;

    expect(PestTestNameScanner::names($source))->toBe([]);
});

test('test 名スキャナ: 実ファイル (SocialProviderTrustPolicyTest) から委譲先 test 名を抽出できる', function (): void {
    $source = file_get_contents(base_path('tests/Architecture/SocialProviderTrustPolicyTest.php'));

    expect($source)->toBeString()
        ->and(PestTestNameScanner::names((string) $source))
        ->toContain('全 SSO provider が capability / email_trust を明示宣言している');
});
