<?php

declare(strict_types=1);

use App\Models\User;

/*
 * 2FA 無効化 (DELETE /user/two-factor-authentication, route two-factor.disable) の
 * recent-auth (step-up) 配線。FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()
 * が booted callback で recent-auth middleware を後付けする。ここではその実効性を HTTP 経由で
 * 検証する。allowlist の付与漏れ検出は RecentAuthRouteTest (Architecture) 側。
 */

test('鮮度なしの DELETE 無効化 (XHR) は 409 recent_auth_required で 2FA を無効化しない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->deleteJson('/user/two-factor-authentication')
        ->assertStatus(409)
        ->assertJson([
            'code' => 'recent_auth_required',
            'redirect' => route('recent-auth.confirm'),
        ]);

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
});

test('鮮度なしの Inertia DELETE 無効化は 409 で 2FA を無効化しない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->delete('/user/two-factor-authentication', [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required');

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
});

test('鮮度なしの通常 (非 XHR/非 Inertia) DELETE 無効化は recent-auth confirm へ 302 する', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->delete('/user/two-factor-authentication')
        ->assertRedirect(route('recent-auth.confirm'));

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
});

test('fresh なら DELETE が 2FA を無効化する', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->deleteJson('/user/two-factor-authentication')
        ->assertOk();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
});
