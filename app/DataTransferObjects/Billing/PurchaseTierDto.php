<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * チケット傾斜単価の表示用 slim DTO (Stripe Price ID / lookup_key は front へ出さない)。
 *
 * @phpstan-type PurchaseTierShape array{minCount: int, unitAmount: int}
 */
final readonly class PurchaseTierDto
{
    public function __construct(
        public int $minCount,
        public int $unitAmount,
    ) {}

    public static function fromTier(TicketVolumeTier $tier): self
    {
        return new self($tier->minCount, $tier->unitAmount);
    }

    /**
     * @return PurchaseTierShape
     */
    public function toArray(): array
    {
        return [
            'minCount' => $this->minCount,
            'unitAmount' => $this->unitAmount,
        ];
    }
}
