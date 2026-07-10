<?php

declare(strict_types=1);

namespace App\Exceptions\Mcp;

/**
 * JSON-RPC 2.0 `-32602 Invalid Params`。
 */
final class InvalidParamsException extends McpException
{
    public function jsonRpcCode(): int
    {
        return -32602;
    }
}
