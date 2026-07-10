<?php

declare(strict_types=1);

namespace App\Passport\Grants;

use App\Models\OauthSession;
use App\Models\User;
use App\Support\OAuth\CliTokenTtl;
use DateInterval;
use Illuminate\Support\Facades\DB;
use JsonException;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * refresh_token grant 拡張 (MCP OAuth 2.1)。
 *
 * refresh で新 access_token を発行する際、前世代 access_token.organization_id /
 * session_id を継承するために request attribute に set する
 * (書き込みは McpAccessTokenRepository が担う)。
 *
 * CLI session (session_id 継承あり) の場合は、継承前に session の失効チェック +
 * membership 再検証を行い、失効/非メンバーなら invalid_grant で refresh を拒否する。
 * per-client TTL (config('template.cli_oauth.*')) は本呼び出しのスコープに閉じて適用する。
 */
final class McpRefreshTokenGrant extends RefreshTokenGrant
{
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $sessionId = null;
        $params = (array) $request->getParsedBody();
        if (isset($params['refresh_token']) && is_string($params['refresh_token'])) {
            try {
                $decrypted = $this->decrypt($params['refresh_token']);
                /** @var mixed $refreshData */
                $refreshData = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
                if (
                    is_array($refreshData)
                    && isset($refreshData['access_token_id'])
                    && is_scalar($refreshData['access_token_id'])
                ) {
                    $prevAccessTokenId = (string) $refreshData['access_token_id'];
                    /** @var object{organization_id: mixed, session_id: mixed}|null $row */
                    $row = DB::table('oauth_access_tokens')
                        ->where('id', $prevAccessTokenId)
                        ->first(['organization_id', 'session_id']);
                    if ($row !== null) {
                        if (is_int($row->organization_id)) {
                            request()->attributes->set('mcp_inherited_organization_id', $row->organization_id);
                        }
                        if (is_string($row->session_id) && $row->session_id !== '') {
                            $sessionId = $row->session_id;
                        }
                    }
                }
            } catch (OAuthServerException|JsonException) {
                // OAuth decrypt / JSON parse 失敗のみ swallow (invalid_grant は parent が応答)。
                // 他の Throwable (RuntimeException / DB エラー等) は bubble で fail-loudly。
            }
        }

        if ($sessionId === null) {
            return parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);
        }

        $this->assertSessionRefreshable($sessionId);
        request()->attributes->set('oauth_inherited_session_id', $sessionId);

        $accessTokenTTL = CliTokenTtl::accessTokenTtl();

        return $this->withCliRefreshTtl(
            fn (): ResponseTypeInterface => parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL),
        );
    }

    /**
     * CLI session が refresh 可能な状態か検証する (失効/非メンバーなら invalid_grant)。
     *
     * @throws OAuthServerException
     */
    private function assertSessionRefreshable(string $sessionId): void
    {
        $session = OauthSession::query()->find($sessionId);
        if ($session === null || $session->isRevoked()) {
            throw OAuthServerException::invalidGrant('The OAuth session is no longer active.');
        }

        $user = User::query()->find($session->user_id);
        $organization = $session->organization;
        if ($user === null || ! $user->isMemberOf($organization)) {
            throw OAuthServerException::invalidGrant('You are no longer a member of the organization for this session.');
        }
    }

    /**
     * CLI refresh TTL を本呼び出しのスコープに閉じて適用する (保存→一時設定→復元)。
     *
     * @param  callable(): ResponseTypeInterface  $callback
     */
    private function withCliRefreshTtl(callable $callback): ResponseTypeInterface
    {
        $original = $this->refreshTokenTTL;
        $this->refreshTokenTTL = CliTokenTtl::refreshTokenTtl();

        try {
            return $callback();
        } finally {
            $this->refreshTokenTTL = $original;
        }
    }
}
