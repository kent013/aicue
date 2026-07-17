<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Onboarding;

/**
 * 未契約 + manageBilling 権限なし member 向け説明画面の props。
 *
 * ownerName / ownerEmail は Owner 不在 org でも描画が壊れないよう null 許容
 * (Organization::routeNotificationForMail() と同じ null 許容)。
 *
 * @phpstan-type BillingRequiredShape array{
 *   ownerName: string|null,
 *   ownerEmail: string|null,
 *   contactUrl: string
 * }
 */
final readonly class BillingRequiredDto
{
    public function __construct(
        public ?string $ownerName,
        public ?string $ownerEmail,
        public string $contactUrl,
    ) {}

    /**
     * @return BillingRequiredShape
     */
    public function toArray(): array
    {
        return [
            'ownerName' => $this->ownerName,
            'ownerEmail' => $this->ownerEmail,
            'contactUrl' => $this->contactUrl,
        ];
    }
}
