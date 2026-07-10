<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Api;

/**
 * `DELETE /api/v1/me/session` のレスポンス shape。
 */
final class SessionRevokedDto
{
    public function __construct(
        public readonly string $sessionId,
        public readonly bool $revoked,
    ) {}

    /** @return array{session_id: string, revoked: bool} */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'revoked' => $this->revoked,
        ];
    }
}
