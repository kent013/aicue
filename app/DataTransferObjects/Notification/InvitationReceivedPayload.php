<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Notification;

/**
 * 組織招待の気づき通知の表示用 payload。
 *
 * 平文 token は含めない (token 平文非保存の既存不変条件。受諾はメールのリンクから行う)。
 * organizationName は発火時点のスナップショット。
 */
final readonly class InvitationReceivedPayload
{
    public function __construct(
        public string $organizationName,
    ) {}

    /**
     * @return array{organization_name: string}
     */
    public function toArray(): array
    {
        return ['organization_name' => $this->organizationName];
    }

    /**
     * 読み出し側の検証復元。型不整合は null 返し (= フロントは fallback 表示)。
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $organizationName = $data['organization_name'] ?? null;
        if (! is_string($organizationName)) {
            return null;
        }

        return new self($organizationName);
    }
}
