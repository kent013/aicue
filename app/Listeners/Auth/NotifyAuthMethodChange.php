<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Enums\Auth\AuthMethodChangeEvent;
use App\Models\User;
use App\Services\Security\AuthMethodChangeNotifier;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * 認証手段変更 → 本人へのメール通知 (T110)。
 *
 * `App\Listeners\RecordSecurityEvent` と同じ構成 (vendor イベント購読 + イベント化
 * できない経路は Service から直接呼ぶ) に倣う。イベント化できない経路
 * (パスワード設定/変更・SSO 連携) は `PasswordCredentialService` / `SocialAccountService`
 * から直接 `AuthMethodChangeNotifier` を呼ぶ (本 listener の対象外)。
 *
 * `Event::subscribe` で明示登録する (`AppServiceProvider::boot()`)。
 */
class NotifyAuthMethodChange
{
    public function __construct(
        private readonly AuthMethodChangeNotifier $notifier,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PasswordReset::class, [self::class, 'handlePasswordReset']);
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
        $events->listen(RecoveryCodesGenerated::class, [self::class, 'handleRecoveryCodesGenerated']);
        $events->listen(PasskeyRegistered::class, [self::class, 'handlePasskeyRegistered']);
        $events->listen(PasskeyDeleted::class, [self::class, 'handlePasskeyDeleted']);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::PasswordReset);
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::TwoFactorEnabled);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::TwoFactorDisabled);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::RecoveryCodesRegenerated);
    }

    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::PasskeyRegistered);
    }

    /**
     * `PasskeyDeleted` は `EnsureLoginMethodRemains` が課す transaction (ロック取得〜
     * controller〜同期 listener〜レスポンス生成まで) の内側で同期発火する
     * (`tests/Architecture/PasskeyPackageContractTest.php` が同期購読者の顔ぶれと
     * 購読順を pin する)。したがって本ハンドラも他イベントと同様にその場で
     * `notify()` を呼べばよい — キュー投入 (`jobs` 行の INSERT) はこの listener を
     * 呼び出している業務トランザクションに自然に参加し、rollback すれば jobs 行ごと
     * 消え、commit と同時に耐久化される (AGENTS.md ドメイン規約 11)。
     */
    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::PasskeyDeleted);
    }

    private function notify(mixed $user, AuthMethodChangeEvent $event): void
    {
        $user = $this->asUser($user);
        if ($user === null) {
            return;
        }

        $this->notifier->notify($user, $event);
    }

    private function asUser(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
