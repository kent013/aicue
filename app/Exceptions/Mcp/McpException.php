<?php

declare(strict_types=1);

namespace App\Exceptions\Mcp;

use Laravel\Mcp\Exceptions\JsonRpcException;

/**
 * MCP 経路の基底例外。
 *
 * laravel/mcp の `JsonRpcException` を継承することで `Server::handle` の
 * try/catch で JSON-RPC error envelope に直接マップされる。これにより
 * `APP_DEBUG=false` (= production) でも意図したメッセージが client に透過する
 * (生 Throwable は Server 側で汎用メッセージに潰される)。
 */
abstract class McpException extends JsonRpcException
{
    public function __construct(string $message)
    {
        parent::__construct($message, $this->jsonRpcCode());
    }

    /** JSON-RPC 2.0 error code */
    abstract public function jsonRpcCode(): int;
}
