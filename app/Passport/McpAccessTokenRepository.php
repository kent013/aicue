<?php

declare(strict_types=1);

namespace App\Passport;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\AccessTokenRepository as BaseRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

/**
 * access_token 発行時に、grant が set した `mcp_inherited_organization_id` /
 * `oauth_inherited_session_id` request attribute を読み取り、
 * access_token.organization_id / session_id に書き込む。
 *
 * grant (McpAuthorizationCodeGrant / McpRefreshTokenGrant) は
 * respondToAccessTokenRequest() の先頭で、auth_code / 前世代 access_token から
 * organization_id / session_id を読んで attribute に set する。
 * 非 MCP 経路では attribute 未 set なので no-op。
 */
final class McpAccessTokenRepository extends BaseRepository
{
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        parent::persistNewAccessToken($accessTokenEntity);

        $orgIdRaw = request()->attributes->get('mcp_inherited_organization_id');
        $orgId = is_int($orgIdRaw) ? $orgIdRaw : null;

        $sessionIdRaw = request()->attributes->get('oauth_inherited_session_id');
        $sessionId = is_string($sessionIdRaw) && $sessionIdRaw !== '' ? $sessionIdRaw : null;

        $updates = [];
        if ($orgId !== null) {
            $updates['organization_id'] = $orgId;
        }
        if ($sessionId !== null) {
            $updates['session_id'] = $sessionId;
        }

        if ($updates !== []) {
            DB::table('oauth_access_tokens')
                ->where('id', $accessTokenEntity->getIdentifier())
                ->update($updates);
        }
    }
}
