<?php

declare(strict_types=1);

use App\Enums\Auth\AuthMethodChangeEvent;
use App\Models\User;
use App\Notifications\Auth\AuthMethodChangedNotification;
use App\Services\Auth\SocialAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Mockery\MockInterface;

test('ShouldQueue を実装している', function (): void {
    $notification = new AuthMethodChangedNotification(
        AuthMethodChangeEvent::PasswordChanged,
        CarbonImmutable::now(),
    );

    expect($notification)->toBeInstanceOf(ShouldQueue::class);
});

test('via() は mail のみ', function (): void {
    $notification = new AuthMethodChangedNotification(
        AuthMethodChangeEvent::PasswordChanged,
        CarbonImmutable::now(),
    );

    expect($notification->via(new stdClass))->toBe(['mail']);
});

test('event() / occurredAt() / context() の getter が構築時の値をそのまま返す', function (): void {
    $occurredAt = CarbonImmutable::create(2026, 8, 21, 12, 0, 0);
    $notification = new AuthMethodChangedNotification(
        AuthMethodChangeEvent::SocialAccountLinked,
        $occurredAt,
        'Google',
    );

    expect($notification->event())->toBe(AuthMethodChangeEvent::SocialAccountLinked);
    expect($notification->occurredAt())->toBe($occurredAt);
    expect($notification->context())->toBe('Google');
});

test('context 省略時は null', function (): void {
    $notification = new AuthMethodChangedNotification(
        AuthMethodChangeEvent::PasswordSet,
        CarbonImmutable::now(),
    );

    expect($notification->context())->toBeNull();
});

test('toMail() は headline を件名・本文に含む', function (): void {
    $notification = new AuthMethodChangedNotification(
        AuthMethodChangeEvent::TwoFactorEnabled,
        CarbonImmutable::now(),
    );

    $mail = $notification->toMail(new stdClass);

    expect($mail->subject)->toContain('2 段階認証が有効化されました');
});

test('SocialAccountLinked は context (provider 表示名) を本文に含む', function (): void {
    $notification = new AuthMethodChangedNotification(
        AuthMethodChangeEvent::SocialAccountLinked,
        CarbonImmutable::now(),
        'Google',
    );

    $mail = $notification->toMail(new stdClass);

    $lines = collect($mail->introLines)->implode(' ');
    expect($lines)->toContain('Google');
});

/**
 * 秘密情報 (パスワードリセットトークン・2FA 回復コード・TOTP シークレット・パスキーの
 * WebAuthn credential ID・Socialite provider user ID) を本文へ載せていないことの負例
 * (Codex 実装レビュー Round 1 [Warning] への対応。Round 2 [Warning] を受けてテスト名・
 * docblock・検証範囲を実際の契約に合わせて絞った)。
 *
 * **本テストが主張する範囲は次の 3 つだけである** (Round 2 の指摘どおり、
 * `SocialAccountLinked` を含めて「秘密情報を含まない」と主張することはできない —
 * このイベントだけは provider 表示名を本文へ載せる契約が意図的にあるため):
 *
 * 1. `SocialAccountLinked` 以外の 8 case は、`$context` に何を渡しても本文へ一切出さない
 *    (`toMail()` がそもそも `$context` を参照しない実装であることの裏取り)
 * 2. `SocialAccountLinked` は `$context` (provider 表示名) を意図的に本文へ出す
 * 3. 実際の呼び出し元 (`SocialAccountService::linkToUser()`) が `$context` へ渡すのは
 *    `providerLabel()` の戻り値 (config の表示名 or provider 識別子文字列) だけであり、
 *    Socialite の provider user ID (`$socialiteUser->getId()`) を渡していないこと
 *    (呼び出し境界のテスト。`toMail()` 自身が secret を無視する実装だという主張はしない)
 */
test('SocialAccountLinked 以外の 8 case は context を本文へ一切出さない', function (): void {
    $suspiciousContext = 'reset-token-abc123 recovery-code-XYZ789 totp-secret-000000 '
        .'credential-id-deadbeef provider-user-id-999999';

    foreach (AuthMethodChangeEvent::cases() as $event) {
        if ($event === AuthMethodChangeEvent::SocialAccountLinked) {
            continue;
        }

        $notification = new AuthMethodChangedNotification(
            $event,
            CarbonImmutable::now(),
            $suspiciousContext,
        );

        $mail = $notification->toMail(new stdClass);
        $rendered = $mail->subject.' '.collect($mail->introLines)->implode(' ')
            .' '.collect($mail->outroLines)->implode(' ');

        expect($rendered)->not->toContain('reset-token');
        expect($rendered)->not->toContain('recovery-code');
        expect($rendered)->not->toContain('totp-secret');
        expect($rendered)->not->toContain('credential-id');
        expect($rendered)->not->toContain('provider-user-id');
    }
});

test('SocialAccountLinked は context をそのまま本文へ出す (意図的な契約であることの明示)', function (): void {
    $notification = new AuthMethodChangedNotification(
        AuthMethodChangeEvent::SocialAccountLinked,
        CarbonImmutable::now(),
        'provider-user-id-999999',
    );

    $mail = $notification->toMail(new stdClass);
    $rendered = collect($mail->introLines)->implode(' ');

    // 本 case だけは表示用途で context を本文に載せる契約であることの確認。
    // 「安全である」ことの根拠は本テストではなく、呼び出し境界テスト
    // (下記 'SocialAccountService は provider 表示名だけを context へ渡す') が担う。
    expect($rendered)->toContain('provider-user-id-999999');
});

test('SocialAccountService は provider 表示名だけを context へ渡す (provider user ID は渡さない)', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'social-boundary@example.com']);
    /** @var SocialiteUserContract&MockInterface $socialiteUser */
    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
    $socialiteUser->shouldReceive('getId')->andReturn('super-secret-provider-user-id-12345');
    $socialiteUser->shouldReceive('getEmail')->andReturn('social-boundary@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Boundary User');

    app(SocialAccountService::class)->linkToUser('google', $socialiteUser, $user);

    Notification::assertSentTo(
        $user,
        AuthMethodChangedNotification::class,
        function (AuthMethodChangedNotification $n): bool {
            // provider 表示名 (config の label または provider 識別子文字列) であり、
            // Socialite の provider user ID ではないことを固定する。
            expect($n->context())->not->toBeNull();
            expect($n->context())->not->toContain('super-secret-provider-user-id-12345');

            return true;
        },
    );
});
