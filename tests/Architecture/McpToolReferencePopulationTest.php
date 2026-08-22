<?php

declare(strict_types=1);

use App\Enums\Mcp\ToolName;
use App\Mcp\Servers\AppMcpServer;
use App\Mcp\Tools\AppMcpTool;
use App\Services\Help\McpToolScanner;
use Laravel\Mcp\Server\Tool;

/*
 * ヘルプの MCP ツール一覧が **実装の全数** から生成されていることを固定する。
 *
 * 見るのは 3 集合の完全一致である:
 *   (1) 走査集合   = McpToolScanner が `app/Mcp/Tools/` から拾う具象ツール
 *   (2) 登録集合   = AppMcpServer::$tools
 *   (3) 語彙集合   = ToolName enum の case
 *
 * 既存の `tests/Feature/Mcp/ToolNameInvariantTest.php` は (2) と (3) の辺を見ている。
 * 本テストが足すのは **(1) の辺** — 「ディレクトリに在るのに登録されていない /
 * 登録されているのにディレクトリから拾えない」を検出する。
 *
 * ★基底クラスは `App\Mcp\Tools\AppMcpTool` である (正典 AG-100 が
 *   「移植時に各リポジトリの基底クラスへ差し替える 1 行」と名指しした箇所 = I3)。
 * ★**保証しないもの**: ツールの中身・説明文の質は見ない。走査器自身の限界は
 *   `McpToolScanner` の docblock と `tests/Unit/Architecture/McpToolScannerTest.php` が正本。
 */

/**
 * 3 集合の食い違いを列挙する (判定の実体。負例はこの関数へ合成入力を与えて裏取りする)。
 *
 * @param  list<string>  $scanned
 * @param  list<string>  $registered
 * @param  list<string>  $vocabulary
 * @return list<string>
 */
function helpMcpPopulationProblems(array $scanned, array $registered, array $vocabulary): array
{
    $problems = [];

    if ($scanned === [] || $registered === [] || $vocabulary === []) {
        $problems[] = '母集団が空である (「違反 0 件」ではなく走査・登録の破損として扱う)';
    }

    sort($scanned);
    sort($registered);
    sort($vocabulary);

    if ($scanned !== $registered) {
        $problems[] = '走査集合と登録集合が食い違う: '
            .implode(', ', array_merge(array_diff($scanned, $registered), array_diff($registered, $scanned)));
    }

    if ($registered !== $vocabulary) {
        $problems[] = '登録集合と ToolName の語彙が食い違う: '
            .implode(', ', array_merge(array_diff($registered, $vocabulary), array_diff($vocabulary, $registered)));
    }

    return $problems;
}

/**
 * AppMcpServer に登録された tool class 名一覧。
 *
 * @return list<class-string<Tool>>
 */
function helpMcpRegisteredToolClasses(): array
{
    $reflection = new ReflectionClass(AppMcpServer::class);

    /** @var list<class-string<Tool>> $tools */
    $tools = $reflection->getProperty('tools')->getValue($reflection->newInstanceWithoutConstructor());

    return $tools;
}

test('走査根 app/Mcp/Tools が実在する', function (): void {
    expect(is_dir(base_path('app/Mcp/Tools')))->toBeTrue();
});

test('走査集合・サーバ登録集合・ToolName の語彙が完全一致する', function (): void {
    $scanned = array_map(
        static fn (string $class): string => app($class)->name(),
        (new McpToolScanner(base_path('app/Mcp/Tools')))->concreteToolClasses(),
    );

    $registered = array_map(
        static fn (string $class): string => app($class)->name(),
        helpMcpRegisteredToolClasses(),
    );

    $vocabulary = array_map(static fn (ToolName $t): string => $t->value, ToolName::cases());

    expect($scanned)->not->toBeEmpty();
    expect(helpMcpPopulationProblems($scanned, $registered, $vocabulary))->toBe([]);
});

test('走査で拾ったクラスはすべて基底 AppMcpTool を継承する (基底の差し替えが効いている)', function (): void {
    $classes = (new McpToolScanner(base_path('app/Mcp/Tools')))->concreteToolClasses();

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(is_subclass_of($class, AppMcpTool::class))->toBeTrue("{$class} は AppMcpTool を継承すること");
    }
});

test('負例: 走査集合が登録集合より多いと問題として現れる', function (): void {
    expect(helpMcpPopulationProblems(['a', 'b'], ['a'], ['a']))
        ->toHaveCount(1)
        ->and(helpMcpPopulationProblems(['a', 'b'], ['a'], ['a'])[0])
        ->toContain('走査集合と登録集合が食い違う');
});

test('負例: 登録集合が走査集合より多いと問題として現れる', function (): void {
    expect(helpMcpPopulationProblems(['a'], ['a', 'b'], ['a', 'b']))
        ->toHaveCount(1);
});

test('負例: ToolName の語彙だけがずれても問題として現れる', function (): void {
    expect(helpMcpPopulationProblems(['a'], ['a'], ['a', 'b']))
        ->toHaveCount(1)
        ->and(helpMcpPopulationProblems(['a'], ['a'], ['a', 'b'])[0])
        ->toContain('ToolName の語彙が食い違う');
});

test('負例: 3 集合がすべて空でも「一致」にはならない', function (): void {
    expect(helpMcpPopulationProblems([], [], []))->toHaveCount(1);
});

test('正例: 一致していれば問題は 0 件である (誤検出しない)', function (): void {
    expect(helpMcpPopulationProblems(['b', 'a'], ['a', 'b'], ['a', 'b']))->toBe([]);
});
