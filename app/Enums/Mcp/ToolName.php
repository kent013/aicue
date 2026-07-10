<?php

declare(strict_types=1);

namespace App\Enums\Mcp;

/**
 * MCP tool の canonical name 一覧。
 *
 * - `requiredPermission()`: 各 tool の Laratrust permission (null = member 基本権限)
 * - `isWriteTool()`: 書き込み系 (idempotency_key required) 判定
 *
 * 認可判定の single source。各 Tool class では override せず本 enum を参照する
 * (AppMcpTool::name() が enum 値を tools/call の lookup 名として返す)。
 * サーバ登録 (AppMcpServer::$tools) との 1:1 対応は ToolNameInvariantTest が強制する。
 */
enum ToolName: string
{
    case Whoami = 'whoami';
    case ListProjects = 'list-projects';
    case ShowProject = 'show-project';
    case ListItems = 'list-items';

    /**
     * tool 名 → 必要 Laratrust permission の対応。
     *
     * <!-- TEMPLATE-MARKER: アプリ固有の permission gate をここに追加する。
     *      例: self::RunEvaluation->value => 'evaluations-run'。permission は
     *      PermissionSeeder に定義し、McpAuthorizationContext::authorizeTool が
     *      laratrust_team_id 明示で評価する。テンプレートの read 系 tool は
     *      member 基本権限のみ (エントリなし = null)。 -->
     *
     * @return array<string, non-empty-string>
     */
    private static function permissionMap(): array
    {
        return [];
    }

    /**
     * tool 実行に必要な Laratrust permission (organization team scope で runtime 再評価)。
     * null = member 基本権限のみで呼べる。
     */
    public function requiredPermission(): ?string
    {
        return self::permissionMap()[$this->value] ?? null;
    }

    /**
     * 書き込み系 tool か (true なら idempotency_key (UUID v4) が必須になり、
     * AppMcpTool が McpIdempotencyService で replay/store を配線する)。
     *
     * match を網羅で書くことで、tool 追加時に write/read の判断を強制する。
     */
    public function isWriteTool(): bool
    {
        return match ($this) {
            self::Whoami,
            self::ListProjects,
            self::ShowProject,
            self::ListItems => false,
        };
    }
}
