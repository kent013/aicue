<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Onboarding;

use App\DataTransferObjects\Billing\AutoRechargeConsentTermsDto;
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
 * @phpstan-import-type AutoRechargeConsentTermsShape from AutoRechargeConsentTermsDto
 *
 * @phpstan-type OnboardingCheckoutShape array{
 *   plans: list<PlanDtoShape>,
 *   recommendedPlanCode: string,
 *   defaultPlanCode: string,
 *   contactUrl: string,
 *   personalEligibility: PersonalPlanEligibilityShape|null,
 *   signupGrantTickets: int,
 *   intendedPlanCode: string|null,
 *   consentTerms: AutoRechargeConsentTermsShape,
 *   fundingChoices: list<string>
 * }
 */
final readonly class OnboardingCheckoutDto
{
    /**
     * @param  list<PlanDto>  $plans  is_active=true ∧ Checkout 対象 code のみ。sort_order 昇順
     * @param  PersonalPlanEligibilityDto|null  $personalEligibility  Personal (free) の選択可否 + 不可理由
     * @param  int  $signupGrantTickets  無料開始 callout 用 (初回無償チケット枚数)
     * @param  string|null  $intendedPlanCode  料金表 `?plan=` 由来の選択意図 (allowlist 照合済。
     *                                         `plans` への包含は保証しない = フロントは該当 code が
     *                                         あるときだけ preselect する)
     */
    public function __construct(
        public array $plans,
        public string $recommendedPlanCode,
        public string $defaultPlanCode,
        public string $contactUrl,
        public ?PersonalPlanEligibilityDto $personalEligibility = null,
        public int $signupGrantTickets = 10,
        public ?string $intendedPlanCode = null,
        /**
         * P8a (D29(i)): オートリチャージ事前同意の提示条件。表示値と記録値の単一計算源
         * (AutoRechargeService::consentTermsFor)。
         */
        public ?AutoRechargeConsentTermsDto $consentTerms = null,
        /**
         * 画面に出す資金選択の並び (enum 値)。`tickets` は UI から出さない
         * (validation では引き続き受理する)。
         *
         * @var list<string>
         */
        public array $fundingChoices = [],
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
            'intendedPlanCode' => $this->intendedPlanCode,
            'consentTerms' => ($this->consentTerms ?? new AutoRechargeConsentTermsDto(0, 0, 0, 0, ''))->toArray(),
            'fundingChoices' => $this->fundingChoices,
        ];
    }
}
