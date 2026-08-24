<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\DataTransferObjects\Billing\PlanDto;
use App\DataTransferObjects\Onboarding\OnboardingCheckoutDto;
use App\Enums\Billing\SignupFundingChoice;
use App\Enums\Billing\SubscriptionState;
use App\Enums\Inquiry\InquirySource;
use App\Enums\PlanCode;
use App\Http\Concerns\ResolvesRouteOrganization;
use App\Http\Controllers\Controller;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketPricingService;
use App\Services\Marketing\ContactUrl;
use App\Services\Onboarding\IntendedPlanResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
    use ResolvesRouteOrganization;

    public function __construct(
        private readonly BillingAccess $access,
        private readonly PersonalPlanService $personalPlan,
        private readonly TicketPricingService $ticketPricing,
        private readonly ContactUrl $contactUrl,
        private readonly IntendedPlanResolver $intendedPlanResolver,
        private readonly AutoRechargeService $autoRecharge,
    ) {}

    public function show(Request $request, Organization $organization): Response|RedirectResponse
    {
        // IDOR 二重防御 (member 認可を最優先)
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 判定順序は hasActiveAccess → manageBilling。契約済み non-manager が誤って
        // billing-required に飛ばないよう、先に契約状態を判定する。
        // 契約済み (Subscribed / ActiveFreePlan) の入口着地は manageBilling 能力で分岐する:
        // - 保持者: 請求管理が正当な着地なので billing.index (現状維持)。
        // - 非保持メンバー (現場作業者): 自分で操作できない請求画面ではなく業務入口 dashboard へ
        //   (Q-2-01。North Star: 現場作業者を最小摩擦で仕事へ着地させる。soft dead-end にしない)。
        if ($this->access->hasActiveAccess($organization)) {
            return new RedirectResponse(
                Gate::allows('manageBilling', $organization)
                    ? route('billing.index', ['organization' => $organization->slug])
                    : route('dashboard', ['organization' => $organization->slug]),
            );
        }

        // 未契約 + manageBilling 権限なし → billing-required へ
        if (! Gate::allows('manageBilling', $organization)) {
            return new RedirectResponse(route('onboarding.billing-required', ['organization' => $organization->slug]));
        }

        // 支払いが未解決のまま契約が残っている組織は、プラン選択ではなく
        // **支払い方法を更新できる画面** (課金画面 = Customer Portal への導線) へ逃がす。
        // 判定は BillingAccess と同じ述語 1 つだけを見る (可否の再判定はしない)。
        $subscription = $organization->subscription('default');
        if ($subscription instanceof Subscription
            && SubscriptionState::fromSubscription($subscription)->hasUnsettledPayment()) {
            return new RedirectResponse(route('billing.index', ['organization' => $organization->slug]));
        }

        // ?plan= が来ていたら org-scoped に積み (Resolver 規約: 有効→put / 無効→forget)、
        // canonical URL へ 303 する (再読込・共有時に query が残らない)。
        // 不在なら session を破壊しない (= リロード耐性のため後段で peek する)。
        if ($request->has('plan')) {
            $this->intendedPlanResolver->rememberForOrganizationFromQuery($request, $organization);

            return new RedirectResponse(route('onboarding.checkout', ['organization' => $organization->slug]), 303);
        }

        $dto = new OnboardingCheckoutDto(
            plans: $this->selectablePlans(),
            recommendedPlanCode: PlanCode::Standard->value,
            defaultPlanCode: PlanCode::Starter->value,
            contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
            personalEligibility: $this->personalPlan->eligibility($organization, $user),
            signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
            // peek = 残す (リロード耐性)。Enterprise / 未知値は正規化で null に倒れる。
            intendedPlanCode: $this->intendedPlanResolver->peekForOrganization($organization)?->value,
            // P8a (D29(i)): 事前同意の提示条件。画面表示値と recordPreConsent の記録値は
            // consentTermsFor() の単一計算源から出る (表示と記録の一致をテストで固定)。
            consentTerms: $this->autoRecharge->consentTermsFor(),
            // UI に出す資金選択は 2 択 (auto_recharge 既定 / later)。
            // `tickets` は UI から出さない (enum・validation では受理継続)。
            fundingChoices: [
                SignupFundingChoice::AutoRecharge->value,
                SignupFundingChoice::Later->value,
            ],
            // P9: 有償プラン契約 POST の冪等 token (render 単位の ULID)。
            subscriptionAttemptToken: (string) Str::ulid(),
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
