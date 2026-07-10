<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ListItemsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ShowProjectTool;
use App\Mcp\Tools\WhoamiTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * テンプレートの MCP サーバー (read 系 tool 4 種)。
 *
 * 認証は OAuth 2.1 Bearer (auth:mcp-oauth middleware。routes/ai.php 参照)。
 * 認可 (permission runtime 再評価)・冪等性 (write 系)・構造化ログは基底
 * AppMcpTool + ToolName enum が配線する。tool を追加する場合は ToolName に
 * case を足して (write 系は isWriteTool=true) ここに登録する
 * (ToolNameInvariantTest が enum とサーバ登録の 1:1 対応を強制する)。
 */
final class AppMcpServer extends Server
{
    protected string $name = 'Application MCP Server';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server exposes read-only access to the organization bound to the OAuth access token:
        whoami, list-projects, show-project, and list-items.
    MARKDOWN;

    /** @var list<class-string<Tool>> */
    protected array $tools = [
        WhoamiTool::class,
        ListProjectsTool::class,
        ShowProjectTool::class,
        ListItemsTool::class,
    ];
}
