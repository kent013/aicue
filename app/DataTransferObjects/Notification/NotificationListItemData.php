<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Notification;

use App\Enums\Notification\NotificationType;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Webmozart\Assert\Assert;

/**
 * 通知一覧の読み出し境界 DTO (DatabaseNotification の生配列をページへ渡さない)。
 *
 * - type は NotificationType::tryFrom(rawType)。未知 type (enum⇔DB ドリフト時) は null
 * - rawType は DB の type 文字列そのまま (常に保持 = fallback 表示・toArray の正)
 * - payload は種別ごとの DTO 検証復元 (tryFromArray)。復元失敗は null
 *   (フロントは rawType で fallback 描画)
 */
final readonly class NotificationListItemData
{
    public function __construct(
        public string $id,
        public ?NotificationType $type,
        public string $rawType,
        public ?int $organizationId,
        public ?string $readAt,
        public string $createdAt,
        public ManualJobPayload|InvitationReceivedPayload|TicketBalanceLowPayload|AccountDeletionRequestedPayload|null $payload,
    ) {}

    public static function fromNotification(DatabaseNotification $notification): self
    {
        $rawType = $notification->getAttribute('type');
        Assert::string($rawType);
        $type = NotificationType::tryFrom($rawType);

        // organization_id は morph 生モデルの attribute 取得のため is_int で検証する
        $organizationId = $notification->getAttribute('organization_id');
        $organizationId = is_int($organizationId) ? $organizationId : null;

        $data = $notification->getAttribute('data');
        $data = is_array($data) ? $data : [];

        $payload = match ($type) {
            NotificationType::ManualAnalyzed,
            NotificationType::ManualRendered => ManualJobPayload::tryFromArray($data),
            NotificationType::InvitationReceived => InvitationReceivedPayload::tryFromArray($data),
            NotificationType::TicketBalanceLow => TicketBalanceLowPayload::tryFromArray($data),
            NotificationType::AccountDeletionRequested => AccountDeletionRequestedPayload::tryFromArray($data),
            null => null,
        };

        $readAt = $notification->getAttribute('read_at');
        $createdAt = $notification->getAttribute('created_at');

        $id = $notification->getKey();
        Assert::string($id); // notifications.id は uuid (string PK)

        return new self(
            id: $id,
            type: $type,
            rawType: $rawType,
            organizationId: $organizationId,
            readAt: $readAt instanceof CarbonInterface ? $readAt->toIso8601String() : null,
            createdAt: $createdAt instanceof CarbonInterface ? $createdAt->toIso8601String() : '',
            payload: $payload,
        );
    }

    /**
     * manual 系 (解析/レンダ) の通知として遷移解決できるか
     * (type と payload 形状の両方が揃っているときのみ true)。
     */
    public function isManualJob(): bool
    {
        return in_array($this->type, [NotificationType::ManualAnalyzed, NotificationType::ManualRendered], true)
            && $this->payload instanceof ManualJobPayload;
    }

    /** isManualJob() === true の場合のみ呼び出せる */
    public function projectId(): int
    {
        Assert::isInstanceOf($this->payload, ManualJobPayload::class);

        return $this->payload->projectId;
    }

    /** isManualJob() === true の場合のみ呼び出せる */
    public function manualId(): int
    {
        Assert::isInstanceOf($this->payload, ManualJobPayload::class);

        return $this->payload->manualId;
    }

    /**
     * Inertia typed array (TS 側 NotificationItem と対)。
     * type は常に rawType を返す (TS 側 discriminant。未知値は fallback 描画)。
     *
     * @return array{id: string, type: string, organization_id: int|null,
     *   read_at: string|null, created_at: string, payload: array<string, int|string|bool|null>|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->rawType,
            'organization_id' => $this->organizationId,
            'read_at' => $this->readAt,
            'created_at' => $this->createdAt,
            'payload' => $this->payload?->toArray(),
        ];
    }
}
