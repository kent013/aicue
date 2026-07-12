<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery\MockInterface;

/*
 * generic recent-auth (step-up 再認証) 機構:
 * RequireRecentAuth middleware / status precheck / password satisfier / SSO step-up intent /
 * fresh login stamp (StampRecentAuthOnLogin)。
 */

/** SSO step-up 用の Socialite callback mock (SocialAuthTest の helper と名前衝突させない) */
function fakeStepUpSocialiteCallback(string $providerUserId): void
{
    /** @var SocialiteUserContract&MockInterface $socialiteUser */
    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
    $socialiteUser->shouldReceive('getId')->andReturn($providerUserId);
    $socialiteUser->shouldReceive('getEmail')->andReturn('step-up@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('StepUp User');

    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

function linkGoogleAccount(User $user, string $providerUserId): void
{
    $account = new SocialAccount(['provider' => 'google', 'provider_user_id' => $providerUserId]);
    $account->user()->associate($user);
    $account->save();
}

/* ---------------------------------------------------------------- middleware */

test('鮮度なしの通常遷移は confirm 画面へ 302 (dropped_mutation flag 付き)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->delete('/settings/account');

    $response->assertRedirect(route('recent-auth.confirm'));
    $response->assertSessionHas('recent_auth.dropped_mutation', true);
});

test('鮮度なしの XHR は 409 + recent_auth_required code (no-store)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->deleteJson('/settings/account');

    $response->assertStatus(409)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'code' => 'recent_auth_required',
            'redirect' => route('recent-auth.confirm'),
        ]);
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('鮮度なしの Inertia mutation は 409 (302 にしない)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->delete('/settings/account');

    $response->assertStatus(409)->assertJsonPath('code', 'recent_auth_required');
});

test('stale な recent_auth_at (timeout 超過) はブロックされる', function (): void {
    $user = User::factory()->create();
    $timeout = config()->integer('auth.recent_auth_timeout');

    $response = $this->actingAs($user)
        ->withSession(['recent_auth_at' => time() - $timeout - 1])
        ->delete('/settings/account');

    $response->assertRedirect(route('recent-auth.confirm'));
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('外部 origin の referer は intended に採用されない (open redirect 防止)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['referer' => 'https://evil.example.com/phish'])
        ->delete('/settings/account')
        ->assertRedirect(route('recent-auth.confirm'));

    expect(session('url.intended'))->toBe(route('dashboard'));
});

/* ------------------------------------------- fortify password.confirm 救済 redirect */

test('GET /user/confirm-password 直アクセスは recent-auth confirm へ 302 (500 にしない)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/user/confirm-password');

    $response->assertRedirect(route('recent-auth.confirm'));
});

test('GET /user/confirm-password は追従すると 200 で ConfirmRecentAuth フォームが出る', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->followingRedirects()->get('/user/confirm-password');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ConfirmRecentAuth')
            ->where('passwordSet', true)
            ->where('canSatisfy', true));
});

test('GET /user/confirm-password は未ログインなら login へ redirect (既存 auth ガード)', function (): void {
    $this->get('/user/confirm-password')->assertRedirect(route('login'));
});

test('GET /user/confirm-password の救済 redirect は再認証の stamp をしない', function (): void {
    // 誤用防止の回帰ガード: この redirect は「画面への誘導」であり、password.confirm
    // middleware 互換 (auth.password_confirmed_at) も recent-auth 鮮度 (recent_auth_at) も
    // 付与しない (Codex 詳細レビュー Round 1 Warning 対応)。
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/user/confirm-password');

    $response->assertRedirect(route('recent-auth.confirm'))
        ->assertSessionMissing('auth.password_confirmed_at')
        ->assertSessionMissing('recent_auth_at');
});

/* ---------------------------------------------------------------- confirm 画面 / status */

test('confirm 画面は passwordSet / availableProviders / canSatisfy を返す', function (): void {
    $user = User::factory()->create();
    linkGoogleAccount($user, 'g-1');

    $response = $this->actingAs($user)->get('/recent-auth/confirm');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ConfirmRecentAuth')
            ->where('passwordSet', true)
            ->where('canSatisfy', true)
            ->where('availableProviders.0.provider', 'google')
            ->where('availableProviders.0.reauthUrl', route('social.redirect', ['provider' => 'google', 'intent' => 'step-up'])));
});

test('status は鮮度と satisfier 情報を返す (no-store)', function (): void {
    $user = User::factory()->create();

    // 鮮度なし
    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'recent' => false,
            'passwordSet' => true,
            'canSatisfy' => true,
            'confirmedAt' => null,
        ]);

    // 鮮度あり (confirmedAt は epoch を露出)
    $at = time() - 10;
    $this->actingAs($user)
        ->withSession(['recent_auth_at' => $at])
        ->getJson('/recent-auth/status')
        ->assertOk()
        ->assertJson(['recent' => true, 'confirmedAt' => $at]);
});

test('status: 連携 provider は capability に応じて再SSO 候補になる', function (): void {
    $user = User::factory()->create();
    linkGoogleAccount($user, 'g-1');

    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertOk()
        ->assertJsonPath('availableProviders.0.provider', 'google')
        ->assertJsonPath('availableProviders.0.capability', 'fresh_auth_prompt_only');

    // capability 未宣言 (identity_only 扱い) の provider は候補から除外される (fail-closed)
    config(['template.social_providers.google.capability' => null]);
    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertOk()
        ->assertJsonPath('availableProviders', []);
});

/* ---------------------------------------------------------------- password satisfier */

test('password satisfier: XHR は 204 で鮮度が stamp される', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/recent-auth/password', [
        'password' => 'password',
    ]);

    $response->assertNoContent();
    expect(session('recent_auth_at'))->toBeInt();
    expect(session('recent_auth_method'))->toBe('password');
});

test('password satisfier: 誤ったパスワードは 422 で stamp されない', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/recent-auth/password', [
        'password' => 'wrong-password',
    ])->assertStatus(422);

    expect(session('recent_auth_at'))->toBeNull();
});

test('password satisfier: Inertia は intended へ redirect し dropped_mutation を消費する', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession([
            'url.intended' => '/settings',
            'recent_auth.dropped_mutation' => true,
        ])
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/recent-auth/password', ['password' => 'password']);

    $response->assertRedirect('/settings');
    $response->assertSessionHas('info');
    $response->assertSessionMissing('recent_auth.dropped_mutation');
    expect(session('recent_auth_at'))->toBeInt();
});

test('password 未設定 (SSO-only) ユーザーは password 経路で step-up できない (fail-closed)', function (): void {
    $user = User::factory()->create();
    // hashed cast を迂回して password 未設定状態を作る (SSO-only 相当)
    DB::table('users')->where('id', $user->id)->update(['password' => '']);

    $this->actingAs($user->fresh())->postJson('/recent-auth/password', [
        'password' => 'password',
    ])->assertStatus(422);

    expect(session('recent_auth_at'))->toBeNull();
});

/* ---------------------------------------------------------------- 鮮度あり通過 */

test('鮮度ありなら機微操作を通過する (オーナー移譲は既存テスト参照)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account')
        ->assertRedirect('/');

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

/* ---------------------------------------------------------------- fresh login stamp */

test('fresh login (password) で recent-auth が stamp される (二重壁の防止)', function (): void {
    User::factory()->create(['email' => 'stamp@example.com']);

    $this->post('/login', [
        'email' => 'stamp@example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    expect(session('recent_auth_at'))->toBeInt();
    expect(session('recent_auth_method'))->toBe('login');
});

/* ---------------------------------------------------------------- SSO step-up satisfier */

test('step-up intent の開始は未ログインなら 403', function (): void {
    $this->get('/auth/google/redirect/step-up')->assertForbidden();
});

test('SSO step-up: 本人の連携アカウントなら鮮度が stamp され intended へ戻る', function (): void {
    $user = User::factory()->create();
    linkGoogleAccount($user, 'g-1');
    fakeStepUpSocialiteCallback('g-1');

    $response = $this->actingAs($user)
        ->withSession([
            'social_auth_intent' => 'step-up',
            'url.intended' => '/settings',
        ])
        ->get('/auth/google/callback');

    $response->assertRedirect('/settings');
    expect(session('recent_auth_at'))->toBeInt();
    expect(session('recent_auth_method'))->toBe('sso');
    expect(session('recent_auth_provider'))->toBe('google');
});

test('SSO step-up: 他人のアカウントでの round-trip は成立しない (本人性バインド)', function (): void {
    $user = User::factory()->create();
    linkGoogleAccount($user, 'g-1');
    fakeStepUpSocialiteCallback('g-other');

    $response = $this->actingAs($user)
        ->withSession(['social_auth_intent' => 'step-up'])
        ->get('/auth/google/callback');

    $response->assertRedirect(route('recent-auth.confirm'));
    $response->assertSessionHasErrors('password');
    expect(session('recent_auth_at'))->toBeNull();
});
