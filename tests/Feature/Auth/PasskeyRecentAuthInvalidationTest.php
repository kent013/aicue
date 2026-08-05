<?php

declare(strict_types=1);

use App\Models\Passkey;
use App\Models\User;
use App\Security\RecentAuthState;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

/*
 * 2026-08-04 裁定 A: **credential 集合の変化 = recent-auth 失効**。
 *
 * パスキーは単独でログインできる強い資格であり、集合が変わったら直前に済ませた
 * 本人確認は失効させる (家系統一原則)。UX の実害は「登録直後のタップ 1 回」に限られる。
 *
 * **裁定で見送られた強化オプション (登録直後の passkey を satisfier から除外する) は
 * 実装しない**。そのことも本テストが明示的に固定する (実装されたら fail する)。
 */

test('passkey 削除で recent-auth 鮮度が失効する (実 HTTP 経路)', function (): void {
    $user = User::factory()->create();   // password あり = 削除しても手段が残る
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertSessionHasNoErrors();

    expect(session()->has('recent_auth_at'))->toBeFalse();
});

test('passkey 削除の直後は機微操作が step-up を要求する', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertSessionHasNoErrors();

    // 同一 session で続けてアカウント削除 (recent-auth 必須) を試みる
    $this->actingAs($user)
        ->delete('/settings/account')
        ->assertRedirect(route('recent-auth.confirm'));

    expect(User::query()->whereKey($user->getKey())->exists())->toBeTrue();
});

/*
 * 登録経路は WebAuthn ceremony を要するため HTTP では実走できない。
 * vendor が dispatch する PasskeyRegistered に対する **listener の契約**を固定する。
 */
test('passkey 登録で recent-auth 鮮度が失効する', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->startSession();
    app(RecentAuthState::class)->confirm(method: 'password');
    expect(session()->has('recent_auth_at'))->toBeTrue();

    PasskeyRegistered::dispatch($user, $passkey);

    expect(session()->has('recent_auth_at'))->toBeFalse();
    expect(session()->has('recent_auth_method'))->toBeFalse();
});

test('PasskeyDeleted イベント単体でも鮮度が失効する', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->startSession();
    app(RecentAuthState::class)->confirm(method: 'password');

    PasskeyDeleted::dispatch($user, $passkey);

    expect(session()->has('recent_auth_at'))->toBeFalse();
});

/*
 * **裁定で見送られた強化オプションが実装されていないこと**の明示。
 * 「登録直後の passkey は satisfier に使えない」を実装すると本テストが fail する。
 * 再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時。
 */
test('登録直後の passkey でも再認証 (satisfier) は成立する', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user);
    $this->startSession();
    request()->setUserResolver(static fn (): User => $user);

    // 登録 → 鮮度失効
    PasskeyRegistered::dispatch($user, $passkey);
    expect(session()->has('recent_auth_at'))->toBeFalse();

    // その passkey で confirm すると鮮度が成立する (裁定どおり除外しない)
    PasskeyVerified::dispatch($user, $passkey);
    expect(session('recent_auth_method'))->toBe('passkey');
});
