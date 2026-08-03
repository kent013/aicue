<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\Enums\Billing\SignupFundingChoice;
use App\Exceptions\Billing\CheckoutInProgressException;
use App\Exceptions\Billing\PersonalPlanNotEligibleException;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\ActivatePersonalRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketPricingService;
use App\Services\Onboarding\OnboardingReturnResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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
        private readonly AutoRechargeService $autoRecharge,
    ) {}

    public function __invoke(ActivatePersonalRequest $request): RedirectResponse|SymfonyResponse
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

        $fundingRaw = $request->validated('funding_choice');
        $funding = is_string($fundingRaw) ? SignupFundingChoice::from($fundingRaw) : null;

        // P8a (D29(i)): 「オートリチャージを設定する」を明示選択した場合は、activate 完了済みの
        // まま カード登録 (mode=setup Checkout) へ直行する。cancel しても請求ページ着地で
        // カード登録 CTA が残る (詰まない)。continuation は上で消費済み。
        if ($funding === SignupFundingChoice::AutoRecharge) {
            // 事前同意の記録 (enabled=false)。version は FormRequest (Rule::in) で activate 前に
            // 検証済み — Service 内の再検証はリクエスト処理中の version 改定 (TOCTOU) に対する
            // defense-in-depth。カード登録完了 webhook が自動有効化する。
            $consentVersion = $request->validated('consent_version');
            Assert::stringNotEmpty($consentVersion);

            try {
                $this->autoRecharge->recordPreConsent($organization, $user, new AutoRechargeConsentDto($consentVersion));

                $result = $this->autoRecharge->startSetupCheckout(
                    $organization,
                    $user,
                    route('billing.index').'?setup_session_id={CHECKOUT_SESSION_ID}',
                    route('billing.index'),
                    // session 保持の安定 token で二重 submit を冪等化
                    // (SetupPaymentMethod 台帳を増殖させない)。
                    $this->setupAttemptToken($request, $organization),
                );
            } catch (CheckoutInProgressException $e) {
                return back()->with('error', $e->getMessage());
            }

            if ($result['url'] !== null) {
                // flash は startSetupCheckout 成功後にのみ積む (Stripe 例外時に flash だけ残さない)。
                $request->session()->flash(
                    'success',
                    $message.' カード登録が完了すると、オートリチャージが自動で有効になります。',
                );

                return Inertia::location($result['url']);
            }

            // url=null (進行中 session の replay) は請求ページへ fallback (カード登録 CTA が残る)。
            return redirect()->route('billing.index')->with('success', $message);
        }

        // 「チケットを買う」(UI 非提示・永続互換値) は購入ページへ直行する。
        if ($funding === SignupFundingChoice::Tickets) {
            return redirect()->route('billing.tickets.show')->with('success', $message);
        }

        return redirect()->to($continue ?? route('dashboard'))->with('success', $message);
    }

    /**
     * カード登録 (mode=setup) の attempt_token を activation フロー単位で安定化する。
     *
     * render ごとに発行すると二重 submit で SetupPaymentMethod 台帳が増殖するため、
     * org スコープの session キーに ULID を保持して再利用する。
     */
    private function setupAttemptToken(ActivatePersonalRequest $request, Organization $organization): string
    {
        $key = "auto_recharge_setup_token:{$organization->id}";
        $token = $request->session()->get($key);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = strtolower((string) Str::ulid());
        $request->session()->put($key, $token);

        return $token;
    }
}
