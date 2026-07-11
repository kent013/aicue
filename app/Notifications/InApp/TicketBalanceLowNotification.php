<?php

declare(strict_types=1);

namespace App\Notifications\InApp;

use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
use App\Enums\Notification\NotificationType;

/**
 * チケット残高低下通知 (org の owner/admin 宛)。reserve の閾値クロス検知でのみ発火する
 * (TicketLedgerService::reserve)。billing_notifications (メール送達台帳) には行を作らない。
 */
final class TicketBalanceLowNotification extends AppNotification
{
    public function __construct(
        private readonly int $organizationId,
        private readonly TicketBalanceLowPayload $payload,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::TicketBalanceLow;
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
