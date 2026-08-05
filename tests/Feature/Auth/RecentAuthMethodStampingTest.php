<?php

declare(strict_types=1);

use App\Models\Passkey;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery\MockInterface;

/*
 * recent-auth の satisfier ごとの最終 session state を経路別に固定する。
 *
 * PasskeyVerified は VerifyPasskey::__invoke() の**中**で dispatch されるため
 * login 経路と confirm 経路の両方で発火する。最終状態は「順序」に依存するため
 * (login では StampRecentAuthOnLogin が後勝ちで 'login' を書く)、経路ごとに固定する。
 *
 * **限界**: WebAuthn ceremony はブラウザ API を要するため自動化しない。
 * passkey 経路は「vendor が dispatch する PasskeyVerified を直接発火させて
 * **アプリ側 listener の契約**を検証する」形にとどめる (ceremony の正しさは vendor の責務)。
 */

function stampSocialiteCallback(string $providerUserId): void
{
    /** @var SocialiteUserContract&MockInterface $socialiteUser */
    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
    $socialiteUser->shouldReceive('getId')->andReturn($providerUserId);
    $socialiteUser->shouldReceive('getEmail')->andReturn('stamp@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Stamp User');

    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

test('password 再入力の satisfier は method=password を記録する', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/recent-auth/password', ['password' => 'password'])
        ->assertNoContent();

    expect(session('recent_auth_method'))->toBe('password');
});

test('再SSO の satisfier は method=sso + provider を記録する', function (): void {
    $user = User::factory()->create();
    $account = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'sso-stamp-1']);
    $account->user()->associate($user);
    $account->save();

    stampSocialiteCallback('sso-stamp-1');

    $this->actingAs($user)->get('/auth/google/redirect/step-up');
    $this->actingAs($user)->get('/auth/google/callback');

    expect(session('recent_auth_method'))->toBe('sso');
    expect(session('recent_auth_provider'))->toBe('google');
});

test('通常ログインは method=login を記録する', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    expect(session('recent_auth_method'))->toBe('login');
});

/* ------------------------------------------------------------ passkey 経路 */

test('passkey confirm 経路 (認証済み本人) は method=passkey を記録する', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user);
    $this->startSession();
    // confirm 経路では VerifyPasskey が「認証済みユーザー本人」の文脈で dispatch する
    request()->setUserResolver(static fn (): User => $user);

    PasskeyVerified::dispatch($user, $passkey);

    expect(session('recent_auth_method'))->toBe('passkey');
    expect(session('recent_auth_at'))->toBeInt();
});

test('guest 文脈 (login 経路 / deny 経路) では鮮度を stamp しない', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->startSession();
    request()->setUserResolver(static fn (): ?User => null);

    PasskeyVerified::dispatch($user, $passkey);

    // TOTP 有効ユーザーの passkey login が deny されても guest session に鮮度は残らない
    expect(session()->has('recent_auth_at'))->toBeFalse();
});

test('他人の credential での検証は鮮度を成立させない (本人性バインド)', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $passkey = Passkey::factory()->for($other)->create();

    $this->actingAs($user);
    $this->startSession();
    request()->setUserResolver(static fn (): User => $user);

    PasskeyVerified::dispatch($other, $passkey);

    expect(session()->has('recent_auth_at'))->toBeFalse();
});

/*
 * vendor の PasskeyConfirmationController::store() は `$session->passwordConfirmed()` で
 * **Fortify の auth.password_confirmed_at を書く**。本アプリは RecentAuthState の契約で
 * 「Fortify の鍵には書かない」としているため、Response 差し替えで確実に除去する。
 */
test('passkey confirm の応答は auth.password_confirmed_at を残さない', function (): void {
    $this->startSession();
    session()->put('auth.password_confirmed_at', time());

    $request = Request::create('/passkeys/confirm', 'POST');
    $request->setLaravelSession(session()->driver());

    $response = app(PasskeyConfirmationResponseContract::class)->toResponse($request);

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
    expect($response->getStatusCode())->toBe(204);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
