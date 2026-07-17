<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\PersonalPlanIneligibleReason;

/**
 * Personal (free) プランの選択可否 (UI 表示用)。
 *
 * reasonLabel はサーバー側 enum で確定した表示文言 (frontend に文言マッピングを散らさない)。
 *
 * @phpstan-type PersonalPlanEligibilityShape array{
 *   eligible: bool,
 *   reason: string|null,
 *   reasonLabel: string|null
 * }
 */
final readonly class PersonalPlanEligibilityDto
{
    private function __construct(
        public bool $eligible,
        public ?PersonalPlanIneligibleReason $reason,
    ) {}

    public static function eligible(): self
    {
        return new self(eligible: true, reason: null);
    }

    public static function ineligible(PersonalPlanIneligibleReason $reason): self
    {
        return new self(eligible: false, reason: $reason);
    }

    /**
     * @return PersonalPlanEligibilityShape
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'reason' => $this->reason?->value,
            'reasonLabel' => $this->reason?->label(),
        ];
    }
}
