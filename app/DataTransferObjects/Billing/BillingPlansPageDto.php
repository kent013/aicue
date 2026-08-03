<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\DataTransferObjects\Marketing\PricingPlanDto;
use App\Enums\Billing\OnboardingBillingState;

/**
 * プラン比較ページ (/billing/plans) の Inertia page prop。
 *
 * プラン台帳 → DTO の mapper は公開料金表と共有する (PricingService::listPublicPlans)。
 * currentPlanCode は **表示専用** の解決結果であり gate 判定には使わない
 * (判定は BillingAccess::state() 一本)。
 *
 * TS 側は resources/js/types/billing.ts の BillingPlansPageProps と exact 対で保守する。
 *
 * @phpstan-import-type PricingPlanShape from PricingPlanDto
 *
 * @phpstan-type BillingPlansPageShape array{
 *   plans: list<PricingPlanShape>,
 *   currentPlanCode: string|null,
 *   billingState: string,
 *   canManage: bool
 * }
 */
final readonly class BillingPlansPageDto
{
    /**
     * @param  list<PricingPlanDto>  $plans
     */
    public function __construct(
        public array $plans,
        public ?string $currentPlanCode,
        public OnboardingBillingState $billingState,
        public bool $canManage,
    ) {}

    /**
     * @return BillingPlansPageShape
     */
    public function toArray(): array
    {
        return [
            'plans' => array_map(
                static fn (PricingPlanDto $plan): array => $plan->toArray(),
                $this->plans,
            ),
            'currentPlanCode' => $this->currentPlanCode,
            'billingState' => $this->billingState->value,
            'canManage' => $this->canManage,
        ];
    }
}
