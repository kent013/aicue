<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Exceptions\Billing\PersonalPlanNotEligibleException;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\ActivatePersonalRequest;
use App\Models\User;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketPricingService;
use App\Services\Onboarding\OnboardingReturnResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * Personal (free) プランの有効化エンドポイント (current org スコープ)。
 *
 * Stripe checkout を通らず、自己申告チェック + business invariant (PersonalPlanService) で
 * 即時に利用開始する。付与ロジックは PersonalPlanService::activate() が単一の真実源で、
 * 本 Controller は呼ぶだけ (二重付与源を作らない)。
 */
final class ActivatePersonalController extends Controller
{
    use ResolvesCurrentOrganization;

    public function __construct(
        private readonly PersonalPlanService $personalPlan,
        private readonly TicketPricingService $ticketPricing,
        private readonly OnboardingReturnResolver $returnResolver,
    ) {}

    public function __invoke(ActivatePersonalRequest $request): RedirectResponse
    {
        $organization = $this->resolveMemberCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        try {
            $result = $this->personalPlan->activate($organization, $user);
        } catch (PersonalPlanNotEligibleException $e) {
            // 条件不成立は 500 にせず 422 (文言はサーバー側 enum が確定)
            throw ValidationException::withMessages(['plan_code' => $e->userMessage()]);
        }

        $message = $result->granted
            ? sprintf(
                'パーソナルプラン（無料）を開始しました。無料チケット %d 枚をお付けしました。',
                $this->ticketPricing->signupGrantTickets(),
            )
            : 'パーソナルプラン（無料）を開始しました。';

        // 課金ゲートで保存された「やりたかった destination」があればそこへ復帰する。
        // 値は org-scoped session に保持した same-origin 内部 path のみ (peek で再正規化)。
        // `redirect()->intended()` は使わない (禁止事項 #7。ログイン直後フロー専用)。
        $continue = $this->returnResolver->peekForOrganization($organization);
        $this->returnResolver->forgetForOrganization($organization);

        return redirect()->to($continue ?? route('dashboard'))->with('success', $message);
    }
}
