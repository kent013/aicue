<?php

declare(strict_types=1);

use App\DataTransferObjects\Auth\LoginMethodRemoval;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\LoginMethodInventory;
use App\Services\Auth\PasskeyLoginPolicy;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkeys;

/*
 * passkey と TOTP (2FA) の関係 — **c2c 未裁定の論点に対する fail-closed 既定**。
 *
 * vendor の PasskeyLoginController::store() は $guard->login() を直接呼び、Fortify の
 * two-factor challenge を通らない。したがって passkey login を許すと TOTP を迂回できる。
 * PasskeyLoginPolicy が「TOTP confirmed なら passkey login を拒否」で fail-closed に倒す。
 *
 * 裁定が出たら PasskeyLoginPolicy 1 箇所を書き換えれば、login 認可 / inventory /
 * UI prop の 3 経路が同時に反転する。本テストはその**現行既定**を固定する。
 */

function allowsPasskeyLoginFor(User $user): bool
{
    $passkey = Passkey::factory()->for($user)->create();

    return Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey);
}

test('TOTP confirmed ユーザーは passkey login を拒否される', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    expect(allowsPasskeyLoginFor($user))->toBeFalse();
});

test('TOTP 無効ユーザーは passkey login を許可される', function (): void {
    $user = User::factory()->create();

    expect(allowsPasskeyLoginFor($user))->toBeTrue();
});

test('TOTP pending (未 confirm) ユーザーは passkey login を許可される', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => encrypt('pending-secret')])->save();

    expect(allowsPasskeyLoginFor($user->fresh() ?? $user))->toBeTrue();
});

/*
 * TOTP confirmed ユーザーにとって passkey は **初めからログイン手段に数えられていない**。
 * したがって全 passkey を消しても残存手段の集合は変わらない
 * (= passkey しか無い TOTP ユーザーの手段はもともと空)。
 */
test('TOTP confirmed ユーザーの手段集合は passkey の増減に影響されない', function (): void {
    $user = User::factory()->withTwoFactor()->create();   // password あり
    Passkey::factory()->count(2)->for($user)->create();

    $inventory = app(LoginMethodInventory::class);

    expect($inventory->remainingAfter($user, LoginMethodRemoval::none())->methods)
        ->toBe($inventory->remainingAfter($user, LoginMethodRemoval::allPasskeys())->methods);
});

/*
 * passkey は **2FA 準拠判定に算入しない**。2FA 必須組織に属する TOTP 未設定ユーザーは、
 * passkey を持っていても RequireTwoFactorForEnforcedOrganizations のゲートに掛かる。
 */
test('passkey 保有は 2FA 必須組織のゲートを免除しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['two_factor_required' => true])->save();
    Passkey::factory()->for($owner)->create();

    // passkey login 自体は許可される (TOTP 未設定のため)
    expect(app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($owner))->toBeTrue();

    // しかし 2FA 準拠にはならないため業務画面は 2FA 設定へ誘導される
    $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('settings.security'));
});
