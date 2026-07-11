<?php

declare(strict_types=1);

namespace App\Notifications\InApp;

use App\DataTransferObjects\Notification\InvitationReceivedPayload;
use App\Enums\Notification\NotificationType;

/**
 * 組織招待の気づき通知 (既存ユーザー宛のみ)。メール招待の補完であり置換ではない
 * (受諾はメールの受諾リンクから。平文 token は payload に含めない)。
 */
final class InvitationReceivedNotification extends AppNotification
{
    public function __construct(
        private readonly int $organizationId,
        private readonly InvitationReceivedPayload $payload,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::InvitationReceived;
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
