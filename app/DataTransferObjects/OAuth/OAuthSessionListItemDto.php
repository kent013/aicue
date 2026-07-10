<?php

declare(strict_types=1);

namespace App\DataTransferObjects\OAuth;

/**
 * OAuth セッション一覧 (Web UI) の 1 行。
 *
 * CLI セッション (`oauth_sessions`) と legacy MCP token (`oauth_access_tokens` で
 * session_id NULL) を同一 shape に正規化する。`isLegacy=true` の行は `id` が
 * access token id で、セッション単位の失効経路を持たない (棚卸し表示用)。
 */
final class OAuthSessionListItemDto
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly int $userId,
        public readonly ?string $userName,
        public readonly string $clientKind,
        public readonly array $scopes,
        public readonly ?string $lastUsedAt,
        public readonly ?string $revokedAt,
        public readonly string $createdAt,
        public readonly bool $isLegacy,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     userId: int,
     *     userName: string|null,
     *     clientKind: string,
     *     scopes: list<string>,
     *     lastUsedAt: string|null,
     *     revokedAt: string|null,
     *     createdAt: string,
     *     isLegacy: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'userName' => $this->userName,
            'clientKind' => $this->clientKind,
            'scopes' => $this->scopes,
            'lastUsedAt' => $this->lastUsedAt,
            'revokedAt' => $this->revokedAt,
            'createdAt' => $this->createdAt,
            'isLegacy' => $this->isLegacy,
        ];
    }
}
