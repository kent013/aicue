<?php

declare(strict_types=1);

namespace App\Values\Mcp;

use App\Exceptions\Mcp\InvalidParamsException;

/**
 * UUID v4 形式の idempotency_key 値オブジェクト。
 *
 * MCP 書き込み系 tool の `idempotency_key` を生文字列で引き回さず、
 * 生成・検証を 1 箇所に集約する。形式違反は JSON-RPC -32602 (Invalid Params)
 * に対応する `InvalidParamsException` を throw する。
 */
final class IdempotencyKey
{
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function __construct(public readonly string $value)
    {
        if ($value === '' || preg_match(self::UUID_V4_PATTERN, $value) !== 1) {
            throw new InvalidParamsException('idempotency_key must be a UUID v4.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
