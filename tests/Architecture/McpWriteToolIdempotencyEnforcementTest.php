<?php

declare(strict_types=1);

use App\Enums\Mcp\ToolName;
use App\Mcp\Servers\AppMcpServer;
use App\Mcp\Tools\AppMcpTool;
use Laravel\Mcp\Server\Tool;

/*
 * MCP 書き込み tool の冪等キー必須化を**中央 1 箇所でしか判断させない** invariant。
 *
 * ★本 gate は「据え置きの機械化」でもある。aicue の write tool は現在 0 本であり
 *   (ToolName の 4 case はすべて read)、MCP 側の状態機械 (reserve/complete) と
 *   T109 (replay 判定がリソース解決より前) は**意図的に据え置いている**。
 *   最初の write tool が追加された瞬間に trip-wire が赤くなり、同時にやるべき作業が
 *   失敗メッセージとして提示される。
 *
 * ★**保証しないこと**: handle() 内の中央分岐の実在確認は**字句検査**である。
 *   分岐の意味 (実際に replay/store が呼ばれるか) までは静的に見ていない。
 *   write tool が生えた時点で behavioral テストを足すこと (trip-wire がそれを強制する)。
 *
 * ★ToolNameInvariantTest とは役割を分ける: 既存は「enum ⇔ サーバ登録の 1:1」と
 *   「全 tool が AppMcpTool を継承」。本 gate は「中央強制を迂回できないこと」と
 *   「据え置きの trip-wire」。重複させない。
 */

/**
 * AppMcpServer に登録された tool class 一覧を reflection で取得する。
 * (Pest のグローバル関数はファイル間で共有されないため本ファイルにも置く。
 *  名前は ToolNameInvariantTest の registeredMcpToolClasses() と衝突しないようにする)
 *
 * @return list<class-string<Tool>>
 */
function mcpEnforcementRegisteredToolClasses(): array
{
    $reflection = new ReflectionClass(AppMcpServer::class);
    $property = $reflection->getProperty('tools');

    /** @var list<class-string<Tool>> $tools */
    $tools = $property->getValue($reflection->newInstanceWithoutConstructor());

    return $tools;
}

/** 対象クラスのソース全文 */
function mcpEnforcementSourceOf(string $class): string
{
    $file = (new ReflectionClass($class))->getFileName();
    expect($file)->toBeString();

    $source = file_get_contents((string) $file);
    expect($source)->toBeString();

    return (string) $source;
}

test('登録 tool の母集団が下限を下回らない (空振り防止)', function (): void {
    expect(count(mcpEnforcementRegisteredToolClasses()))->toBeGreaterThanOrEqual(4);
    expect(count(ToolName::cases()))->toBeGreaterThanOrEqual(4);
});

test('全 tool の handle() は AppMcpTool が宣言したものである (override による迂回の禁止)', function (): void {
    $violations = [];

    foreach (mcpEnforcementRegisteredToolClasses() as $class) {
        $declaring = (new ReflectionMethod($class, 'handle'))->getDeclaringClass()->getName();
        if ($declaring !== AppMcpTool::class) {
            $violations[] = "{$class}: handle() が {$declaring} で宣言されています";
        }
    }

    expect($violations)->toBe([],
        'handle() を override すると認可・冪等・ログの中央強制を迂回できます。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('AppMcpTool::handle() は final である', function (): void {
    expect((new ReflectionMethod(AppMcpTool::class, 'handle'))->isFinal())->toBeTrue();
});

test('ToolName::isWriteTool() は網羅 match で書かれている (default を持たない)', function (): void {
    // default => があると case 追加時に write/read の判断が強制されなくなる
    expect(preg_match('/\bdefault\s*=>/', mcpEnforcementSourceOf(ToolName::class)))->toBe(0,
        'ToolName に default => が現れました。isWriteTool() の match は網羅で書き、'
        .'tool 追加時に write/read の判断を強制してください。');
});

test('AppMcpTool::handle() は isWriteTool() による中央分岐を持つ', function (): void {
    // ★字句検査である (分岐の意味までは見ていない)。限界は本ファイル冒頭に明記。
    expect(preg_match('/->isWriteTool\(\s*\)/', mcpEnforcementSourceOf(AppMcpTool::class)))->toBe(1);
});

test('MCP write tool は 0 本である (据え置きの明示的な pin)', function (): void {
    $writeTools = array_values(array_filter(
        ToolName::cases(),
        static fn (ToolName $t): bool => $t->isWriteTool(),
    ));

    expect($writeTools)->toBe([],
        '初めての MCP write tool を追加しました。次を**同じ PR で**行ってください:'
        .PHP_EOL.'1. McpIdempotencyService を reserve/complete/indeterminate へ再構成する'
        .'(現在の store() は unique 違反を握り潰しており、並行呼び出しで二重実行が起きる)'
        .PHP_EOL.'2. T109 を解消する (AppMcpTool::handle() の冪等判定を runTool() の'
        .'リソース解決より後へ。REST 側の api.project-in-org < idempotent と同型のハザード)'
        .PHP_EOL.'3. write tool の idempotency_key 必須化・replay・conflict の behavioral テストを追加する'
        .PHP_EOL.'4. 書き込みの範囲の再評価 (ToolName::requiredPermission() の割り当て) も同時に決める'
        .'(認可の関門そのものは McpAuthorizationChokePointTest が固定しているが、'
        .'新しい write tool にどの権限を要求するかは人が決めるほかない)'
        .PHP_EOL.'5. 本 pin をその時点の write tool 一覧へ更新する'
        .PHP_EOL.'設計の根拠: devnotes/20260809-0027-idempotency-concurrent-claim/');
});
