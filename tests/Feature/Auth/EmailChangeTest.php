<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Notifications\EmailChangedSecurityNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

test('メール変更時に旧アドレスへセキュリティ通知が送られ再検証が要求される', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    app(UpdateUserProfileInformation::class)->update($user, [
        'name' => $user->name,
        'email' => 'new@example.com',
    ]);

    $user->refresh();
    expect($user->email)->toBe('new@example.com');
    expect($user->email_verified_at)->toBeNull();

    // 旧アドレスへの on-demand 通知 (新アドレスは本文に含めない)
    Notification::assertSentTo(
        new AnonymousNotifiable,
        EmailChangedSecurityNotification::class,
        function ($notification, $channels, $notifiable): bool {
            return $notifiable->routes['mail'] === 'old@example.com';
        },
    );
});

test('他ユーザーの email へは変更できない (中立メッセージ)', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'me@example.com']);

    expect(fn () => app(UpdateUserProfileInformation::class)->update($user, [
        'name' => $user->name,
        'email' => 'taken@example.com',
    ]))->toThrow(ValidationException::class);

    expect($user->refresh()->email)->toBe('me@example.com');
});

test('email 変更なしの name 更新では通知も再検証も発生しない', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'me@example.com']);

    app(UpdateUserProfileInformation::class)->update($user, [
        'name' => '新しい名前',
        'email' => 'me@example.com',
    ]);

    $user->refresh();
    expect($user->name)->toBe('新しい名前');
    expect($user->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});
