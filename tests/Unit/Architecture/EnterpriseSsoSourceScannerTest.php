<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;

/*
 * 走査器そのものの検出力の裏取り (AGENTS.md 走査器共通規約 (c) = 両方向)。
 *
 * ★見本は `tests/Architecture/fixtures/enterprise-sso/*.php.txt` に置く
 *   (拡張子が `.php` でないので autoload も走査根への混入も起きない)。
 */

/** @return array<string, string> */
function scannerFixture(string $name): array
{
    $path = dirname(__DIR__, 2).'/tests/Architecture/fixtures/enterprise-sso/'.$name.'.php.txt';
    $path = str_replace('/tests/tests/', '/tests/', $path);

    expect(is_file($path))->toBeTrue("見本が存在すること: {$path}");

    return ["fixtures/{$name}.php" => (string) file_get_contents($path)];
}

test('動的な呼び出しの 3 形をすべて検出する (負例)', function (): void {
    $violations = EnterpriseSsoSourceScanner::dynamicCallForms(scannerFixture('DynamicCallSample'));

    expect($violations)->toHaveCount(3);
    expect(implode("\n", $violations))->toContain('動的なメンバー名');
    expect(implode("\n", $violations))->toContain('可変クラス名の生成');
    expect(implode("\n", $violations))->toContain('可変 callable');
});

test('規定どおりの固定の呼び出しを動的とみなさない (正例)', function (): void {
    expect(EnterpriseSsoSourceScanner::dynamicCallForms(scannerFixture('CleanSample')))->toBe([]);
});

test('受け手の型が解決できない保護対象語彙の呼び出しを検出する (負例)', function (): void {
    $violations = EnterpriseSsoSourceScanner::unresolvedProtectedCalls(
        scannerFixture('UnresolvedReceiverSample'),
        ['fetch'],
    );

    // 解決できる 1 件は通し、解決できない 1 件だけを落とす
    expect($violations)->toHaveCount(1);
    expect($violations[0])->toContain('受け手の型が解決できない fetch()');
});

test('構築子の promoted プロパティ経由の呼び出しを誤検出しない (正例)', function (): void {
    expect(EnterpriseSsoSourceScanner::unresolvedProtectedCalls(scannerFixture('CleanSample'), ['fetch']))
        ->toBe([]);
});

test('禁止クラスの参照を完全修飾名で検出する (負例)', function (): void {
    $violations = EnterpriseSsoSourceScanner::forbiddenClassReferences(
        scannerFixture('ForbiddenHttpSample'),
        [Http::class],
    );

    expect($violations)->not->toBe([]);
});

test('禁止クラスを参照しないファイルを誤検出しない (正例)', function (): void {
    expect(EnterpriseSsoSourceScanner::forbiddenClassReferences(scannerFixture('CleanSample'), [Http::class]))
        ->toBe([]);
});

test('語彙一致はトークン完全一致である (接頭辞・打ち消し・接尾辞の 3 形を拾わない)', function (): void {
    $sources = ['sample.php' => <<<'PHP'
        <?php
        final class Sample
        {
            public function run(): void
            {
                $this->preReveal();
                $this->notReveal();
                $this->revealLater();
            }
        }
        PHP];

    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['reveal']))->toBe([]);
});

test('語彙一致は同名のトークンをちょうど拾う (負例)', function (): void {
    $sources = ['sample.php' => <<<'PHP'
        <?php
        final class Sample
        {
            public function run(): void
            {
                $this->reveal();
            }
        }
        PHP];

    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['reveal']))->toHaveCount(1);
});

test('存在しない走査根は fail-fast する (空振りを緑にしない)', function (): void {
    expect(fn () => EnterpriseSsoSourceScanner::sources(['app/NotThere']))
        ->toThrow(RuntimeException::class);
});

test('走査根から 1 件以上のファイルを列挙できる (母集団が空でない)', function (): void {
    expect(EnterpriseSsoSourceScanner::sources(['app/Services/EnterpriseSso']))->not->toBe([]);
});

test('危険な fetch() の 5 形をすべて落とし、リテラルの false だけを通す (負例)', function (): void {
    $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral(
        scannerFixture('RedirectFollowingSample'),
        'fetch',
        'followRedirects',
        'false',
    );

    // ★見本の 7 呼び出しのうち、安全な 2 件 (リテラル false / 内側にも同名があるが外側は false) を通す。
    //   `makeRequest()` は fetch ではないので走査対象そのものに入らない。
    expect($violations)->toHaveCount(5);

    $combined = implode("\n", $violations);
    // 引数が無い形 (引数省略 + **入れ子にしか無い**形の 2 件)
    expect(substr_count($combined, 'followRedirects: が無い'))->toBe(2);
    // 値が false でない形 (明示 true / 動的 / 式)
    expect(substr_count($combined, 'followRedirects: が false でない'))->toBe(3);
});

test('入れ子の呼び出しにある同名の引数を外側のものと取り違えない (深さ判定の負例)', function (): void {
    $sources = ['sample.php' => <<<'PHP'
        <?php
        final class Sample
        {
            public function __construct(private Client $pinned) {}

            public function run(): void
            {
                $this->pinned->fetch($this->build(followRedirects: false), $deadline);
            }
        }
        PHP];

    $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral(
        $sources,
        'fetch',
        'followRedirects',
        'false',
    );

    expect($violations)->toHaveCount(1);
    expect($violations[0])->toContain('followRedirects: が無い');
});

test('外側が false なら、入れ子に同名の引数があっても通す (深さ判定の正例)', function (): void {
    $sources = ['sample.php' => <<<'PHP'
        <?php
        final class Sample
        {
            public function __construct(private Client $pinned) {}

            public function run(): void
            {
                $this->pinned->fetch($this->build(followRedirects: true), $deadline, followRedirects: false);
            }
        }
        PHP];

    expect(EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false'))
        ->toBe([]);
});

test('リテラルの false ちょうどの呼び出しは通す (正例)', function (): void {
    $sources = ['sample.php' => <<<'PHP'
        <?php
        final class Sample
        {
            public function __construct(private Client $pinned) {}

            public function run(): void
            {
                $this->pinned->fetch($request, $deadline, followRedirects: false);
            }
        }
        PHP];

    expect(EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false'))
        ->toBe([]);
});

test('宣言そのものは呼び出しとみなさない (正例)', function (): void {
    $sources = ['sample.php' => <<<'PHP'
        <?php
        final class Sample
        {
            public function fetch(): void {}
        }
        PHP];

    expect(EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false'))
        ->toBe([]);
});
