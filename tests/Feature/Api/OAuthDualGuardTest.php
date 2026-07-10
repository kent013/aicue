<?php

declare(strict_types=1);

use App\Models\OauthSession;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\OAuthTestHelpers;

/*
 * REST API v1 dual guard (auth:api-key,api-oauth) + resolve.api-actor (WP24)。
 *
 * OAuth user token (cli:use + session 束縛) が REST を叩けること、session 失効 /
 * scope 欠落 / session 不在で 401 になること、DELETE /me/session の冪等失効、
 * GET /me の actor 出し分け、write ability の scope 判定・idempotency を固定する。
 */

beforeEach(function (): void {
    [$this->org, $this->user] = createOrganizationWithOwner('DualGuard組織');

    $this->pkce = OAuthTestHelpers::generatePkcePair();
    $this->client = OAuthTestHelpers::createMcpClient(name: 'Test CLI Client');
    $this->redirectUri = 'https://test.example/callback';
});

/**
 * OAuth flow で token を取得し、CLI セッション行を作って access token に束縛する。
 * (CLI client の consent フローでの session 自動発行は WP25。ここでは actor 解決の
 * 前提条件を DB 直接で満たす)
 *
 * @return array{access_token: string, refresh_token: string, session: OauthSession}
 */
function issueCliSessionTokens(object $test, string $scope = 'cli:use read write session.revoke'): array
{
    $tokens = OAuthTestHelpers::exchangeForTokens($test, scope: $scope);
    expect($tokens['access_token'])->not->toBe('');

    $tokenId = DB::table('oauth_access_tokens')
        ->where('user_id', $test->user->id)
        ->orderByDesc('created_at')
        ->value('id');
    expect($tokenId)->not->toBeNull();

    /** @var OauthSession $session */
    $session = OauthSession::factory()->cli()->create([
        'user_id' => $test->user->id,
        'organization_id' => $test->org->id,
        'client_id' => (string) $test->client->id,
    ]);

    DB::table('oauth_access_tokens')->where('id', $tokenId)->update(['session_id' => $session->id]);

    Auth::forgetGuards();

    return [...$tokens, 'session' => $session];
}

test('OAuth user token は dual guard で GET /api/v1/me を叩ける (session セクション出し分け)', function (): void {
    $issued = issueCliSessionTokens($this);

    $response = $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.organization.id', $this->org->id)
        ->assertJsonPath('data.actor_kind', 'user_token')
        ->assertJsonPath('data.api_key', null)
        ->assertJsonPath('data.session.id', $issued['session']->id);
    expect($response->json('data.session.scopes'))->toContain('read');
});

test('API キーは従来どおり叩けて api_key セクションを出す (actor_kind=api_key)', function (): void {
    [$apiKey, $plain] = issueApiKey($this->org, $this->user, ['read']);

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.actor_kind', 'api_key')
        ->assertJsonPath('data.api_key.id', $apiKey->id)
        ->assertJsonPath('data.api_key.abilities', ['read'])
        ->assertJsonPath('data.session', null);
});

test('cli:use scope の無い OAuth token は actor 解決段で 401', function (): void {
    // mcp:use のみの token (session も束縛しない = 既存 MCP token 相当)
    $tokens = OAuthTestHelpers::exchangeForTokens($this, scope: 'mcp:use');
    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('cli:use はあるが session 未束縛の token は 401', function (): void {
    $tokens = OAuthTestHelpers::exchangeForTokens($this, scope: 'cli:use read');
    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('session 失効後は同じ token が 401 になる (chain 失効)', function (): void {
    $issued = issueCliSessionTokens($this);
    $issued['session']->revoke();
    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('組織から外れた user の token は 401 (membership 毎リクエスト再検証)', function (): void {
    $issued = issueCliSessionTokens($this);
    $this->org->users()->detach($this->user->id);
    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('write scope の無い OAuth token は write endpoint で 403 insufficient_ability', function (): void {
    $issued = issueCliSessionTokens($this, scope: 'cli:use read');
    $project = Project::factory()->forOrganization($this->org)->create();

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'アイテム'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability')
        ->assertJsonPath('error.details.required_ability', 'write');
});

test('write scope 付き OAuth token は write endpoint を叩けて Idempotency-Key も機能する', function (): void {
    $issued = issueCliSessionTokens($this);
    $project = Project::factory()->forOrganization($this->org)->create();

    $first = $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->withHeader('Idempotency-Key', 'oauth-idem-1')
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'OAuth経由アイテム'])
        ->assertCreated();

    // 同一 key + 同一 body の再送は保存済みレスポンスを再生する (副作用は 1 回)
    $replay = $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->withHeader('Idempotency-Key', 'oauth-idem-1')
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'OAuth経由アイテム'])
        ->assertCreated();

    expect($replay->json('data.id'))->toBe($first->json('data.id'));
    expect($project->items()->count())->toBe(1);
});

test('DELETE /api/v1/me/session は自セッションを失効させ、以降の token を 401 にする', function (): void {
    $issued = issueCliSessionTokens($this);

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->deleteJson('/api/v1/me/session')
        ->assertOk()
        ->assertJsonPath('data.session_id', $issued['session']->id)
        ->assertJsonPath('data.revoked', true);

    expect(OauthSession::query()->findOrFail($issued['session']->id)->isRevoked())->toBeTrue();

    // 失効した token は次リクエストで guard 段 401
    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('session.revoke scope の無い token は DELETE /me/session が 403', function (): void {
    $issued = issueCliSessionTokens($this, scope: 'cli:use read');

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->deleteJson('/api/v1/me/session')
        ->assertForbidden();

    expect(OauthSession::query()->findOrFail($issued['session']->id)->isRevoked())->toBeFalse();
});

test('API キー Bearer は DELETE /me/session を叩けない (guard 段 401)', function (): void {
    [, $plain] = issueApiKey($this->org, $this->user);

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->deleteJson('/api/v1/me/session')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('OauthSession::revoke は冪等 (再実行しても初回の失効時刻を保持)', function (): void {
    $issued = issueCliSessionTokens($this);
    $session = $issued['session'];

    $session->revoke();
    $firstRevokedAt = $session->revoked_at;
    expect($firstRevokedAt)->not->toBeNull();

    $this->travel(10)->minutes();
    $session->revoke();

    expect($session->revoked_at?->equalTo($firstRevokedAt))->toBeTrue();
});
