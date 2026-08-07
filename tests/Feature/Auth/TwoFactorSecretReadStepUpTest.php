<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Fortify\Fortify;

/*
 * 2FA seed を返す GET 2 本 (qr-code / secret-key) の recent-auth (step-up) 配線 (T124)。
 *
 * Fortify 登録ルートには FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が
 * booted callback で recent-auth middleware を後付けする。ここではその実効性
 * (stale で遮断 = 秘密を返さない / fresh で通過 = 秘密を返す) を HTTP 経由で検証する。
 * 付与漏れ検出は RecentAuthRouteTest / TwoFactorStepUpInventoryTest (Architecture) 側。
 *
 * ★`Accept: application/json` を**実ヘッダで**指定する (getJson() ヘルパ任せにしない)。
 *   フロント (Settings/Security.svelte) の素 fetch が同じ条件で 409 契約へ入ることの証拠にする。
 */

test('鮮度なしの GET QR コード (Accept: application/json) は 409 recent_auth_required で svg も url も返さない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get('/user/two-factor-qr-code', ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required')
        ->assertJsonPath('redirect', route('recent-auth.confirm'))
        ->assertJsonMissingPath('svg')
        ->assertJsonMissingPath('url');
});

test('鮮度なしの GET セットアップキー (Accept: application/json) は 409 で secretKey を返さない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required')
        ->assertJsonMissingPath('secretKey');
});

test('鮮度なしの通常 GET (Accept: text/html) は recent-auth confirm へ 302 する', function (string $uri): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get($uri, ['Accept' => 'text/html'])
        ->assertRedirect(route('recent-auth.confirm'));
})->with([
    'qr-code' => ['/user/two-factor-qr-code'],
    'secret-key' => ['/user/two-factor-secret-key'],
]);

test('fresh なら QR とセットアップキーが実際に秘密を返す (負のコントロール)', function (): void {
    // 「常に失敗するから green」という空振りを排除する。遮断に意味があることの証拠。
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();

    $secret = $user->two_factor_secret;
    expect($secret)->toBeString();
    $plainSecret = Fortify::currentEncrypter()->decrypt($secret);

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->get('/user/two-factor-qr-code', ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('svg', fn (mixed $svg): bool => is_string($svg) && $svg !== '');

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('secretKey', $plainSecret);
});

test('409 応答には Cache-Control: no-store が付く (RequireRecentAuth 契約の回帰)', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $response = $this->actingAs($user)
        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
        ->assertStatus(409);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
