<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Onboarding\IntendedPlanResolver;
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
    // T105: google は email_trust=confirmed 宣言のため、trust policy 導入後も検証済みで作られる (挙動不変)
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->terms_accepted_at)->not->toBeNull();
    expect($user->socialAccounts()->where('provider', 'google')->exists())->toBeTrue();

    // verified ゲートを素通りして dashboard に到達できる (従来どおり)
    $this->get(route('dashboard'))->assertOk();
});

test('T105: email_trust=unconfirmed の provider では SSO register が検証済みにしない', function (): void {
    // nOAuth 対策の継ぎ目。confirmed を名乗れない provider は IdP の email 主張を信頼せず、
    // アプリ側のメール到達確認 (/email/verify) を経てから検証済みにする。
    config(['template.social_providers.google.email_trust' => 'unconfirmed']);

    $this->withSession(['social_auth_intent' => 'register']);
    fakeSocialiteCallback(fakeSocialiteUser('g-untrusted', 'untrusted@example.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::whereBlind('email', 'email_index', 'untrusted@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();

    // verified ゲートに落ちる (dashboard へ到達できない)
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('T105: email_trust 未宣言の provider は fail-closed で検証済みにしない', function (): void {
    // 宣言漏れ (SocialProviderTrustPolicyTest が CI で先に落とす) が万一すり抜けても、
    // 実行時は Unconfirmed に倒れることを固定する。
    config(['template.social_providers.google.email_trust' => null]);

    $this->withSession(['social_auth_intent' => 'register']);
    fakeSocialiteCallback(fakeSocialiteUser('g-undeclared', 'undeclared@example.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

    $user = User::whereBlind('email', 'email_index', 'undeclared@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
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

test('P7: SSO register 開始は ?plan= を pending に積む (allowlist 照合済み)', function (): void {
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/oauth'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->get('/auth/google/redirect/register?terms_accepted=1&plan=standard')
        ->assertRedirect('https://accounts.google.com/oauth');

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBe('standard');
});

test('P7: SSO register 開始で plan 不在・Enterprise・未知値は stale pending を消す', function (string $query): void {
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/oauth'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    session([IntendedPlanResolver::PENDING_KEY => 'business']);

    $this->get('/auth/google/redirect/register?terms_accepted=1'.$query);

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
})->with([
    'plan 不在' => [''],
    'enterprise' => ['&plan=enterprise'],
    '未知値' => ['&plan=foo'],
]);

test('P7: SSO login 開始は pending を forget する', function (): void {
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/oauth'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    session([IntendedPlanResolver::PENDING_KEY => 'standard']);

    $this->get('/auth/google/redirect/login');

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
});

test('P7: SSO register 成立で pending が個人組織へ promote される', function (): void {
    $this->withSession([
        'social_auth_intent' => 'register',
        IntendedPlanResolver::PENDING_KEY => 'standard',
    ]);
    fakeSocialiteCallback(fakeSocialiteUser('g-p7', 'sso-plan@example.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

    $user = User::whereBlind('email', 'email_index', 'sso-plan@example.com')->firstOrFail();
    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBe('standard');
});

test('P7: SSO register 拒否分岐 (email 衝突) は pending を残さない', function (): void {
    User::factory()->create(['email' => 'victim-p7@example.com']);
    $this->withSession([
        'social_auth_intent' => 'register',
        IntendedPlanResolver::PENDING_KEY => 'standard',
    ]);
    fakeSocialiteCallback(fakeSocialiteUser('g-p7-reject', 'victim-p7@example.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('register'));

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
});

test('P7: SSO login の未連携拒否分岐も pending を残さない', function (): void {
    $this->withSession([
        'social_auth_intent' => 'login',
        IntendedPlanResolver::PENDING_KEY => 'standard',
    ]);
    fakeSocialiteCallback(fakeSocialiteUser('g-p7-login', 'unknown-p7@example.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('login'));

    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
});

test('無効なプロバイダは 404', function (): void {
    $this->get('/auth/unknown/redirect/login')->assertNotFound();
});
