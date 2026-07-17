<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\DataTransferObjects\Billing\SubscriptionEntitlementDto;
use App\Enums\Billing\EntitlementDeniedReason;
use App\Enums\Billing\PlanPriceKind;
use App\Enums\Billing\ScheduleSetupStatus;
use App\Enums\Billing\SubscriptionState;
use App\Enums\PlanCode;
use App\Exceptions\Billing\StripePriceNotSyncedException;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * Subscription (契約) の状態管理サービス。
 *
 * Stripe への I/O は Gateway 経由のみで、本クラスは entitlement の導出・webhook 受信時の
 * 状態同期・checkout の前処理に責務を絞る。
 */
class SubscriptionService
{
    /** organizations.plan_code を同期する subscription status (それ以外では既存値を維持する) */
    private const array ACTIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing'];

    public function __construct(
        private readonly StripeGatewayInterface $gateway,
    ) {}

    /**
     * subscription の利用可否 (entitlement) を確定する **唯一の経路**。
     *
     * `SubscriptionState::fromSubscription`/`grantsAccess` を直接参照して可否を決めてはならない。
     * 本メソッドが state + PM 有無 + trial_ends_at + Stripe status snapshot を合成して最終確定する。
     *
     *   entitled = state.grantsAccess()
     *              AND NOT (trial_ends_at <= now AND !has_payment_method)   // trial 終了 & カード無し
     *              AND status != paused                                     // Stripe 確定の read-only
     *
     * - Paused: grantsAccess=false で否定 (reason=Paused)。
     * - trial 終了 & PM 無し: webhook 前 (Stripe がまだ paused 化していない) でも先回りで否定する
     *   (reason=TrialEndedWithoutPaymentMethod)。
     * - PastDue (PM 有): grantsAccess=true かつ trial 条件に該当しないため entitled=true (請求失敗中も利用継続)。
     * - PM 無し past_due (= trial 後カード無し dunning): trial_ends_at<=now & !has_payment_method で否定。
     */
    public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto
    {
        $state = SubscriptionState::fromSubscription($sub);

        if (! $state->grantsAccess()) {
            $reason = $state === SubscriptionState::Paused
                ? EntitlementDeniedReason::Paused
                : EntitlementDeniedReason::NoActiveSubscription;

            return SubscriptionEntitlementDto::denied($state, $reason);
        }

        // trial 終了後カード未登録 → 利用不可 (webhook の paused 化前でも先回り遮断)。
        $now = CarbonImmutable::now();
        $trialEnded = $sub->trial_ends_at !== null
            && CarbonImmutable::instance($sub->trial_ends_at)->lessThanOrEqualTo($now);
        if ($trialEnded && ! $sub->has_payment_method) {
            return SubscriptionEntitlementDto::denied(
                $state,
                EntitlementDeniedReason::TrialEndedWithoutPaymentMethod,
            );
        }

        // status=paused は grantsAccess で既に弾かれているが、防御的に二重で確認する。
        if ($sub->stripe_status === 'paused') {
            return SubscriptionEntitlementDto::denied($state, EntitlementDeniedReason::Paused);
        }

        return SubscriptionEntitlementDto::granted($state);
    }

    /**
     * Webhook (customer.subscription.created/updated/deleted) 受信時、Stripe サブスクの
     * 最新スナップショットをローカル状態へ反映する **唯一の書込経路**。
     *
     * 列の所在差の吸収 (aigenba は subscriptions.plan_code に書くが、本アプリの権威は
     * organizations.plan_code):
     * - (a) base Price から plan が解決でき **かつ** status が active/trialing のときだけ
     *   `organizations.plan_code` を同期する (未知 Price は受理のみ)。
     * - (b) `subscriptions` 行が存在すれば lockForUpdate の上で Stripe 由来の列を更新する。
     *   **行の作成は行わない** (作成の権威は Cashier の WebhookController。WebhookReceived は
     *   Cashier のハンドラより先に発火するため created 時点では行が無いことがあり、ここで
     *   先に作ると Cashier 側の subscription_items 生成が永久に skip される)。
     * - (c) `$terminated` (customer.subscription.deleted) では `organizations.plan_code` を
     *   null に戻し、schedule ライフサイクル列を同一トランザクションで明示クリアする
     *   (「移行」ではなく「終了」。status だけ更新・schedule 残存の一時不整合を防ぐ)。
     *
     * seat drift / schedule out-of-band drift / period 巻き戻し guard は対象列
     * (additional_seats / pending_plan_code / current_period_start) が無いため移植しない。
     *
     * @param  bool  $terminated  終了系 (deleted) のとき true。
     */
    public function applySubscriptionSnapshot(
        Organization $org,
        SubscriptionSnapshot $snap,
        bool $terminated = false,
    ): void {
        DB::transaction(function () use ($org, $snap, $terminated): void {
            $sub = Subscription::query()
                ->where('stripe_id', $snap->stripeId)
                ->lockForUpdate()
                ->first();

            if ($sub instanceof Subscription) {
                $attrs = [
                    'stripe_status' => $snap->status,
                    'stripe_price' => $snap->basePriceId,
                    'quantity' => $snap->baseQuantity,
                    'trial_ends_at' => $snap->trialEndsAt,
                    'ends_at' => $snap->endsAt,
                ];

                // period 欠落 payload では既存の current_period_end を維持する (renewal reminder の
                // 真実源を null で塗り潰さない = 現行 syncSubscriptionPeriod の早期 return と同値)。
                if ($snap->currentPeriodEnd !== null) {
                    $attrs['current_period_end'] = $snap->currentPeriodEnd;
                }

                if ($terminated) {
                    $attrs['stripe_schedule_id'] = null;
                    $attrs['schedule_setup_status'] = ScheduleSetupStatus::None;
                }

                $sub->forceFill($attrs)->save();
            }

            if ($terminated) {
                // plan_code は状態キー: webhook 同期でのみ明示代入する
                $org->plan_code = null;
                $org->save();

                return;
            }

            $planCode = $this->resolvePlanCodeFromPriceId($snap->basePriceId);
            if ($planCode === null || ! in_array($snap->status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
                return; // 未知 Price / 非 active 系は受理のみ (既存 plan_code を維持)
            }

            $org->plan_code = $planCode->value;
            $org->save();
        });
    }

    /**
     * has_payment_method を subscription に記録する **独立 monotonic writer**。
     *
     * `applySubscriptionSnapshot` の中に置かない理由: 早期 return 経路 (行不在等) と無関係に
     * 「決済手段の有無」だけを独立した契約として書くため。
     *
     * - has_payment_method: monotonic (true から false に戻さない)。Stripe の payload は
     *   default_payment_method を expand しない周期があり、false 側を信じると trial 終了後の
     *   遮断判定 (deriveEntitlement) が誤発火するため。
     * - 行不在 (Cashier の WebhookController が行を作る前の customer.subscription.created 等) は
     *   早期 return で no-op。最初の権威 PM 書込は最初の customer.subscription.updated に載る。
     */
    public function recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentMethod): void
    {
        DB::transaction(function () use ($sub, $hasPaymentMethod): void {
            $fresh = Subscription::query()->lockForUpdate()->find($sub->id);
            if (! $fresh instanceof Subscription) {
                return;
            }

            // PM 有無 (monotonic: 一度 true になったら下げない)。
            if ($hasPaymentMethod && ! $fresh->has_payment_method) {
                $fresh->forceFill(['has_payment_method' => true])->save();
            }
        });
    }

    /**
     * Stripe Checkout (サブスク契約) を開始し、遷移先 (hosted Checkout URL) を返す。
     *
     * checkout session の冪等状態機械 (attempt token / billing_checkout_sessions) は
     * 本フェーズのスコープ外 (後続フェーズで本メソッドに配線する)。
     *
     * @throws StripePriceNotSyncedException production runtime で未 sync の Price のとき
     * @throws ValidationException Stripe 決済対象外のプランのとき (422)
     * @throws \InvalidArgumentException 既に有効なサブスクリプションがあるとき
     */
    public function startCheckout(
        Organization $org,
        PlanPrice $basePrice,
        string $successUrl,
        string $cancelUrl,
    ): ExternalBillingRedirect {
        // production runtime guard
        $this->assertPriceSynced($basePrice);

        $plan = $basePrice->plan;
        Assert::isInstanceOf($plan, Plan::class);
        $this->assertStripeBillablePlan($plan);

        $existing = $org->subscription('default');
        Assert::true(
            ! $existing instanceof Subscription || ! $existing->valid(),
            '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
        );

        return $this->gateway->createSubscriptionCheckout(
            $org,
            $basePrice->stripe_price_id,
            $successUrl,
            $cancelUrl,
        );
    }

    /** Stripe Customer Portal セッション (支払い方法・解約の自己管理) の遷移先を返す。 */
    public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect
    {
        return $this->gateway->createPortalSession($org, $returnUrl);
    }

    /**
     * Stripe Checkout の対象プランかを service 層で明示拒否する (validation 迂回対策)。
     * Personal (free) / Enterprise / 未知 code は fail-closed で 422。
     */
    private function assertStripeBillablePlan(Plan $plan): void
    {
        $planCode = PlanCode::tryFrom($plan->code);
        if ($planCode === null || ! $planCode->requiresStripeCheckout()) {
            throw ValidationException::withMessages([
                'plan_code' => 'このプランは Stripe 決済の対象外です。',
            ]);
        }
    }

    /**
     * production runtime で未 sync の test mode Price を checkout に使う事故を防ぐ DB レベル guard。
     */
    private function assertPriceSynced(PlanPrice $price): void
    {
        if (! app()->environment('production')) {
            return;
        }
        if (! $price->livemode || $price->synced_at === null) {
            $lookupKey = $price->lookup_key ?? "plan_id={$price->plan_id}:kind={$price->kind}";
            throw new StripePriceNotSyncedException($lookupKey);
        }
    }

    /** base Price ID からプラン (PlanCode) を逆引きする。未知 Price は null。 */
    private function resolvePlanCodeFromPriceId(?string $priceId): ?PlanCode
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        $row = PlanPrice::query()
            ->where('stripe_price_id', $priceId)
            ->where('kind', PlanPriceKind::Base->value)
            ->first();

        if (! $row instanceof PlanPrice) {
            return null;
        }

        $plan = $row->plan;
        if (! $plan instanceof Plan) {
            return null;
        }

        return PlanCode::tryFrom($plan->code);
    }
}
