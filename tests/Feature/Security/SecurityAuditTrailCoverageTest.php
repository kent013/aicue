<?php

declare(strict_types=1);

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Support\EmailHash;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery\MockInterface;

/*
 * security_audit_events の記録経路のうち、**担保テストが存在しなかった event_type** を
 * 実際に記録されるところまで固定する (T108 S7)。
 *
 * tests/Architecture/SecurityEventCoverageTest が「全 case に担保テストがあること」を
 * deny-by-default で機械保証するようになったため、その要求を満たす実体としてここに置く。
 * 既に担保のある case は既存テストが引き続き担う (map の covered_by がその対応表)。
 *
 * 検証の境界: RecordSecurityEvent は event subscriber なので「対象イベントが発火したら
 * 行が増える」ことが記録経路の契約。HTTP で安く駆動できるものは実走し、
 * vendor 内部でしか発火しないもの (パスワードリセット完了 / TOTP 確定・解除) は
 * フレームワークのイベントを直接 dispatch して listener 側を固定する。
 */

/** 指定 event_type の行数。 */
function auditTrailCount(SecurityEventType $type): int
{
    return SecurityAuditEvent::query()->where('event_type', $type->value)->count();
}

test('ログイン失敗が login_failed として記録される', function (): void {
    $user = User::factory()->create(['email' => 'failed@example.com']);

    $this->from('/login')->post('/login', [
        'email' => 'failed@example.com',
        'password' => 'wrong-password',
    ]);

    expect(auditTrailCount(SecurityEventType::LoginFailed))->toBe(1);
    expect(
        SecurityAuditEvent::query()
            ->where('event_type', 'login_failed')
            ->where('user_id', $user->id)
            ->exists(),
    )->toBeTrue();
});

test('メールアドレス変更が email_changed として記録され metadata に鍵つきハッシュ 2 値が残る', function (): void {
    $user = User::factory()->create(['email' => 'before@example.com']);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->put('/user/profile-information', [
            'name' => '変更後の名前',
            'email' => 'after@example.com',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()?->email)->toBe('after@example.com');
    expect(auditTrailCount(SecurityEventType::EmailChanged))->toBe(1);

    // 家系の裁定 AG-195: 変更前後のアドレスの鍵つきハッシュ 2 値を残す
    // (生アドレスと鍵なしの変換値は載せない)。キー名は家系で共通。
    $event = SecurityAuditEvent::query()
        ->where('event_type', 'email_changed')
        ->firstOrFail();
    expect($event->user_id)->toBe($user->id)
        ->and($event->metadata)->toBe([
            'old_email_hash' => EmailHash::compute('before@example.com'),
            'new_email_hash' => EmailHash::compute('after@example.com'),
        ]);
});

test('email_changed の監査 metadata は鍵つきハッシュだけで生アドレスを含まない', function (): void {
    $user = User::factory()->create(['email' => 'before@example.com']);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->put('/user/profile-information', [
            'name' => '変更後の名前',
            'email' => 'after@example.com',
        ])
        ->assertSessionHasNoErrors();

    // モデルの cast を通した配列ではなく、**DB に保存された JSON 文字列**を見る
    // (cast 越しでは「保存された文字列に平文が混ざっていないこと」を言えないため)。
    $raw = DB::table('security_audit_events')
        ->where('event_type', 'email_changed')
        ->where('user_id', $user->id)
        ->value('metadata');
    expect($raw)->toBeString();
    $json = (string) $raw;

    // 局所部・ドメインのいずれも現れない。3 語とも hex (0-9a-f) 以外の文字を含むため、
    // 64 桁のハッシュ値に偶然含まれることはない ('o' / 't' / 'x' が hex ではない)。
    expect($json)->not->toContain('before')
        ->and($json)->not->toContain('after')
        ->and($json)->not->toContain('example.com');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    // 2 値が互いに異なることは**検査しない**。変更判定が正規化を挟まないため、
    // 大文字小文字だけが違う変更では 2 値が一致し得る (観測専用なので一致に意味を持たせない)。
    expect(array_keys($decoded))->toBe(['old_email_hash', 'new_email_hash']);
    expect($decoded['old_email_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($decoded['new_email_hash'])->toMatch('/^[0-9a-f]{64}$/');
});

test('ソーシャルアカウント連携が social_account_linked として記録される', function (): void {
    $user = User::factory()->create(['email' => 'link@example.com']);

    /** @var SocialiteUserContract&MockInterface $socialiteUser */
    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
    $socialiteUser->shouldReceive('getId')->andReturn('g-link-1');
    $socialiteUser->shouldReceive('getEmail')->andReturn('link@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Linked User');

    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->actingAs($user)
        ->withSession(['social_auth_intent' => 'link'])
        ->get('/auth/google/callback');

    expect(auditTrailCount(SecurityEventType::SocialAccountLinked))->toBe(1);
    $event = SecurityAuditEvent::query()
        ->where('event_type', 'social_account_linked')
        ->firstOrFail();
    expect($event->metadata)->toBe(['provider' => 'google']);
});

test('パスワードリセット完了が password_reset として記録される', function (): void {
    // Illuminate\Auth\Events\PasswordReset は Fortify / Laravel のリセット完了で発火する
    $user = User::factory()->create();

    event(new PasswordReset($user));

    expect(auditTrailCount(SecurityEventType::PasswordReset))->toBe(1);
    expect(
        SecurityAuditEvent::query()
            ->where('event_type', 'password_reset')
            ->where('user_id', $user->id)
            ->exists(),
    )->toBeTrue();
});

test('2 段階認証の確定が two_factor_enabled として記録される', function (): void {
    $user = User::factory()->create();

    TwoFactorAuthenticationConfirmed::dispatch($user);

    expect(auditTrailCount(SecurityEventType::TwoFactorEnabled))->toBe(1);
    expect(
        SecurityAuditEvent::query()
            ->where('event_type', 'two_factor_enabled')
            ->where('user_id', $user->id)
            ->exists(),
    )->toBeTrue();
});

test('2 段階認証の解除が two_factor_disabled として記録される', function (): void {
    $user = User::factory()->create();

    TwoFactorAuthenticationDisabled::dispatch($user);

    expect(auditTrailCount(SecurityEventType::TwoFactorDisabled))->toBe(1);
    expect(
        SecurityAuditEvent::query()
            ->where('event_type', 'two_factor_disabled')
            ->where('user_id', $user->id)
            ->exists(),
    )->toBeTrue();
});

test('ログアウトが logout として記録される', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    expect(auditTrailCount(SecurityEventType::Logout))->toBe(1);
    expect(
        SecurityAuditEvent::query()
            ->where('event_type', 'logout')
            ->where('user_id', $user->id)
            ->exists(),
    )->toBeTrue();
});
