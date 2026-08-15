<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Security;

/**
 * 組織アクセス失効の結果 (家族ごとの件数)。監査 metadata の材料。
 *
 * **0 件でも記録する**。「失効すべきものが無かった」ことも監査上の事実であり、
 * 記録が無いと「窓口が呼ばれなかったのか / 対象が無かったのか」を区別できない。
 */
final readonly class OrgAccessRevocationResult
{
    public function __construct(
        /** 失効させた oauth_sessions 行数 */
        public int $sessions,
        /** 失効させた access token 行数 */
        public int $accessTokens,
        /** 失効させた refresh token 行数 */
        public int $refreshTokens,
        /** 失効させた未交換の認可コード行数 */
        public int $authCodes,
    ) {}

    public function total(): int
    {
        return $this->sessions + $this->accessTokens + $this->refreshTokens + $this->authCodes;
    }

    /** @return array{sessions: int, access_tokens: int, refresh_tokens: int, auth_codes: int} */
    public function toArray(): array
    {
        return [
            'sessions' => $this->sessions,
            'access_tokens' => $this->accessTokens,
            'refresh_tokens' => $this->refreshTokens,
            'auth_codes' => $this->authCodes,
        ];
    }
}
