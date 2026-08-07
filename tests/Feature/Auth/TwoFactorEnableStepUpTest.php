<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;

/*
 * 2FA enrollment 開始 (POST /user/two-factor-authentication) の recent-auth (step-up) 配線 (T124)。
 *
 * ★この施策の中心的な脅威: Fortify の TwoFactorAuthenticationController は
 *   `$request->boolean('force')` を EnableTwoFactorAuthentication へそのまま渡す。
 *   force=true は two_factor_secret と two_factor_recovery_codes を**再生成する一方で
 *   two_factor_confirmed_at を触らない**。つまり奪取セッションから 1 回叩くだけで
 *   「誰も知らない秘密で TOTP を要求し続ける」= 正規ユーザーの永久ロックアウトが成立する。
 *   秘密の**読み出し** (qr-code / secret-key) だけ塞いで**差し替え**を開けたままにしない。
 */

test('鮮度なしの POST enable (XHR) は 409 で two_factor_secret を作らない', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication')
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required');

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
});

test('鮮度なしの POST enable force=true は確立済み seed とリカバリコードを差し替えない (ロックアウト回帰)', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();

    // 前提の明示固定: Factory が confirmed_at を立てることに暗黙依存すると、
    // Factory 変更で「**確立済み** 2FA に対する差し替え」というテストの意味が沈黙して薄れる。
    expect($user->two_factor_confirmed_at)->not->toBeNull();

    $beforeSecret = $user->two_factor_secret;
    $beforeCodes = $user->two_factor_recovery_codes;
    $beforeConfirmedAt = $user->two_factor_confirmed_at;

    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication', ['force' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required');

    $user->refresh();
    expect($user->two_factor_secret)->toBe($beforeSecret);
    expect($user->two_factor_recovery_codes)->toBe($beforeCodes);
    expect($user->two_factor_confirmed_at?->toIso8601String())
        ->toBe($beforeConfirmedAt?->toIso8601String());
});

test('鮮度なしの通常 POST enable は recent-auth confirm へ 302 する', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/user/two-factor-authentication')
        ->assertRedirect(route('recent-auth.confirm'));

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
});

test('fresh なら force=true が seed を実際に差し替え、confirmed_at は触られない (負のコントロール)', function (): void {
    // ★confirmed_at が不変であること自体が「誰も知らない秘密で TOTP を要求し続ける」
    //   ロックアウトが成立する仕組みそのものである。この事実が変わったら設計の前提が
    //   変わるのでテストで固定する。
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();

    $beforeSecret = $user->two_factor_secret;
    $beforeCodes = $user->two_factor_recovery_codes;
    $beforeConfirmedAt = $user->two_factor_confirmed_at;

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->postJson('/user/two-factor-authentication', ['force' => true])
        ->assertSuccessful();

    $user->refresh();
    expect($user->two_factor_secret)->not->toBe($beforeSecret);
    expect($user->two_factor_recovery_codes)->not->toBe($beforeCodes);
    expect($user->two_factor_confirmed_at?->toIso8601String())
        ->toBe($beforeConfirmedAt?->toIso8601String());
});

test('2FA 必須組織の未準拠メンバーでも enable は 2FA ゲートに阻まれない (遮断理由は step-up 側だけ)', function (): void {
    // RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES に two-factor.enable が
    // 元から入っているため、recent-auth 追加後も遮断の理由が step-up 側だけであることを固定する。
    [$organization] = createOrganizationWithOwner();
    /** @var Organization $organization */
    $organization->forceFill(['two_factor_required' => true])->save();

    $member = attachOrganizationMember($organization);

    $this->actingAs($member)
        ->postJson('/user/two-factor-authentication')
        ->assertStatus(409)
        // two_factor_required ではないこと = 2FA ゲートではなく step-up が遮断している
        ->assertJsonPath('code', 'recent_auth_required');
});
