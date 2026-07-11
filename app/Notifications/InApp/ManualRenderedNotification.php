<?php

declare(strict_types=1);

namespace App\Notifications\InApp;

use App\DataTransferObjects\Notification\ManualJobPayload;
use App\Enums\Notification\NotificationType;

/**
 * レンダジョブ (kind=render のみ) の terminal (succeeded/failed) 通知。
 * preview は通知しない (NotificationCenterService::notifyRenderFinished が guard)。
 */
final class ManualRenderedNotification extends AppNotification
{
    public function __construct(
        private readonly int $organizationId,
        private readonly ManualJobPayload $payload,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::ManualRendered;
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
