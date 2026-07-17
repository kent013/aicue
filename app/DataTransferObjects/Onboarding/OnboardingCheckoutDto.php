<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Onboarding;

use App\DataTransferObjects\Billing\PersonalPlanEligibilityDto;
use App\DataTransferObjects\Billing\PlanDto;

/**
 * 登録直後の Plan 選択 + Personal (free) 自己申告画面の props。
 *
 * recommendedPlanCode / defaultPlanCode は**コード値**であり `plans` への包含を保証しない
 * (フロントは該当 code があるときのみ preselect し、無ければ先頭 plan を選ぶ)。
 * personalEligibility の表示文言はサーバー側 enum で確定する (frontend に文言を散らさない)。
 *
 * @phpstan-import-type PlanDtoShape from PlanDto
 * @phpstan-import-type PersonalPlanEligibilityShape from PersonalPlanEligibilityDto
 *
 * @phpstan-type OnboardingCheckoutShape array{
 *   plans: list<PlanDtoShape>,
 *   recommendedPlanCode: string,
 *   defaultPlanCode: string,
 *   contactUrl: string,
 *   personalEligibility: PersonalPlanEligibilityShape|null,
 *   signupGrantTickets: int
 * }
 */
final readonly class OnboardingCheckoutDto
{
    /**
     * @param  list<PlanDto>  $plans  is_active=true ∧ Checkout 対象 code のみ。sort_order 昇順
     * @param  PersonalPlanEligibilityDto|null  $personalEligibility  Personal (free) の選択可否 + 不可理由
     * @param  int  $signupGrantTickets  無料開始 callout 用 (初回無償チケット枚数)
     */
    public function __construct(
        public array $plans,
        public string $recommendedPlanCode,
        public string $defaultPlanCode,
        public string $contactUrl,
        public ?PersonalPlanEligibilityDto $personalEligibility = null,
        public int $signupGrantTickets = 10,
    ) {}

    /**
     * @return OnboardingCheckoutShape
     */
    public function toArray(): array
    {
        return [
            'plans' => array_map(
                static fn (PlanDto $p): array => $p->toArray(),
                $this->plans,
            ),
            'recommendedPlanCode' => $this->recommendedPlanCode,
            'defaultPlanCode' => $this->defaultPlanCode,
            'contactUrl' => $this->contactUrl,
            'personalEligibility' => $this->personalEligibility?->toArray(),
            'signupGrantTickets' => $this->signupGrantTickets,
        ];
    }
}
