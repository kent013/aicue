<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyMcpOrigin;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\OAuthTestHelpers;
use Tests\TestCase;

/*
 * /api/v1/mcp の Origin allowlist (VerifyMcpOrigin) と transport 契約。
 */

beforeEach(function (): void {
    config()->set('mcp.allowed_origins', ['https://claude.ai']);
    config()->set('mcp.strict_transport', true);
});

/**
 * OAuth full flow (authorize → consent → token) で Passport access token を取得する。
 * WP23 で MCP は auth:mcp-oauth (Passport) 保護になったため、認証済みケースは
 * API キーではなく OAuth token を使う。
 */
function acquireMcpAccessToken(TestCase $test): string
{
    [$test->org, $test->user] = createOrganizationWithOwner();
    $test->pkce = OAuthTestHelpers::generatePkcePair();
    $test->client = OAuthTestHelpers::createMcpClient();
    $test->redirectUri = 'https://test.example/callback';

    $tokens = OAuthTestHelpers::exchangeForTokens($test, state: 'origin-test');

    // session 認証を残さず Bearer guard のみで検証する
    auth()->logout();
    $test->flushSession();

    return $tokens['access_token'];
}

test('allowlist に無い Origin は 403', function (): void {
    $this->withHeaders(['Origin' => 'https://evil.example'])
        ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1])
        ->assertForbidden();
});

test('allowlist に一致する Origin + 有効な OAuth token は 200', function (): void {
    $accessToken = acquireMcpAccessToken($this);

    $this->withHeaders([
        'Origin' => 'https://claude.ai',
        'Authorization' => "Bearer {$accessToken}",
    ])->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1])
        ->assertOk();
});

test('Origin 欠落は strict_transport=true なら 403', function (): void {
    $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1])
        ->assertForbidden();
});

test('Origin 欠落でも strict_transport=false なら通る (非ブラウザクライアント)', function (): void {
    config()->set('mcp.strict_transport', false);
    $accessToken = acquireMcpAccessToken($this);

    $this->withHeaders(['Authorization' => "Bearer {$accessToken}"])
        ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1])
        ->assertOk();
});

test('allowlist が空なら全拒否 (fail-closed)', function (): void {
    config()->set('mcp.allowed_origins', []);
    $middleware = new VerifyMcpOrigin;
    $request = Request::create('/api/v1/mcp', 'POST');
    $request->headers->set('Origin', 'https://claude.ai');

    expect(fn () => $middleware->handle($request, fn () => response('ok')))
        ->toThrow(AccessDeniedHttpException::class);
});

test('production では bare * を拒否する', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('mcp.allowed_origins', ['*']);
    $middleware = new VerifyMcpOrigin;
    $request = Request::create('/api/v1/mcp', 'POST');
    $request->headers->set('Origin', 'https://claude.ai');

    try {
        $middleware->handle($request, fn () => response('ok'));
        $this->fail('AccessDeniedHttpException が投げられるべき');
    } catch (AccessDeniedHttpException $e) {
        expect($e->getMessage())->toContain('Wildcard origin');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

test('非 production では * で全 Origin を許可できる', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('mcp.allowed_origins', ['*']);
    $middleware = new VerifyMcpOrigin;
    $request = Request::create('/api/v1/mcp', 'POST');
    $request->headers->set('Origin', 'https://whatever.example');

    try {
        $response = $middleware->handle($request, fn () => response('ok'));
        expect($response->getContent())->toBe('ok');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

test('MCP は POST + JSON 以外を 400 で拒否する (EnforceMcpTransport)', function (): void {
    $this->withHeaders([
        'Origin' => 'https://claude.ai',
        'Accept' => 'text/html',
    ])->post('/api/v1/mcp', ['body' => 'x'])
        ->assertStatus(400);
});

test('MCP の未認証 (Bearer なし) は 401', function (): void {
    $this->withHeaders(['Origin' => 'https://claude.ai'])
        ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1])
        ->assertUnauthorized();
});
