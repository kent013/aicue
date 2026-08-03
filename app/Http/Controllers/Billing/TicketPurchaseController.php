<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\DataTransferObjects\Billing\PurchaseTicketsPageDto;
use App\Enums\Billing\PurchaseFormState;
use App\Exceptions\Billing\CheckoutInProgressException;
use App\Exceptions\Billing\StaleCheckoutAttemptException;
use App\Exceptions\Billing\TicketVolumeTierUnavailableException;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\TicketCheckoutRequest;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Billing\TicketVolumePrice;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
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

    /**
     * 購入画面。
     *
     * P8b (tc-5): attempt_token は毎 render ULID ではなく、**自分が開始した復帰可能な購入**が
     * あればその session の token を再利用する (ブラウザバック / bfcache で既存 replay 冪等が
     * 効き、二重課金にならない)。`?fresh=1` は明示的に新規購入 (別 token) へ倒す。
     */
    public function show(
        Request $request,
        TicketPricingService $pricing,
        TicketLedgerService $tickets,
        TicketCheckoutService $checkout,
        AutoRechargeService $autoRecharge,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // Stripe success_url からの帰還 (表示専用)。session_id を current org の自 DB 行と
        // 照合できた時のみバナー表示 (org 切替中の誤表示・query 偽装を fail-closed で防ぐ)
        $sessionId = $request->query('session_id');
        $purchased = $request->boolean('purchased')
            && $checkout->confirmsPurchaseReturn($organization, is_string($sessionId) ? $sessionId : null);

        $canManage = $user->can('manageBilling', $organization);

        // manageBilling を持たない閲覧者には resume / completed を出さない
        // (resumeUrl は外部 Stripe Checkout 直リンクで purchase gate を迂回しうる)。
        // 決済成功着地 ($purchased = 自 org の session_id 照合済み) では resume / completed へ
        // 写像しない: webhook 未達の一瞬は当該 session がまだ live pending のため、成功バナーと
        // 「決済を続ける」(支払い済み Checkout への直リンク) が同時に出て誤誘導になる
        // (着地 feedback の統合は P9 所管。それまでは成功バナーを優先する fail-safe)。
        $resumable = ($canManage && ! $purchased && ! $request->boolean('fresh'))
            ? $checkout->resolveResumablePurchase(
                $organization,
                $user->id,
                config()->integer('billing.purchase_resume_window_minutes'),
            )
            : null;

        [$formState, $attemptToken, $boundCount, $resumeUrl] = match (true) {
            // resolveResumablePurchase は live pending のみを返す (T088 で完了窓を撤去)。
            $resumable instanceof TicketCheckoutSession => [
                PurchaseFormState::Resume,
                $resumable->attempt_token,
                $resumable->ticket_count,
                $resumable->checkout_url,
            ],
            default => [PurchaseFormState::Normal, (string) Str::ulid(), null, null],
        };

        $dto = new PurchaseTicketsPageDto(
            tiers: $pricing->volumeTiersForDisplay(),
            minCount: TicketVolumePrice::PURCHASE_MIN_COUNT,
            maxCount: TicketVolumePrice::PURCHASE_MAX_COUNT,
            defaultCount: self::DEFAULT_COUNT,
            balance: $tickets->balance($organization),
            canManage: $canManage,
            ticketAttemptToken: $attemptToken,
            purchased: $purchased,
            formState: $formState,
            boundCount: $boundCount,
            resumeUrl: $resumeUrl,
            newPurchaseUrl: route('billing.tickets.show', ['fresh' => 1]),
            // P8a: 有効なら「自動購入が設定済み」であることを購入導線でも示せるようにする
            // (軽量な enabled 判定のみ。カタログ解決コストは払わない)。
            autoRechargeEnabled: $autoRecharge->isEnabledFor($organization),
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
