<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * 認証系イベント → security_audit_events の記録 (subscriber)。
 * EventServiceProvider ではなく Event::subscribe で明示登録する。
 */
class RecordSecurityEvent
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Failed::class, [self::class, 'handleFailed']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
        $events->listen(PasswordReset::class, [self::class, 'handlePasswordReset']);
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
        $events->listen(RecoveryCodesGenerated::class, [self::class, 'handleRecoveryCodesGenerated']);
    }

    public function handleLogin(Login $event): void
    {
        $this->recorder->record(SecurityEventType::Login, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        // user が特定できた失敗のみ記録する (email 列挙の助けになる平文 email は残さない)
        $this->recorder->record(SecurityEventType::LoginFailed, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        $this->recorder->record(SecurityEventType::Logout, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->recorder->record(SecurityEventType::PasswordReset, $this->asUser($event->user));
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->recorder->record(SecurityEventType::TwoFactorEnabled, $this->asUser($event->user));
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->recorder->record(SecurityEventType::TwoFactorDisabled, $this->asUser($event->user));
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->recorder->record(SecurityEventType::TwoFactorEnabled, $this->asUser($event->user), [
            'action' => 'recovery_codes_generated',
        ]);
    }

    private function asUser(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
