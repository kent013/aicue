<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery\MockInterface;

function fakeSocialiteUser(string $id, string $email, string $name = 'SSO User'): SocialiteUserContract
{
    /** @var SocialiteUserContract&MockInterface $user */
    $user = Mockery::mock(SocialiteUserContract::class);
    $user->shouldReceive('getId')->andReturn($id);
    $user->shouldReceive('getEmail')->andReturn($email);
    $user->shouldReceive('getName')->andReturn($name);

    return $user;
}

function fakeSocialiteCallback(SocialiteUserContract $user): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

test('SSO 開始は GET の redirect (form POST を要求しない)', function (): void {
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/oauth'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $response = $this->get('/auth/google/redirect/login');

    $response->assertRedirect('https://accounts.google.com/oauth');
});

test('SSO register は規約同意なしでは開始できない', function (): void {
    $response = $this->get('/auth/google/redirect/register');

    $response->assertRedirect(route('register'));
    $response->assertSessionHasErrors('terms_accepted');
});

test('SSO register で User + SocialAccount が作成されログインされる', function (): void {
    $this->withSession(['social_auth_intent' => 'register']);
    fakeSocialiteCallback(fakeSocialiteUser('g-1', 'sso@example.com'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::whereBlind('email', 'email_index', 'sso@example.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->terms_accepted_at)->not->toBeNull();
    expect($user->socialAccounts()->where('provider', 'google')->exists())->toBeTrue();
});

test('SSO login は未連携アカウントを自動登録しない', function (): void {
    $this->withSession(['social_auth_intent' => 'login']);
    fakeSocialiteCallback(fakeSocialiteUser('g-2', 'unknown@example.com'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::whereBlind('email', 'email_index', 'unknown@example.com')->exists())->toBeFalse();
});

test('SSO register はメール一致の既存ユーザーへ自動リンクしない (乗っ取り防止)', function (): void {
    User::factory()->create(['email' => 'victim@example.com']);
    $this->withSession(['social_auth_intent' => 'register']);
    fakeSocialiteCallback(fakeSocialiteUser('g-3', 'victim@example.com'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('register'));
    $this->assertGuest();
    expect(SocialAccount::query()->where('provider_user_id', 'g-3')->exists())->toBeFalse();
});

test('連携済みアカウントは login intent でログインできる', function (): void {
    $user = User::factory()->create(['email' => 'linked@example.com']);
    $social = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-4']);
    $social->user()->associate($user);
    $social->save();

    $this->withSession(['social_auth_intent' => 'login']);
    fakeSocialiteCallback(fakeSocialiteUser('g-4', 'linked@example.com'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('無効なプロバイダは 404', function (): void {
    $this->get('/auth/unknown/redirect/login')->assertNotFound();
});
