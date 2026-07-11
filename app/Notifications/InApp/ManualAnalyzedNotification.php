<?php

declare(strict_types=1);

namespace App\Notifications\InApp;

use App\DataTransferObjects\Notification\ManualJobPayload;
use App\Enums\Notification\NotificationType;

/**
 * AI 解析ジョブの terminal (succeeded/failed) 通知。宛先は creator ∪ triggeredBy
 * (NotificationCenterService::notifyAnalysisFinished が組み立てる)。
 */
final class ManualAnalyzedNotification extends AppNotification
{
    public function __construct(
        private readonly int $organizationId,
        private readonly ManualJobPayload $payload,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::ManualAnalyzed;
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
