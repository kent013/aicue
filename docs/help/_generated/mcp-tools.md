<!-- 自動生成: `php artisan help:build` が生成する。手で編集しない。 -->
<!-- 生成器: mcp-tools (App\Services\Help\Generators\McpToolReferenceGenerator) -->

# MCP ツールリファレンス

本アプリが MCP サーバー (`App\Mcp\Servers\AppMcpServer`) 経由で公開しているツールの一覧である。
実装 (`app/Mcp/Tools/`) から自動生成しているので、手書きの説明が実装からずれることはない。

現在のツール数: 4

## `list-items`

List items of a project in the organization bound to the access token.

| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| `project_id` | integer | 必須 | Project ID |
| `page` | integer | 任意 | Page number (1..1000) |
| `per_page` | integer | 任意 | Items per page (1..100) |

## `list-projects`

List projects in the organization bound to the access token.

| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| `page` | integer | 任意 | Page number (1..1000) |
| `per_page` | integer | 任意 | Items per page (1..100) |

## `show-project`

Show a project in the organization bound to the access token.

| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| `project_id` | integer | 必須 | Project ID |

## `whoami`

Return the authenticated user and the organization bound to the access token.

パラメータなし。
