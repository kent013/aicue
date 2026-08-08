<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * `idempotent` middleware を持たないことが正しいと裁定した route の分類語彙。
 *
 * deny-by-default: `api/v1/*` の変更系 route は `idempotent` をちょうど 1 本持つか、
 * 本 enum + 30 文字以上の根拠で `tests/Architecture/IdempotentRouteCoverageTest.php` の
 * 目録へ登録する (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「idempotent を貼るべき route」である。
 */
enum IdempotencyWiringExemption: string
{
    /**
     * 成功すると actor 自身の認証手段が失効する route。
     *
     * 適用条件: 成功後の同一 token での再送が**冪等層より前**の guard 段で 401 になり、
     * 再生応答がクライアントへ返る経路が構造的に存在しない。
     */
    case SelfRevocationUnreachableReplay = 'self_revocation_unreachable_replay';

    /**
     * MCP transport の単一 endpoint。
     *
     * 適用条件: 冪等の単位が transport ではなく tool 呼び出しであり、強制は
     * AppMcpTool の中央分岐 (`ToolName::isWriteTool()` 分岐) が担う。
     */
    case McpTransportPerToolEnforcement = 'mcp_transport_per_tool_enforcement';

    /**
     * vendor が登録する定数 405 (Method Not Allowed) スタブ。
     *
     * 適用条件: ハンドラが即座に固定 Response を返すだけで、本体処理へ到達しない。
     */
    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';
}
