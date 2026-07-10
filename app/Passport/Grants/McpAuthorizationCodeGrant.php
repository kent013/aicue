<?php

declare(strict_types=1);

namespace App\Passport\Grants;

use App\Support\OAuth\CliTokenTtl;
use DateInterval;
use Illuminate\Support\Facades\DB;
use JsonException;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * authorization_code grant 拡張 (MCP OAuth 2.1)。
 *
 * token 交換 (POST /oauth/token) の先頭で、body から code を decrypt し
 * auth_code.organization_id / session_id を DB から読み出して request attribute に
 * set する。その後 McpAccessTokenRepository が attribute を拾って
 * access_token.organization_id / session_id に書く。
 *
 * repository の getByIdentifier は vendor 内部で bypass される可能性があるため、
 * 必ず通る grant の respondToAccessTokenRequest() に継承処理を載せる。
 *
 * ## PKCE S256 強制
 *
 * MCP client は public client (client_secret を端末に置けないネイティブ/CLI) 扱いで、
 * `/oauth/register` の DCR で誰でも登録できる。league/oauth2-server の
 * `requireCodeChallengeForPublicClients=true` は PKCE の *存在* は強制するが
 * `code_challenge_method=plain` を受理してしまう。plain は code_challenge が平文露出し
 * 認可コード横取りに使われ得る (RFC 7636 §7.2) ため、validateAuthorizationRequest を
 * override して S256 以外を authorize 段階で拒否する。
 */
final class McpAuthorizationCodeGrant extends AuthCodeGrant
{
    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        $params = $request->getQueryParams();
        $challenge = $params['code_challenge'] ?? null;
        $method = $params['code_challenge_method'] ?? null;

        if (! is_string($challenge) || $challenge === '') {
            throw OAuthServerException::invalidRequest(
                'code_challenge',
                'PKCE is required for MCP OAuth (RFC 7636).',
            );
        }
        if ($method !== 'S256') {
            throw OAuthServerException::invalidRequest(
                'code_challenge_method',
                'Only S256 is allowed for MCP OAuth. `plain` is rejected.',
            );
        }

        return parent::validateAuthorizationRequest($request);
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $isCliExchange = false;
        $params = (array) $request->getParsedBody();
        if (isset($params['code']) && is_string($params['code'])) {
            try {
                $decrypted = $this->decrypt($params['code']);
                /** @var mixed $codeData */
                $codeData = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($codeData) && isset($codeData['auth_code_id']) && is_scalar($codeData['auth_code_id'])) {
                    $authCodeId = (string) $codeData['auth_code_id'];
                    /** @var object{organization_id: mixed, session_id: mixed}|null $row */
                    $row = DB::table('oauth_auth_codes')
                        ->where('id', $authCodeId)
                        ->first(['organization_id', 'session_id']);
                    if ($row !== null) {
                        if (is_int($row->organization_id)) {
                            request()->attributes->set('mcp_inherited_organization_id', $row->organization_id);
                        }
                        if (is_string($row->session_id) && $row->session_id !== '') {
                            request()->attributes->set('oauth_inherited_session_id', $row->session_id);
                            $isCliExchange = true;
                        }
                    }
                }
            } catch (OAuthServerException|JsonException) {
                // catch は OAuth decrypt 失敗 / JSON parse 失敗に絞る。
                // これらは invalid_grant として parent が適切に応答する想定の範囲。
                // RuntimeException / DB 接続エラー等は bubble させて fail-loudly。
            }
        }

        // CLI client (session_id 継承あり = McpAuthCodeRepository が session を発行済) は
        // per-client TTL をローカル変数で都度決定する (config('template.cli_oauth.*'))。
        // grant の mutable プロパティには残さない (前リクエストの TTL 残留防止)。
        if ($isCliExchange) {
            $accessTokenTTL = CliTokenTtl::accessTokenTtl();

            return $this->withCliRefreshTtl(
                fn (): ResponseTypeInterface => parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL),
            );
        }

        return parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);
    }

    /**
     * CLI refresh TTL を **本呼び出しのスコープに閉じて** 適用する。
     *
     * league/oauth2-server は `$this->refreshTokenTTL` プロパティを参照して refresh token を
     * 発行するため、per-request 値を渡す API が存在しない。そこで「保存 → 一時設定 → 復元
     * (finally)」で steady-state を呼び出し前と同一に戻し、Octane / 長寿命プロセスで
     * 前リクエストの TTL が残留しないことを保証する (= grant の観測可能な状態は不変)。
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
