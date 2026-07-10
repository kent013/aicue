<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enums\Billing\PlanPriceKind;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingCheckoutRequest;
use App\Models\Billing\Plan;
use App\Models\User;
use App\Services\Billing\PortalConfigurationSpec;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
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
                    'monthlyTicketGrant' => $plan->monthly_ticket_grant,
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
            'ticketBalance' => $tickets->balance($organization),
            'canManageBilling' => $user->can('manageBilling', $organization),
        ]);
    }

    /** Stripe Checkout を開始し、Checkout URL へリダイレクトする */
    public function checkout(BillingCheckoutRequest $request): SymfonyResponse|RedirectResponse
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

        $checkout = $organization
            ->newSubscription('default', $price->stripe_price_id)
            ->checkout([
                'success_url' => route('billing.index'),
                'cancel_url' => route('billing.index'),
            ]);

        $url = $checkout->asStripeCheckoutSession()->url;
        Assert::string($url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');

        // 外部 URL への遷移は Inertia::location (full page redirect)
        return Inertia::location($url);
    }

    /** Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理) */
    public function portal(Request $request): SymfonyResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
        // subscription_update 無効の spec 準拠 configuration で portal session を作る
        // (未設定なら Dashboard 既定 configuration。PortalConfigurationSpec 参照)
        return Inertia::location($organization->billingPortalUrl(
            route('billing.index'),
            PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
        ));
    }
}
