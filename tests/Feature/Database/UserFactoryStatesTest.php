<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/*
 * UserFactory の追加 state (WP50): ssoOnly() / withTwoFactor()。
 */

test('ssoOnly は password null + email 認証済みのユーザーを生成する', function (): void {
    $user = User::factory()->ssoOnly()->create();

    expect($user->getAttribute('password'))->toBeNull();
    expect($user->hasPassword())->toBeFalse();
    expect($user->email_verified_at)->not->toBeNull();
});

test('withTwoFactor は本物の TOTP secret + recovery codes を confirmed 状態で設定する', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();

    // Fortify の判定 (secret + confirmed_at) がそのまま通ること
    expect($user->hasEnabledTwoFactorAuthentication())->toBeTrue();
    expect($user->two_factor_confirmed_at)->not->toBeNull();

    // secret は本物の TOTP secret (現在時刻の OTP が検証を通る)
    $google2fa = app(Google2FA::class);
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    expect($google2fa->verifyKey($secret, $google2fa->getCurrentOtp($secret)))->toBeTrue();

    // recovery codes は 8 件
    expect($user->recoveryCodes())->toHaveCount(8);
});
