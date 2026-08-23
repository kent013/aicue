<?php

declare(strict_types=1);

use App\Enums\Auth\AuthMethodChangeEvent;
use App\Enums\SecurityEventType;
use App\Models\Passkey;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Notifications\Auth\AuthMethodChangedNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery\MockInterface;
use PragmaRX\Google2FA\Google2FA;

/*
 * 認証手段変更のメール通知ポリシー (T110)。
 *
 * テストレーンを分離する (Notification::fake() と jobs テーブル観測を同一テストで
 * 両立させない):
 *   1. イベント → enum 対応の正しさ: Notification::fake()
 *   2. queue 投入件数の確認: config(['queue.default' => 'database']) + jobs テーブル
 */

/** 直近の queue jobs テーブルに積まれた AuthMethodChangedNotification 系ジョブの件数。 */
function authMethodChangeJobCount(): int
{
    return DB::table('jobs')
        ->where('payload', 'like', '%AuthMethodChangedNotification%')
        ->count();
}

function fakeGoogleSocialiteUser(string $id, string $email, string $name = 'SSO User'): SocialiteUserContract
{
    /** @var SocialiteUserContract&MockInterface $user */
    $user = Mockery::mock(SocialiteUserContract::class);
    $user->shouldReceive('getId')->andReturn($id);
    $user->shouldReceive('getEmail')->andReturn($email);
    $user->shouldReceive('getName')->andReturn($name);

    return $user;
}

function fakeGoogleSocialiteCallback(SocialiteUserContract $user): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

/* ------------------------------------------------------------ パスワード */

test('PUT /user/password (変更) は PasswordChanged 通知 1 件を送り、他イベントは送らない', function (): void {
    Notification::fake();
    $user = User::factory()->create(['password' => Hash::make('current-password')]);

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'current-password',
        'password' => 'BrandNewPassw0rd!x',
    ])->assertSessionHasNoErrors();

    Notification::assertSentTo(
        $user,
        AuthMethodChangedNotification::class,
        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::PasswordChanged,
    );
    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
});

test('PUT /user/password は通知の enqueue が例外化してもパスワード変更自体は成功する (best-effort、実経路)', function (): void {
    Exceptions::fake();

    /** @var Dispatcher&MockInterface $dispatcher */
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('mail queue down'));
    app()->instance(Dispatcher::class, $dispatcher);

    $user = User::factory()->create(['password' => Hash::make('current-password')]);

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'current-password',
        'password' => 'BrandNewPassw0rd!x',
    ])->assertSessionHasNoErrors();

    // パスワード自体は確実に更新されている (通知失敗が主処理を巻き添えにしない)
    expect(Hash::check('BrandNewPassw0rd!x', $user->fresh()->password))->toBeTrue();

    // 例外は握り潰さず report() されている (Codex 実装レビュー Round 1 [Warning] への対応)
    Exceptions::assertReported(RuntimeException::class);
});

test('POST /settings/password (初回設定) は PasswordSet 通知 1 件を送り、他イベントは送らない', function (): void {
    Notification::fake();
    $user = User::factory()->ssoOnly()->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->post('/settings/password', ['password' => 'Str0ngPassphrase99'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo(
        $user,
        AuthMethodChangedNotification::class,
        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::PasswordSet,
    );
    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
});

test('forgot-password → reset-password は PasswordReset 通知 1 件を送る', function (): void {
    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
    $user = User::factory()->create();
    $email = $user->email;

    // ResetPassword (トークン通知) は Notification::fake() 下で捕まえる。
    // AuthMethodChangedNotification の検証は同じ fake 内でまとめて行う。
    Notification::fake();

    $this->post('/forgot-password', ['email' => $email])->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });
    expect($token)->toBeString();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $email,
        'password' => 'CorrectHorse9Battery',
    ])->assertSessionHasNoErrors();

    Notification::assertSentTo(
        $user,
        AuthMethodChangedNotification::class,
        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::PasswordReset,
    );
    // forgot-password 経路で送られる通知の総数が 1 件であること
    // (PasswordCredentialService を経由すると PasswordChanged と二重発火するため将来検出用)
    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
});

/* ------------------------------------------------------------ 2FA */

test('POST 有効化 → confirm (実 TOTP) は TwoFactorEnabled 通知 1 件のみ送る', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->post('/user/two-factor-authentication')
        ->assertRedirect();

    $secret = decrypt($user->fresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($user)
        ->post('/user/confirmed-two-factor-authentication', ['code' => $code])
        ->assertRedirect();

    // 有効化 1 操作からの通知は TwoFactorEnabled の 1 通のみ
    // (vendor の EnableTwoFactorAuthentication は RecoveryCodesGenerated を dispatch しないため)
    Notification::assertSentTo(
        $user,
        AuthMethodChangedNotification::class,
        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::TwoFactorEnabled,
    );
    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
});

test('DELETE /user/two-factor-authentication (無効化) は TwoFactorDisabled 通知 1 件を送る', function (): void {
    Notification::fake();
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->deleteJson('/user/two-factor-authentication')
        ->assertOk();

    Notification::assertSentTo(
        $user,
        AuthMethodChangedNotification::class,
        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::TwoFactorDisabled,
    );
    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
});

test('POST /user/two-factor-recovery-codes (再生成) は RecoveryCodesRegenerated 通知 1 件を送る', function (): void {
    Notification::fake();
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->postJson('/user/two-factor-recovery-codes')
        ->assertOk();

    Notification::assertSentTo(
        $user,
        AuthMethodChangedNotification::class,
        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::RecoveryCodesRegenerated,
    );
    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
});

/* ------------------------------------------------------------ パスキー */

test('パスキー登録イベントは PasskeyRegistered 通知 1 件を送る (vendor イベント境界)', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    PasskeyRegistered::dispatch($user, $passkey);

    Notification::assertSentTo(
        $user,
        AuthMethodChangedNotification::class,
        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::PasskeyRegistered,
    );
});

test('複数手段が残る passkey 削除は PasskeyDeleted 通知の queue job を 1 件積む (jobs テーブル)', function (): void {
    config()->set('queue.default', 'database');
    $user = User::factory()->create(); // password あり = 削除しても手段が残る
    $passkeys = Passkey::factory()->count(2)->for($user)->create();
    $target = $passkeys->firstOrFail();

    expect(authMethodChangeJobCount())->toBe(0);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$target->getKey()}")
        ->assertSessionHasNoErrors();

    expect(authMethodChangeJobCount())->toBe(1);
});

test('唯一のログイン手段の passkey 削除は拒否され、通知 job も 0 件のまま', function (): void {
    config()->set('queue.default', 'database');
    $user = User::factory()->ssoOnly()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertSessionHasErrors('login_method');

    expect(authMethodChangeJobCount())->toBe(0);
});

test('passkey 削除成功後に後続の同期処理が例外を投げると削除自体が rollback し、通知 job も 0 件のまま', function (): void {
    config()->set('queue.default', 'database');
    $user = User::factory()->create();
    $passkeys = Passkey::factory()->count(2)->for($user)->create();
    $target = $passkeys->firstOrFail();

    Event::listen(PasskeyDeleted::class, function (): void {
        throw new RuntimeException('listener failure');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->delete("/user/passkeys/{$target->getKey()}"))
        ->toThrow(RuntimeException::class, 'listener failure');

    // 行も監査記録も同じ transaction で巻き戻る (既存 PasskeyDeletionAtomicityTest と同型)
    expect(Passkey::query()->whereKey($target->getKey())->exists())->toBeTrue();
    expect(SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::PasskeyDeleted->value)
        ->count())->toBe(0);

    // 通知 job も 0 件のまま (state の保存とキュー投入が同一トランザクションに乗るため、
    // rollback で jobs 行ごと消える。AGENTS.md ドメイン規約 11)
    expect(authMethodChangeJobCount())->toBe(0);
});

/* ------------------------------------------------------------ SSO 連携 */

test('既存ログイン中ユーザーへの追加連携 (intent=link) は SocialAccountLinked 通知 1 件を送る', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'link-target@example.com']);
    fakeGoogleSocialiteCallback(fakeGoogleSocialiteUser('g-link-1', 'link-target@example.com'));

    $this->actingAs($user)
        ->withSession(['social_auth_intent' => 'link'])
        ->get('/auth/google/callback')
        ->assertRedirect(route('settings.security'));

    Notification::assertSentTo(
        $user,
        AuthMethodChangedNotification::class,
        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::SocialAccountLinked
            && $n->context() === 'Google',
    );
    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
});

test('新規 SSO 登録 (intent=register) は通知を送らないが監査記録は従来どおり残る', function (): void {
    Notification::fake();
    $this->withSession(['social_auth_intent' => 'register']);
    fakeGoogleSocialiteCallback(fakeGoogleSocialiteUser('g-register-1', 'new-sso-user@example.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('app.entry'));

    $user = User::whereBlind('email', 'email_index', 'new-sso-user@example.com')->firstOrFail();

    Notification::assertNothingSentTo($user);

    // 監査記録 (SecurityEventType::SocialAccountLinked) は従来どおり記録される
    // (通知と監査で対象範囲が意図的に異なる)
    expect(SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::SocialAccountLinked->value)
        ->where('user_id', $user->getKey())
        ->exists())->toBeTrue();
});
