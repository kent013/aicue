<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * MCP OAuth 2.1 discovery endpoint の contract test (WP23)。
 * Claude Desktop / Cursor が URL 貼付後に参照する well-known を固定する。
 */

test('/.well-known/oauth-authorization-server が MCP discovery contract を満たす', function (): void {
    $response = $this->getJson('/.well-known/oauth-authorization-server');

    $response->assertOk()
        ->assertJsonStructure([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'registration_endpoint',
            'response_types_supported',
            'code_challenge_methods_supported',
            'scopes_supported',
            'grant_types_supported',
        ])
        // OAuth 2.1 / MCP spec: PKCE S256 必須
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('scopes_supported', ['mcp:use'])
        ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token']);
});

test('/.well-known/oauth-protected-resource が resource discovery を返す', function (): void {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonStructure([
            'resource',
            'authorization_servers',
        ]);
});

test('DCR (/oauth/register) route が存在し throttle:oauth-register が配線されている', function (): void {
    $registerRoute = collect(Route::getRoutes()->getRoutes())
        ->first(fn (Illuminate\Routing\Route $r): bool => in_array('POST', $r->methods(), true) && $r->uri() === 'oauth/register');

    expect($registerRoute)->not->toBeNull();
    // routes/ai.php の fail-fast 配線 (無ければ起動が止まる契約) の回帰検知。
    expect($registerRoute->gatherMiddleware())->toContain('throttle:oauth-register');
});

test('MCP endpoint (/api/v1/mcp) は auth:mcp-oauth で保護されている', function (): void {
    $mcpRoute = collect(Route::getRoutes()->getRoutes())
        ->first(fn (Illuminate\Routing\Route $r): bool => in_array('POST', $r->methods(), true) && $r->uri() === 'api/v1/mcp');

    expect($mcpRoute)->not->toBeNull();
    $middleware = $mcpRoute->gatherMiddleware();
    expect($middleware)->toContain('auth:mcp-oauth');
    expect($middleware)->toContain('mcp.origin');
    expect($middleware)->toContain('mcp.transport');
    expect($middleware)->toContain('throttle:api-mcp');
    // API キー guard は MCP 経路から撤去済み (WP23 で OAuth 2.1 に差替)
    expect($middleware)->not->toContain('auth:api-key');
});
