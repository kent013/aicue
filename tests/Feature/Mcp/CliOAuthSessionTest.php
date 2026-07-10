<?php

declare(strict_types=1);

use App\Enums\OAuth\OAuthClientKind;
use App\Models\OauthSession;
use App\Support\OAuth\CliTokenTtl;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Tests\Support\OAuthTestHelpers;

/*
 * CLI OAuth (client_kind='cli') の session 発行 + per-client TTL:
 * - auth code 永続化と同一トランザクションで oauth_sessions が 1 行作られ、
 *   token chain (auth code → access token) に session_id が継承される
 * - CLI token TTL は config('template.cli_oauth.*') 駆動 + clamp
 *   (Passport global TTL = MCP 経路の 30/90 日には波及しない)
 * - 失効済み session / 非メンバーの refresh は invalid_grant
 */

beforeEach(function (): void {
    [$this->org, $this->user] = createOrganizationWithOwner('CLI組織');

    $this->pkce = OAuthTestHelpers::generatePkcePair();
    $this->redirectUri = 'https://cli-test.example/callback';

    // first-party CLI client を発行コマンド経由で作成する (client_kind='cli')
    $this->artisan('cli:client', ['--redirect' => [$this->redirectUri]])->assertSuccessful();
    $clientId = DB::table('oauth_clients')
        ->where('client_kind', OAuthClientKind::Cli->value)
        ->value('id');
    $this->client = Client::query()->findOrFail($clientId);
});

test('CLI client の認可で oauth_sessions が発行され token chain に継承される', function (): void {
    ['access_token' => $accessToken] = OAuthTestHelpers::exchangeForTokens(
        $this, scope: 'cli:use read write session.revoke',
    );
    expect($accessToken)->not->toBe('');

    // session は 1 行 (client_kind snapshot / user / org)
    $sessions = OauthSession::query()->get();
    expect($sessions)->toHaveCount(1);
    $session = $sessions->first();
    expect($session->user_id)->toBe($this->user->id);
    expect($session->organization_id)->toBe($this->org->id);
    expect($session->client_kind)->toBe(OAuthClientKind::Cli->value);
    expect($session->isRevoked())->toBeFalse();

    // auth code / access token に session_id が継承されている
    $authCode = DB::table('oauth_auth_codes')->where('user_id', $this->user->id)->first();
    expect($authCode->session_id)->toBe($session->id);

    $tokenRow = DB::table('oauth_access_tokens')->where('user_id', $this->user->id)->first();
    expect($tokenRow->session_id)->toBe($session->id);
    expect((int) $tokenRow->organization_id)->toBe($this->org->id);
});

test('CLI access token は per-client TTL (既定 60 分) で発行される', function (): void {
    OAuthTestHelpers::exchangeForTokens($this, scope: 'cli:use read');

    $tokenRow = DB::table('oauth_access_tokens')->where('user_id', $this->user->id)->first();
    $expiresAt = new DateTimeImmutable((string) $tokenRow->expires_at);
    $minutes = (int) round(($expiresAt->getTimestamp() - time()) / 60);

    // MCP global TTL (30 日) ではなく CLI TTL (60 分) が適用される
    expect($minutes)->toBeGreaterThanOrEqual(59)->toBeLessThanOrEqual(61);
});

test('CLI TTL は config 値を反映しつつ安全域に clamp される', function (): void {
    // 極端な設定値 (100000 分) は上限 1440 分 (24h) に clamp
    config()->set('template.cli_oauth.access_ttl_minutes', 100000);

    OAuthTestHelpers::exchangeForTokens($this, scope: 'cli:use read');

    $tokenRow = DB::table('oauth_access_tokens')->where('user_id', $this->user->id)->first();
    $expiresAt = new DateTimeImmutable((string) $tokenRow->expires_at);
    $minutes = (int) round(($expiresAt->getTimestamp() - time()) / 60);

    expect($minutes)->toBeGreaterThanOrEqual(1439)->toBeLessThanOrEqual(1441);
});

test('MCP client (client_kind なし) の認可では session を発行しない', function (): void {
    $this->client = OAuthTestHelpers::createMcpClient();
    // MCP client の登録 redirect_uri に合わせる (beforeEach の CLI 用と異なるため上書き)。
    $this->redirectUri = 'https://test.example/callback';

    OAuthTestHelpers::exchangeForTokens($this);

    expect(OauthSession::query()->count())->toBe(0);
    $tokenRow = DB::table('oauth_access_tokens')->where('user_id', $this->user->id)->first();
    expect($tokenRow->session_id)->toBeNull();
});

test('refresh は session を継承し、失効済み session では invalid_grant', function (): void {
    ['refresh_token' => $refreshToken] = OAuthTestHelpers::exchangeForTokens(
        $this, scope: 'cli:use read',
    );

    // 正常 refresh: 新 access token も同一 session を継承
    $refreshResponse = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'refresh_token',
        'client_id' => (string) $this->client->id,
        'refresh_token' => $refreshToken,
    ]);
    expect($refreshResponse->getStatusCode())->toBe(200);

    $session = OauthSession::query()->firstOrFail();
    $sessionIds = DB::table('oauth_access_tokens')->pluck('session_id')->unique()->all();
    expect($sessionIds)->toBe([$session->id]);

    // session 失効後の refresh は invalid_grant
    /** @var array<string, mixed> $refreshed */
    $refreshed = json_decode($refreshResponse->getContent() ?: '{}', true);
    $session->revoke();

    $failed = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'refresh_token',
        'client_id' => (string) $this->client->id,
        'refresh_token' => (string) $refreshed['refresh_token'],
    ]);
    expect($failed->getStatusCode())->toBe(400);
    expect($failed->getContent())->toContain('invalid_grant');
});

test('組織から外れた user の refresh は invalid_grant', function (): void {
    ['refresh_token' => $refreshToken] = OAuthTestHelpers::exchangeForTokens(
        $this, scope: 'cli:use read',
    );

    $this->org->users()->detach($this->user);

    $failed = OAuthTestHelpers::exchangeTokenForm($this, [
        'grant_type' => 'refresh_token',
        'client_id' => (string) $this->client->id,
        'refresh_token' => $refreshToken,
    ]);
    expect($failed->getStatusCode())->toBe(400);
    expect($failed->getContent())->toContain('invalid_grant');
});

test('CliTokenTtl は非数値/負値を既定値・下限に clamp する', function (): void {
    // 非数値 → 既定値 (60 分 / 30 日)
    config()->set('template.cli_oauth.access_ttl_minutes', 'abc');
    config()->set('template.cli_oauth.refresh_ttl_days', null);
    expect(CliTokenTtl::accessTokenTtl()->i)->toBe(60);
    expect(CliTokenTtl::refreshTokenTtl()->d)->toBe(30);

    // 負値 → 下限 (access 5 分 / refresh 1 日)
    config()->set('template.cli_oauth.access_ttl_minutes', -10);
    config()->set('template.cli_oauth.refresh_ttl_days', -5);
    expect(CliTokenTtl::accessTokenTtl()->i)->toBe(5);
    expect(CliTokenTtl::refreshTokenTtl()->d)->toBe(1);
});
