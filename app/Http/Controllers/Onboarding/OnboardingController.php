<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\DataTransferObjects\Billing\PlanDto;
use App\DataTransferObjects\Onboarding\OnboardingCheckoutDto;
use App\Enums\Inquiry\InquirySource;
use App\Enums\PlanCode;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketPricingService;
use App\Services\Marketing\ContactUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 登録直後 → Plan 選択 + Personal (free) 自己申告画面 (current org スコープ)。
 *
 * `Onboarding` 配下に置く理由: 既存 `Organizations\OrganizationOnboardingController` は
 * MCP/CLI 導入ガイド用の別責務。命名衝突を避けるため階層を分けた。
 */
final class OnboardingController extends Controller
{
    use ResolvesCurrentOrganization;

    public function __construct(
        private readonly BillingAccess $access,
        private readonly PersonalPlanService $personalPlan,
        private readonly TicketPricingService $ticketPricing,
        private readonly ContactUrl $contactUrl,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $organization = $this->resolveMemberCurrentOrganization($request);
        // IDOR 二重防御 (member 認可を最優先)
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 判定順序は hasActiveAccess → manageBilling。契約済み non-manager が誤って
        // billing-required に飛ばないよう、先に契約状態を判定する。
        if ($this->access->hasActiveAccess($organization)) {
            return new RedirectResponse(route('billing.index'));
        }

        // 未契約 + manageBilling 権限なし → billing-required へ
        if (! Gate::allows('manageBilling', $organization)) {
            return new RedirectResponse(route('onboarding.billing-required'));
        }

        $dto = new OnboardingCheckoutDto(
            plans: $this->selectablePlans(),
            recommendedPlanCode: PlanCode::Standard->value,
            defaultPlanCode: PlanCode::Starter->value,
            contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
            personalEligibility: $this->personalPlan->eligibility($organization, $user),
            signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
        );

        return Inertia::render('Onboarding/Checkout', [
            'organization' => $this->organizationProps($organization),
            'pageData' => $dto->toArray(),
        ]);
    }

    /**
     * 選択可能なプラン。公開規則は `is_active=true` の単一規則 (PricingService と同一)。
     * Enterprise はお問い合わせ営業のため除外する (Checkout を通らない)。
     *
     * @return list<PlanDto>
     */
    private function selectablePlans(): array
    {
        // list<PlanDto> は array_values で確定する (PricingService::listPublicPlans と同作法)
        return array_values(Plan::query()
            ->where('is_active', true)
            ->whereIn('code', [
                PlanCode::Personal->value,
                PlanCode::Starter->value,
                PlanCode::Standard->value,
                PlanCode::Business->value,
            ])
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (Plan $p): PlanDto => PlanDto::fromModel($p))
            ->all());
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function organizationProps(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
        ];
    }
}
