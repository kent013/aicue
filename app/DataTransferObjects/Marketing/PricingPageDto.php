<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Marketing;

use App\DataTransferObjects\Billing\PurchaseTierDto;

/**
 * 料金表 (/pricing) の Inertia page prop (単一真実源の shape 定義)。
 *
 * TS 側は resources/js/types/marketing.ts の PricingPageProps と exact 対で保守する。
 *
 * @phpstan-import-type PricingPlanShape from PricingPlanDto
 * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
 *
 * @phpstan-type PricingPageShape array{
 *   plans: list<PricingPlanShape>,
 *   ticketTiers: list<PurchaseTierShape>,
 *   spotUnitAmountJpy: int,
 *   signupGrantTickets: int,
 *   signupGrantExpiryDays: int,
 *   analysisTicketCost: int,
 *   renderTicketCost: int,
 *   isAuthenticated: bool,
 *   contactUrl: string,
 *   contactIsExternal: bool
 * }
 */
final readonly class PricingPageDto
{
    /**
     * @param  list<PricingPlanDto>  $plans
     * @param  list<PurchaseTierDto>  $ticketTiers
     */
    public function __construct(
        public array $plans,
        public array $ticketTiers,
        public int $spotUnitAmountJpy,
        public int $signupGrantTickets,
        public int $signupGrantExpiryDays,
        public int $analysisTicketCost,
        public int $renderTicketCost,
        public bool $isAuthenticated,
        public string $contactUrl,
        public bool $contactIsExternal,
    ) {}

    /**
     * @return PricingPageShape
     */
    public function toArray(): array
    {
        return [
            'plans' => array_map(
                static fn (PricingPlanDto $plan): array => $plan->toArray(),
                $this->plans,
            ),
            'ticketTiers' => array_map(
                static fn (PurchaseTierDto $tier): array => $tier->toArray(),
                $this->ticketTiers,
            ),
            'spotUnitAmountJpy' => $this->spotUnitAmountJpy,
            'signupGrantTickets' => $this->signupGrantTickets,
            'signupGrantExpiryDays' => $this->signupGrantExpiryDays,
            'analysisTicketCost' => $this->analysisTicketCost,
            'renderTicketCost' => $this->renderTicketCost,
            'isAuthenticated' => $this->isAuthenticated,
            'contactUrl' => $this->contactUrl,
            'contactIsExternal' => $this->contactIsExternal,
        ];
    }
}
