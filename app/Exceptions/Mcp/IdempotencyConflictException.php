<?php

declare(strict_types=1);

namespace App\Exceptions\Mcp;

/**
 * 同じ idempotency_key で違う payload が送られた時の conflict。
 * JSON-RPC 2.0 application error 領域の `-32002` にマップする。
 */
final class IdempotencyConflictException extends McpException
{
    public function jsonRpcCode(): int
    {
        return -32002;
    }
}
