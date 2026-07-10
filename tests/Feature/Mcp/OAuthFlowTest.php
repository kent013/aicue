<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\OAuthTestHelpers;

/*
 * MCP OAuth 2.1 full flow (WP23)。
 * authorize GET → consent POST (組織バインド) → token exchange → refresh を
 * HTTP 境界跨ぎで固定し、grant の org 継承 / PKCE S256 強制 / rotation を回帰検知する。
 */

beforeEach(function (): void {
    [$this->org, $this->user] = createOrganizationWithOwner('OAuth組織');

    $this->pkce = OAuthTestHelpers::generatePkcePair();
    $this->client = OAuthTestHelpers::createMcpClient();
    $this->redirectUri = 'https://test.example/callback';

    config()->set('mcp.allowed_origins', ['https://claude.ai']);
});

test('OAuth happy path: authorize → consent → token → refresh (org を token chain へ継承)', function (): void {
    // ── Step 1: GET /oauth/authorize → consent view (組織選択付き)
    $this->actingAs($this->user);
    $authResponse = $this->get(OAuthTestHelpers::buildAuthorizeUrl(
        clientId: (string) $this->client->id,
        redirectUri: $this->redirectUri,
        codeChallenge: $this->pkce['code_challenge'],
        state: 'flow-state-1',
    ));
    $authResponse->assertOk();
    $authResponse->assertSee('OAuth組織');

    // ── Step 2: POST /oauth/authorize (approve + organization_id)
    $authToken = session('authToken');
    expect($authToken)->toBeString()->not->toBe('');

    $approveResponse = $this->post('/oauth/authorize', [
        'auth_token' => $authToken,
        'organization_id' => $this->org->id,
    ]);
    $approveResponse->assertStatus(302);
    $callback = OAuthTestHelpers::parseCallbackParams($approveResponse);
    expect($callback)->toHaveKey('code');
    expect($callback['state'])->toBe('flow-state-1');

    // ── Step 2.5: auth_code に organization_id がバインドされている
    $authCodeRow = DB::table('oauth_auth_codes')
        ->where('user_id', $this->user->id)
        ->orderByDesc('expires_at')
        ->first();
    expect($authCodeRow)->not->toBeNull();
    expect((int) $authCodeRow->organization_id)->toBe($this->org->id);

    // ── Step 3: POST /oauth/token (grant_type=authorization_code + PKCE)
    $tokenResponse = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'authorization_code',
        'client_id' => (string) $this->client->id,
        'redirect_uri' => $this->redirectUri,
        'code_verifier' => $this->pkce['code_verifier'],
        'code' => $callback['code'],
    ]);
    expect($tokenResponse->getStatusCode())->toBe(200);
    /** @var array<string, mixed> $tokens */
    $tokens = json_decode($tokenResponse->getContent() ?: '{}', true);
    expect($tokens)->toHaveKeys(['access_token', 'refresh_token', 'token_type', 'expires_in']);

    // ── Step 3.5: access_token に organization_id が継承されている
    $accessTokenRow = DB::table('oauth_access_tokens')
        ->where('user_id', $this->user->id)
        ->orderByDesc('created_at')
        ->first();
    expect($accessTokenRow)->not->toBeNull();
    expect((int) $accessTokenRow->organization_id)->toBe($this->org->id);

    // ── Step 4: POST /oauth/token (grant_type=refresh_token) → 新 token も同 org を継承
    /** @var string $refreshToken */
    $refreshToken = $tokens['refresh_token'];
    $refreshResponse = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => (string) $this->client->id,
    ]);
    expect($refreshResponse->getStatusCode())->toBe(200);
    /** @var array<string, mixed> $newTokens */
    $newTokens = json_decode($refreshResponse->getContent() ?: '{}', true);
    expect($newTokens)->toHaveKeys(['access_token', 'refresh_token']);
    expect($newTokens['access_token'])->not->toBe($tokens['access_token']);

    $newAccessTokenRow = DB::table('oauth_access_tokens')
        ->where('user_id', $this->user->id)
        ->orderByDesc('created_at')
        ->first();
    expect((int) $newAccessTokenRow->organization_id)->toBe($this->org->id);
});

test('rotation 済み refresh_token の再使用は invalid_grant (OAuth 2.1)', function (): void {
    $tokens = OAuthTestHelpers::exchangeForTokens($this, state: 'edge-rotate');

    // 1 回目の refresh は成功
    $refreshOk = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => (string) $this->client->id,
    ]);
    expect($refreshOk->getStatusCode())->toBe(200);

    // 2 回目 (旧 refresh を再使用) は invalid_grant
    $refreshStale = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'], // 旧
        'client_id' => (string) $this->client->id,
    ]);
    expect($refreshStale->getStatusCode())->toBe(400);
    /** @var array<string, mixed> $stale */
    $stale = json_decode($refreshStale->getContent() ?: '{}', true);
    expect($stale['error'] ?? null)->toBe('invalid_grant');
});

test('PKCE plain への downgrade は authorize 段で拒否される (S256 only)', function (): void {
    $this->actingAs($this->user);

    $response = $this->get(OAuthTestHelpers::buildAuthorizeUrl(
        clientId: (string) $this->client->id,
        redirectUri: $this->redirectUri,
        codeChallenge: $this->pkce['code_challenge'],
        state: 'pkce-plain-downgrade',
        extra: ['code_challenge_method' => 'plain'],
    ));

    // plain は拒否され、consent 画面 (org 名) は描画されない。
    $response->assertDontSee('OAuth組織');
    expect($response->getStatusCode())->not->toBe(200);
});

test('code_challenge 欠落は authorize 段で拒否される (PKCE 必須)', function (): void {
    $this->actingAs($this->user);

    $url = '/oauth/authorize?'.http_build_query([
        'client_id' => (string) $this->client->id,
        'redirect_uri' => $this->redirectUri,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'pkce-missing',
    ]);
    $response = $this->get($url);

    $response->assertDontSee('OAuth組織');
    expect($response->getStatusCode())->not->toBe(200);
});

test('code_verifier 欠落の token 交換は拒否される (PKCE 強制)', function (): void {
    // authorize + approve で code を取得
    $this->actingAs($this->user);
    $this->get(OAuthTestHelpers::buildAuthorizeUrl(
        clientId: (string) $this->client->id,
        redirectUri: $this->redirectUri,
        codeChallenge: $this->pkce['code_challenge'],
        state: 'pkce-edge',
    ));
    $approve = $this->post('/oauth/authorize', [
        'auth_token' => session('authToken'),
        'organization_id' => $this->org->id,
    ]);
    $callback = OAuthTestHelpers::parseCallbackParams($approve);

    // code_verifier を欠落させて token 交換 → PKCE 強制で reject
    $tokenResponse = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'authorization_code',
        'client_id' => (string) $this->client->id,
        'redirect_uri' => $this->redirectUri,
        // 'code_verifier' => (missing)
        'code' => $callback['code'],
    ]);
    expect($tokenResponse->getStatusCode())->toBe(400);
    /** @var array<string, mixed> $body */
    $body = json_decode($tokenResponse->getContent() ?: '{}', true);
    expect($body['error'] ?? null)->toBeIn(['invalid_grant', 'invalid_request']);
});

test('MCP endpoint は Passport access token を受理し API キーは受理しない', function (): void {
    $tokens = OAuthTestHelpers::exchangeForTokens($this, state: 'guard-check');

    // actingAs の session を解除して Passport Bearer guard のみで認証させる。
    auth()->logout();
    $this->flushSession();

    // ping は tool 基盤に依存しない JSON-RPC method (tool の OAuth 対応は WP25)。
    $this->withHeaders([
        'Origin' => 'https://claude.ai',
        'Authorization' => "Bearer {$tokens['access_token']}",
    ])->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1])
        ->assertOk();

    // API キー (WP22 の api-key guard 形式) は MCP 経路では 401 になる。
    // RequestGuard は 1 リクエスト内で解決済み user をメモ化するため、上の OAuth 成功で
    // 解決された user が同一テストプロセス内の次リクエストへ持ち越されないよう guard を破棄し、
    // 本番の「リクエストごとに新規解決」と同じ独立リクエストを再現する。
    auth()->forgetGuards();
    [, $plainApiKey] = issueApiKey($this->org, $this->user, ['read']);
    $this->withHeaders([
        'Origin' => 'https://claude.ai',
        'Authorization' => "Bearer {$plainApiKey}",
    ])->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 2])
        ->assertUnauthorized();
});
