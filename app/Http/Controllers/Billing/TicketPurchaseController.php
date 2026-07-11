<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\DataTransferObjects\Billing\PurchaseTicketsPageDto;
use App\Exceptions\Billing\CheckoutInProgressException;
use App\Exceptions\Billing\StaleCheckoutAttemptException;
use App\Exceptions\Billing\TicketVolumeTierUnavailableException;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\TicketCheckoutRequest;
use App\Models\Billing\TicketVolumePrice;
use App\Models\User;
use App\Services\Billing\TicketCheckoutService;
use App\Services\Billing\TicketLedgerService;
use App\Services\Billing\TicketPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Webmozart\Assert\Assert;

/**
 * チケットスポット購入 (current org スコープ)。
 *
 * - 閲覧は組織メンバー全員 (残高・料金の透明性)。購入 (Checkout 開始) は
 *   manageBilling (owner / admin) のみ
 * - 課金ゲート (require-active-subscription) の対象外 = 未契約 / free プラン組織でも購入可能
 */
class TicketPurchaseController extends Controller
{
    use ResolvesCurrentOrganization;

    /** 購入画面の枚数入力の初期値 */
    private const int DEFAULT_COUNT = 10;

    /** 購入画面 (attempt_token は render ごとに ULID 発行) */
    public function show(Request $request, TicketPricingService $pricing, TicketLedgerService $tickets): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $dto = new PurchaseTicketsPageDto(
            tiers: $pricing->volumeTiersForDisplay(),
            minCount: TicketVolumePrice::PURCHASE_MIN_COUNT,
            maxCount: TicketVolumePrice::PURCHASE_MAX_COUNT,
            defaultCount: self::DEFAULT_COUNT,
            balance: $tickets->balance($organization),
            canManage: $user->can('manageBilling', $organization),
            attemptToken: (string) Str::ulid(),
            purchased: $request->boolean('purchased'), // Stripe success_url からの帰還 (表示専用)
        );

        return Inertia::render('Billing/PurchaseTickets', ['page' => $dto->toArray()]);
    }

    /** Checkout 開始 (manageBilling のみ) */
    public function checkout(TicketCheckoutRequest $request, TicketCheckoutService $service): SymfonyResponse|RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $count = $request->validated('count');
        $attemptToken = $request->validated('attempt_token');
        Assert::integerish($count);
        Assert::string($attemptToken);

        try {
            $redirect = $service->startCheckout($organization, $user, (int) $count, $attemptToken);
        } catch (CheckoutInProgressException|StaleCheckoutAttemptException|TicketVolumeTierUnavailableException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($redirect->url === null) {
            return redirect()->route('billing.tickets.show')
                ->with('info', 'この購入は既に受付済みです。残高への反映をお待ちください。');
        }

        // 外部 Stripe への full page redirect
        return Inertia::location($redirect->url);
    }
}
