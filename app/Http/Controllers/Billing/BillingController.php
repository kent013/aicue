<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enums\Billing\PlanPriceKind;
use App\Exceptions\Billing\StripePriceNotSyncedException;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingCheckoutRequest;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\TicketLedgerService;
use App\Services\Onboarding\IntendedPlanResolver;
use App\Services\Onboarding\OnboardingReturnResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Webmozart\Assert\Assert;

/**
 * 課金画面 (current org スコープ)。
 *
 * - プラン変更は Stripe Checkout / Customer Portal 経由のみ (アプリは plan_code を
 *   直接書かない。organizations.plan_code は webhook で同期される)
 * - 閲覧は組織メンバー全員、Checkout / Portal は manageBilling (owner / admin) のみ
 */
class BillingController extends Controller
{
    use ResolvesCurrentOrganization;

    public function __construct(
        private readonly BillingAccess $access,
        private readonly IntendedPlanResolver $intendedPlanResolver,
        private readonly OnboardingReturnResolver $returnResolver,
    ) {}

    /** 課金ページ (現在プラン / チケット残高 / プラン一覧) */
    public function index(Request $request, TicketLedgerService $tickets): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $plans = Plan::query()->orderBy('sort_order')->get()
            ->map(function (Plan $plan): array {
                $price = $plan->currentPrice(PlanPriceKind::Base);

                return [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'price' => $price === null ? null : [
                        'unitAmount' => $price->amount,
                        'currency' => $price->currency,
                    ],
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Billing/Index', [
            'plans' => $plans,
            'currentPlanCode' => $organization->plan_code,
            'ticketBalance' => $tickets->balance($organization)->totalAvailable(),
            'canManageBilling' => $user->can('manageBilling', $organization),
            'continueUrl' => $this->resolveOnboardingContinue($organization),
        ]);
    }

    /**
     * Stripe Checkout を開始し、Checkout URL へリダイレクトする
     * (戻り型に RedirectResponse を含むのは price 不在 / 開始不可時の back() 分岐のため)
     */
    public function checkout(BillingCheckoutRequest $request, SubscriptionService $subscriptions): SymfonyResponse|RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        $planCode = $request->validated('plan_code');
        Assert::string($planCode);
        $plan = Plan::query()->where('code', $planCode)->firstOrFail();

        $price = $plan->currentPrice(PlanPriceKind::Base);
        if ($price === null) {
            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
        }

        try {
            $redirect = $subscriptions->startCheckout(
                $organization,
                $price,
                route('billing.index'),
                route('billing.index'),
            );
        } catch (StripePriceNotSyncedException) {
            // production の sync 漏れ。500 にせず現行と同一文言で差し戻す
            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
        } catch (InvalidArgumentException $e) {
            // 既に有効なサブスクリプションがある (service 層の fail-closed ガード)
            return back()->with('error', $e->getMessage());
        }

        // 契約開始が成立したのでプラン意図を消費する (checkout URL 取得後・遷移前)。
        // price 不在 / 開始不可の back() 経路では forget しない = 意図を維持して再試行できる。
        $this->intendedPlanResolver->forgetForOrganization($organization);

        // 外部 URL への遷移は Inertia::location (full page redirect)
        return Inertia::location($redirect->url);
    }

    /**
     * 契約成立着地でのみ「元の画面に戻る」導線を出す (1 回限り = リロードで CTA が残らない)。
     *
     * 判定は BillingAccess::state()->grantsAccess() 一本 (subscription 直参照も
     * `?session_id` 依存もしない)。未契約 org では peek すらせず return_to を維持する
     * (契約前に消費すると本来の復帰先が失われる)。
     */
    private function resolveOnboardingContinue(Organization $organization): ?string
    {
        if (! $this->access->state($organization)->grantsAccess()) {
            return null;
        }

        $continue = $this->returnResolver->peekForOrganization($organization);
        if ($continue === null) {
            return null;
        }

        $this->returnResolver->forgetForOrganization($organization);

        return $continue;
    }

    /** Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理) */
    public function portal(Request $request, SubscriptionService $subscriptions): SymfonyResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        return Inertia::location($subscriptions->createPortalSession($organization, route('billing.index'))->url);
    }
}
