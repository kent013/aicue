<?php

declare(strict_types=1);

use App\Enums\Mcp\ToolName;
use App\Mcp\Servers\AppMcpServer;
use App\Mcp\Tools\AppMcpTool;
use Laravel\Mcp\Server\Tool;

/*
 * ToolName enum と AppMcpServer のサーバ登録が 1:1 で対応する不変条件。
 * tool を追加して enum への case 追加 (= 認可/冪等の判断) を忘れる、
 * またはその逆をビルド時に検出する。
 */

/**
 * AppMcpServer に登録された tool class 一覧を reflection で取得する。
 *
 * @return list<class-string<Tool>>
 */
function registeredMcpToolClasses(): array
{
    $reflection = new ReflectionClass(AppMcpServer::class);
    $property = $reflection->getProperty('tools');

    /** @var list<class-string<Tool>> $tools */
    $tools = $property->getValue($reflection->newInstanceWithoutConstructor());

    return $tools;
}

test('ToolName の case とサーバ登録 tool は 1:1 に対応する', function (): void {
    $registeredNames = array_map(
        static fn (string $class): string => app($class)->name(),
        registeredMcpToolClasses(),
    );
    sort($registeredNames);

    $enumNames = array_map(static fn (ToolName $t): string => $t->value, ToolName::cases());
    sort($enumNames);

    expect($registeredNames)->toBe($enumNames);
});

test('登録済み tool はすべて AppMcpTool を継承する (認可/冪等/ログの配線保証)', function (): void {
    foreach (registeredMcpToolClasses() as $class) {
        expect(is_subclass_of($class, AppMcpTool::class))
            ->toBeTrue("{$class} は AppMcpTool を継承すること");
    }
});
