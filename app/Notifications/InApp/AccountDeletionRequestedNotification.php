<?php

declare(strict_types=1);

namespace App\Notifications\InApp;

use App\DataTransferObjects\Notification\AccountDeletionRequestedPayload;
use App\Enums\Notification\NotificationType;

/**
 * 退会予約 (猶予期間つき削除) のアプリ内通知。
 *
 * 凍結中でも `notifications.index` は allowlist で通るため、「いつ消えるか」を
 * 本人が読める経路として機能する (メールが届かない環境の保険)。
 *
 * ★`organizationId` は**予約時点の current org** である。退会そのものは組織に属さない事象だが、
 *   本アプリのアプリ内通知は org 文脈を必須とする設計 (`AppNotification::organizationId()` が
 *   non-nullable) のため、表示の文脈として current org を写す。current org を持たない
 *   ユーザーには**アプリ内通知を作らない** (メールだけが届く) —
 *   `NotificationCenterService` 側で判定する。
 */
final class AccountDeletionRequestedNotification extends AppNotification
{
    public function __construct(
        private readonly int $organizationId,
        private readonly AccountDeletionRequestedPayload $payload,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::AccountDeletionRequested;
    }

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload->toArray();
    }
}
