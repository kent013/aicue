<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * MCP OAuth 2.1 flow テストの共有 helper。
 * PKCE pair 生成、MCP 向け Passport public client factory、redirect param 抽出、
 * form-urlencoded での /oauth/token 呼び出しを集約する。
 */
final class OAuthTestHelpers
{
    /**
     * PKCE code_verifier / code_challenge (S256) ペアを生成する。
     *
     * @return array{code_verifier: string, code_challenge: string}
     */
    public static function generatePkcePair(): array
    {
        // RFC 7636: verifier は 43-128 chars の [A-Za-z0-9\-._~]。
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(
            strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'),
            '=',
        );

        return [
            'code_verifier' => $codeVerifier,
            'code_challenge' => $codeChallenge,
        ];
    }

    /**
     * MCP 接続向けの public Authorization Code Grant client を作成する。
     * public client (confidential=false) にすることで PKCE が強制される。
     *
     * @param  list<string>  $redirectUris
     */
    public static function createMcpClient(
        string $name = 'Test MCP Client',
        array $redirectUris = ['https://test.example/callback'],
    ): Client {
        /** @var ClientRepository $repo */
        $repo = app(ClientRepository::class);

        return $repo->createAuthorizationCodeGrantClient(
            name: $name,
            redirectUris: $redirectUris,
            confidential: false,
        );
    }

    /**
     * `302` redirect の Location header から OAuth `code` / `state` / `error` を抽出。
     *
     * @return array<string, string>
     */
    public static function parseCallbackParams(TestResponse $response): array
    {
        $location = $response->headers->get('Location');
        if (! is_string($location)) {
            return [];
        }
        $query = parse_url($location, PHP_URL_QUERY);
        if (! is_string($query)) {
            return [];
        }
        parse_str($query, $params);

        /** @var array<string, string> $result */
        $result = [];
        foreach ($params as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * Authorize endpoint の URL を build する。
     *
     * @param  array<string, string>  $extra
     */
    public static function buildAuthorizeUrl(
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        string $state = 'test-state',
        string $scope = 'mcp:use',
        array $extra = [],
    ): string {
        $params = array_merge([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], $extra);

        return '/oauth/authorize?'.http_build_query($params);
    }

    /**
     * `/oauth/token` に form-urlencoded で POST する。
     *
     * `$this->post()` は test http client が body を JSON 化することがあり
     * league/oauth2-server の body parser と噛み合わずに `unsupported_grant_type`
     * になる場合がある。`$this->call('POST', ...)` + 明示的な `CONTENT_TYPE` で
     * form-urlencoded を強制する知見をここに一本化し、個別テストには分散させない。
     *
     * @param  array<string, string|int>  $params
     */
    public static function exchangeTokenForm(TestCase $test, array $params): TestResponse
    {
        return $test->call(
            method: 'POST',
            uri: '/oauth/token',
            parameters: $params,
            cookies: [],
            files: [],
            server: [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_ACCEPT' => 'application/json',
            ],
        );
    }

    /**
     * authorize → consent → token exchange を 1 helper にまとめる。
     *
     * HTTP boundary を超えて access_token / refresh_token を取得する。
     * caller 側 TestCase の `user` / `org` / `client` / `pkce` / `redirectUri`
     * プロパティを参照する (Pest beforeEach パターン)。
     *
     * `$scope` で要求 scope を差し替えられる (既定は MCP の 'mcp:use'。
     * CLI dual guard テストは 'cli:use read ...' を渡す)。
     *
     * @return array{access_token: string, refresh_token: string}
     */
    public static function exchangeForTokens(
        TestCase $test,
        string $state = 'helper-state',
        string $scope = 'mcp:use',
    ): array {
        /** @var User $user */
        $user = $test->user;
        /** @var Client $client */
        $client = $test->client;
        /** @var array{code_verifier: string, code_challenge: string} $pkce */
        $pkce = $test->pkce;
        /** @var Organization $org */
        $org = $test->org;
        /** @var string $redirectUri */
        $redirectUri = $test->redirectUri;

        $test->actingAs($user);

        $test->get(self::buildAuthorizeUrl(
            clientId: (string) $client->id,
            redirectUri: $redirectUri,
            codeChallenge: $pkce['code_challenge'],
            state: $state,
            scope: $scope,
        ));

        $approve = $test->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
            'organization_id' => $org->id,
        ]);
        $callback = self::parseCallbackParams($approve);
        $code = $callback['code'] ?? '';

        $tokenResponse = self::exchangeTokenForm($test, [
            'grant_type' => 'authorization_code',
            'client_id' => (string) $client->id,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $pkce['code_verifier'],
            'code' => $code,
        ]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($tokenResponse->getContent() ?: '{}', true);

        return [
            'access_token' => (string) ($decoded['access_token'] ?? ''),
            'refresh_token' => (string) ($decoded['refresh_token'] ?? ''),
        ];
    }
}
