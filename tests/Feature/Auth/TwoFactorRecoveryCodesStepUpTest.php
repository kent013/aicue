<?php

declare(strict_types=1);

use App\Models\User;

/*
 * リカバリコード表示 (GET) / 再生成 (POST) の recent-auth (step-up) 配線。
 *
 * Fortify 登録ルートには FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が
 * booted callback で recent-auth middleware を後付けする。ここではその実効性
 * (stale で遮断 / fresh で通過) を HTTP 経由で検証する。allowlist の付与漏れ検出は
 * RecentAuthRouteTest (Architecture) 側。
 */

test('鮮度なしの GET リカバリコード (XHR) は 409 recent_auth_required でコードを返さない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->getJson('/user/two-factor-recovery-codes')
        ->assertStatus(409)
        ->assertJson([
            'code' => 'recent_auth_required',
            'redirect' => route('recent-auth.confirm'),
        ]);
});

test('鮮度なしの POST 再生成 (XHR) は 409 recent_auth_required で旧コードを失効させない', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();
    $before = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->postJson('/user/two-factor-recovery-codes')
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required');

    $user->refresh();
    expect($user->two_factor_recovery_codes)->toBe($before);
});

test('鮮度なしの通常 POST 再生成は recent-auth confirm へ 302 する', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->post('/user/two-factor-recovery-codes')
        ->assertRedirect(route('recent-auth.confirm'));
});

test('fresh なら GET がコード一覧を返し POST が再生成する', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();
    $before = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->getJson('/user/two-factor-recovery-codes')
        ->assertOk()
        ->assertJsonCount(8);

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->postJson('/user/two-factor-recovery-codes')
        ->assertOk();

    $user->refresh();
    expect($user->two_factor_recovery_codes)->not->toBe($before);
});
