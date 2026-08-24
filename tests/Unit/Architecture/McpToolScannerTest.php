<?php

declare(strict_types=1);

use App\Mcp\Tools\AppMcpTool;
use App\Services\Help\McpToolScanner;
use Tests\Support\Help\HelpTestTree;

/*
 * McpToolScanner (ヘルプの MCP ツール走査器) の自己検査。
 *
 * 走査器・gate の共通規約 (AGENTS.md §静的検査 (gate) と走査器の共通規約) の
 * (b) fail-closed / (c) 負例で裏取り を、合成した一時走査根で両方向に固定する。
 * 実装の docblock が「保証しないもの」の正本である。
 */

afterEach(function (): void {
    HelpTestTree::cleanup();
});

test('正例: 基底を継承した具象クラスをクラス名昇順で列挙する', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-ok');
    HelpTestTree::writeToolFixture($root, 'ScannerFixtureZebraTool', 'Whoami');
    HelpTestTree::writeToolFixture($root, 'ScannerFixtureAlphaTool', 'ListProjects');

    $classes = (new McpToolScanner($root))->concreteToolClasses();

    expect($classes)->toBe([
        'App\Mcp\Tools\ScannerFixtureAlphaTool',
        'App\Mcp\Tools\ScannerFixtureZebraTool',
    ]);

    foreach ($classes as $class) {
        expect(is_subclass_of($class, AppMcpTool::class))->toBeTrue();
    }
});

test('正例: 抽象クラスは母集団から外れるが具象は残る', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-abstract');
    HelpTestTree::writeToolFixture($root, 'ScannerFixtureConcreteTool', 'Whoami');

    $abstract = $root.'/ScannerFixtureAbstractTool.php';
    HelpTestTree::put($abstract, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

abstract class ScannerFixtureAbstractTool extends AppMcpTool {}

PHP);
    require_once $abstract;

    expect((new McpToolScanner($root))->concreteToolClasses())
        ->toBe(['App\Mcp\Tools\ScannerFixtureConcreteTool']);
});

test('負例: 走査根が存在しないと例外で止まる (空を返さない)', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-missing');
    $missing = $root.'/not-there';

    expect(fn (): array => (new McpToolScanner($missing))->concreteToolClasses())
        ->toThrow(RuntimeException::class, '走査根が存在しません');
});

test('負例: 母集団が 0 件なら「違反 0 件」ではなく走査の破損として止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-empty');

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, '1 件も見つかりません');
});

test('負例: クラス名とファイル名が一致しないと例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-unresolved');
    HelpTestTree::put($root.'/ScannerFixtureNoSuchClassTool.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

final class ScannerFixtureDifferentNameTool {}

PHP);

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, 'クラスを解決できません');
});

test('負例: 基底を継承しない具象クラスがあると例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-not-a-tool');
    $path = $root.'/ScannerFixtureHelperClass.php';
    HelpTestTree::put($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

final class ScannerFixtureHelperClass {}

PHP);
    require_once $path;

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, 'を継承していません');
});

test('負例: 実体が symlink だと例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-symlink');
    HelpTestTree::writeToolFixture($root, 'ScannerFixtureLinkTargetTool', 'Whoami');
    symlink($root.'/ScannerFixtureLinkTargetTool.php', $root.'/ScannerFixtureLinkedTool.php');

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, '通常ファイルではありません');
});

test('負例: 同名クラスが別の場所から読み込まれていると例外で止まる (走査が空振りしない)', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-shadow');

    // 実在する `App\Mcp\Tools\WhoamiTool` と同名のファイルを一時根へ置く。
    // class_exists() は composer autoload 経由で **本物** を読むため、
    // Reflection の実体は app/Mcp/Tools/WhoamiTool.php を指し、走査中のファイルと食い違う。
    HelpTestTree::put($root.'/WhoamiTool.php', "<?php\n\ndeclare(strict_types=1);\n\n// 中身は読まれない (autoload が本物を解決する)\n");

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, '実体が走査中のファイルと一致しません');
});

test('負例: 走査根そのものが symlink だと例外で止まる', function (): void {
    $real = HelpTestTree::makeDir('mcp-scanner-real-root');
    HelpTestTree::writeToolFixture($real, 'ScannerFixtureBehindLinkTool', 'Whoami');

    $linkRoot = HelpTestTree::makeDir('mcp-scanner-link-holder').'/tools';
    symlink($real, $linkRoot);

    expect(fn (): array => (new McpToolScanner($linkRoot))->concreteToolClasses())
        ->toThrow(RuntimeException::class, 'canonical path ではありません');
});

test('負例: 走査根の**親要素**が symlink でも例外で止まる (最終要素だけを見ない)', function (): void {
    // outside/tools が実ディレクトリ。holder/mcp -> outside という**親要素**の symlink を張り、
    // holder/mcp/tools を走査根にする。最終要素は通常ディレクトリなので
    // `is_link()` だけでは素通りするが、canonical path 検査は弾く。
    $outside = HelpTestTree::makeDir('mcp-scanner-ancestor-outside');
    $tools = $outside.'/tools';
    mkdir($tools, 0o755);
    HelpTestTree::writeToolFixture($tools, 'ScannerFixtureAncestorTool', 'Whoami');

    $holder = HelpTestTree::makeDir('mcp-scanner-ancestor-holder');
    symlink($outside, $holder.'/mcp');
    $root = $holder.'/mcp/tools';

    expect(is_link($root))->toBeFalse()
        ->and(is_dir($root))->toBeTrue();

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, 'canonical path ではありません');
});

test('走査根が実在し、実装の母集団が非空であること', function (): void {
    $root = base_path('app/Mcp/Tools');

    expect(is_dir($root))->toBeTrue();
    expect((new McpToolScanner($root))->concreteToolClasses())->not->toBeEmpty();
});
