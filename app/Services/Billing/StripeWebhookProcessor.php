<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\BillingNotificationType;
use App\Enums\Billing\HandledStripeWebhookEvent;
use App\Enums\Billing\WebhookEventStatus;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Notifications\Billing\PaymentFailedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;
use RuntimeException;
use Throwable;

/**
 * Stripe webhook の冪等マシン (Cashier の WebhookReceived listener)。
 *
 * 1. stripe_webhook_events に event_id UNIQUE で冪等記録 (二重処理 skip)
 * 2. type 別 handler:
 *    - customer.subscription.created/updated: organizations.plan_code と
 *      subscriptions.current_period_end を同期
 *    - customer.subscription.deleted: plan_code を解除
 *    - invoice.paid: プランの monthly_ticket_grant を月次付与 (+ 初回は signup grant)
 *    - invoice.payment_failed: 支払い失敗通知 (BillingNotificationDispatcher 経由の send-once)
 *    - charge.refunded: 買い切りチケットの返金逆仕訳 (clawback)
 * 3. 失敗時は status=failed + failure_reason 記録 + report して再 throw (Cashier 既定どおり
 *    200 を返さず Stripe の再送を促す。failed は再送時に received へ復帰して再処理される)
 * 4. 再送上限: failed→received 復帰のたびに attempts をインクリメントし、
 *    MAX_PROCESSING_ATTEMPTS 到達後は処理せず skip (= 200 terminal-ack) して
 *    恒久失敗イベントの無限 500 ストームを打ち切る (運用は failure_reason で調査する)
 *
 * subscriptions テーブル自体の同期 (updateOrCreate) は Cashier の WebhookController
 * が行うため、ここではアプリ状態 (plan_code / チケット) だけを扱う。
 */
class StripeWebhookProcessor
{
    /**
     * webhook 処理失敗の再送上限。attempts (failed→received 復帰回数) がこれに到達したら
     * terminal とみなし処理せず 200 ack して Stripe の自動再送を打ち切る。
     * claim() が transaction + lockForUpdate で状態遷移を直列化するため
     * "processing 残留 stale" は生じず、復帰 sweep は不要。
     * Stripe の自動再送窓 (~3 日) に対し 8 回で十分。
     */
    public const int MAX_PROCESSING_ATTEMPTS = 8;

    /** plan_code を同期する subscription status (それ以外では既存値を維持する) */
    private const array ACTIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing'];

    /** 月次付与の対象となる invoice billing_reason */
    private const array GRANTING_BILLING_REASONS = ['subscription_create', 'subscription_cycle'];

    public function __construct(
        private readonly TicketLedgerService $tickets,
        private readonly BillingNotificationDispatcher $notifications,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        /** @var array<mixed> $payload */
        $payload = $event->payload;
        $eventId = $this->stringAt($payload, 'id');
        $type = $this->stringAt($payload, 'type');
        if ($eventId === null || $type === null) {
            return; // 形式不正の payload は処理対象外 (署名検証は Cashier middleware 側)
        }

        $record = $this->claim($eventId, $type, $payload);
        if ($record === null) {
            return; // 同一 event_id を処理済み (冪等 skip)
        }

        try {
            $this->process($type, $payload);
        } catch (Throwable $exception) {
            $record->status = WebhookEventStatus::Failed;
            $record->failure_reason = $exception->getMessage();
            $record->save();
            report($exception);

            throw $exception; // 200 を返さず Stripe の再送を促す (failed は再送で再処理)
        }

        $record->status = WebhookEventStatus::Processed;
        $record->failure_reason = null;
        $record->processed_at = CarbonImmutable::now();
        $record->save();
    }

    /**
     * 冪等記録の獲得。処理すべきときだけ record を返す。
     * - 未受信: 新規 received で記録して返す
     * - processed / received (処理中): null (二重処理 skip)
     * - failed: attempts をインクリメントして received に戻して返す (Stripe 再送による再処理)。
     *   ただし attempts が MAX_PROCESSING_ATTEMPTS に到達済みなら null (terminal-ack:
     *   処理せず 200 を返し Stripe の自動再送を打ち切る)
     *
     * @param  array<mixed>  $payload
     */
    private function claim(string $eventId, string $type, array $payload): ?StripeWebhookEvent
    {
        return DB::transaction(function () use ($eventId, $type, $payload): ?StripeWebhookEvent {
            $existing = StripeWebhookEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status !== WebhookEventStatus::Failed) {
                    return null;
                }
                if ($existing->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                    // terminal: 構造化ログで可観測化し、運用側が failure_reason を見て手動対応する
                    Log::warning('stripe webhook: terminal failure, acking to stop Stripe retries', [
                        'event_id' => $eventId,
                        'type' => $type,
                        'attempts' => $existing->attempts,
                    ]);

                    return null;
                }
                $existing->status = WebhookEventStatus::Received;
                $existing->attempts += 1;
                $existing->save();

                return $existing;
            }

            $record = new StripeWebhookEvent;
            // 全カラム明示代入 (クライアント入力は入らない)
            $record->event_id = $eventId;
            $record->type = $type;
            $record->status = WebhookEventStatus::Received;
            $record->payload = $payload;
            $record->save();

            return $record;
        });
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function process(string $type, array $payload): void
    {
        // 処理イベント集合の単一出典は HandledStripeWebhookEvent (購読集合の導出元)。
        // case を足したらここに arm を足す (handled ⊆ subscribed は invariant test が担保)
        match (HandledStripeWebhookEvent::tryFrom($type)) {
            HandledStripeWebhookEvent::SubscriptionCreated,
            HandledStripeWebhookEvent::SubscriptionUpdated => $this->syncSubscriptionState($payload),
            HandledStripeWebhookEvent::SubscriptionDeleted => $this->clearPlanCode($payload),
            HandledStripeWebhookEvent::InvoicePaid => $this->grantMonthlyTickets($payload),
            HandledStripeWebhookEvent::ChargeRefunded => $this->clawbackRefundedTickets($payload),
            HandledStripeWebhookEvent::InvoicePaymentFailed => $this->handleInvoicePaymentFailed($payload),
            // 拡張点: テンプレートでは受理のみ (派生アプリで
            // TicketLedgerService::grantPurchased によるチケット購入付与等を実装する)
            HandledStripeWebhookEvent::CheckoutSessionCompleted => null,
            null => null, // 未対応 type は受理のみ (processed として記録)
        };
    }

    /**
     * customer.subscription.created/updated: plan_code 同期 + 次回更新日時の同期。
     *
     * @param  array<mixed>  $payload
     */
    private function syncSubscriptionState(array $payload): void
    {
        $this->syncPlanCode($payload);
        $this->syncSubscriptionPeriod($payload);
    }

    /**
     * subscription snapshot から organizations.plan_code を同期する。
     * status が active / trialing のときだけ反映 (past_due 等の扱いはアプリ判断で拡張する)。
     *
     * @param  array<mixed>  $payload
     */
    private function syncPlanCode(array $payload): void
    {
        $organization = $this->resolveOrganization($payload);
        if ($organization === null) {
            return;
        }

        $status = $this->stringAt($payload, 'data.object.status');
        if (! in_array($status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
            return;
        }

        $priceId = $this->stringAt($payload, 'data.object.items.data.0.price.id');
        if ($priceId === null) {
            return;
        }

        $plan = $this->planByStripePriceId($priceId);
        if ($plan === null) {
            return; // 未知の Price はアプリのプランに対応しない (受理のみ)
        }

        // plan_code は状態キー: webhook 同期でのみ明示代入する
        $organization->plan_code = $plan->code;
        $organization->save();
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function clearPlanCode(array $payload): void
    {
        $organization = $this->resolveOrganization($payload);
        if ($organization === null) {
            return;
        }

        $organization->plan_code = null;
        $organization->save();
    }

    /**
     * invoice.paid: 契約プランの monthly_ticket_grant を月次付与する。
     * 初回 (billing_reason=subscription_create) はあわせて signup grant を付与する。
     *
     * 冪等性は claim() の event_id UNIQUE に加え、台帳の idempotency_key
     * (monthly:{invoiceId} / signup_grant:{subscriptionId}) が保証する
     * (event_id 違いの同一 invoice 再通知でも二重付与しない)。
     *
     * @param  array<mixed>  $payload
     */
    private function grantMonthlyTickets(array $payload): void
    {
        $organization = $this->resolveOrganization($payload);
        if ($organization === null) {
            return;
        }

        $billingReason = $this->stringAt($payload, 'data.object.billing_reason');
        if (! in_array($billingReason, self::GRANTING_BILLING_REASONS, true)) {
            return; // サブスク以外の請求 (one-time 等) では付与しない
        }

        // 初回 signup grant (「まず触れる」導線)。subscription id が取れない場合は
        // 安定した冪等キーを作れないため fail-closed で付与しない (report で可観測化)
        if ($billingReason === 'subscription_create') {
            $subscriptionId = $this->resolveInvoiceSubscriptionId($payload);
            if ($subscriptionId !== null) {
                $this->tickets->grantSignupGrant($organization, "signup_grant:{$subscriptionId}");
            } else {
                report(new RuntimeException('invoice.paid subscription_create: subscription id 不明で signup grant skip'));
            }
        }

        $plan = $this->resolveInvoicePlan($payload, $organization);
        if ($plan === null || $plan->monthly_ticket_grant <= 0) {
            return;
        }

        // invoice id が取れない payload では安定した冪等キーを作れないため付与しない (fail-closed)
        $invoiceId = $this->stringAt($payload, 'data.object.id');
        if ($invoiceId === null) {
            report(new RuntimeException('invoice.paid: invoice id 不明で月次付与 skip'));

            return;
        }

        $this->tickets->grantMonthly(
            $organization,
            $plan->monthly_ticket_grant,
            null, // 月次付与の期限はテンプレートでは無期限 (期限運用は派生アプリの判断で渡す)
            "monthly:{$invoiceId}",
            "月次チケット付与 (plan: {$plan->code} / invoice: {$invoiceId})",
        );
    }

    /**
     * subscription snapshot から subscriptions.current_period_end を同期する
     * (renewal reminder = billing:send-billing-reminders の真実源)。
     *
     * subscriptions 行自体の作成は Cashier の WebhookController が行う。WebhookReceived は
     * Cashier の同期処理より先に発火するため、created イベント時点では行が無いことがある
     * (best-effort: 直後の customer.subscription.updated / 次周期の更新で追随する)。
     *
     * @param  array<mixed>  $payload
     */
    private function syncSubscriptionPeriod(array $payload): void
    {
        $stripeId = $this->stringAt($payload, 'data.object.id');
        if ($stripeId === null) {
            return;
        }

        // 新 API (basil) は item 配下、旧 API は subscription top-level に持つため両系を fallback で拾う
        $periodEnd = data_get($payload, 'data.object.items.data.0.current_period_end')
            ?? data_get($payload, 'data.object.current_period_end');
        if (! is_int($periodEnd) || $periodEnd <= 0) {
            return;
        }

        Subscription::query()
            ->where('stripe_id', $stripeId)
            ->update(['current_period_end' => CarbonImmutable::createFromTimestamp($periodEnd)]);
    }

    /**
     * invoice.payment_failed: 観測ログ + 支払い失敗通知 (dedup は通知台帳の (type, invoice_id))。
     * past_due 状態遷移・督促回数管理は派生アプリの拡張点。
     *
     * @param  array<mixed>  $payload
     */
    private function handleInvoicePaymentFailed(array $payload): void
    {
        $invoiceId = $this->stringAt($payload, 'data.object.id');
        $organization = $this->resolveOrganization($payload);

        Log::warning('stripe webhook: invoice payment failed', [
            'invoice_id' => $invoiceId,
            'customer_id' => $this->stringAt($payload, 'data.object.customer'),
            'attempt_count' => data_get($payload, 'data.object.attempt_count'),
        ]);

        if ($invoiceId === null || $organization === null) {
            return;
        }

        $this->safelyNotify(fn () => $this->notifications->sendOnce(
            $organization,
            BillingNotificationType::PaymentFailed,
            $invoiceId,
            new PaymentFailedNotification($invoiceId, $organization->name, route('billing.index')),
        ));
    }

    /**
     * 請求通知の呼び出しを保護する最終防壁。通知失敗が webhook 本処理 (plan_code 同期/付与) を
     * 巻き込まないよう、想定外の例外を握りつぶす。通知失敗の一次吸収・記録は dispatcher 側の
     * 責務で、ここは dispatcher が想定外に throw した場合のみ拾う (report で可観測化)。
     */
    private function safelyNotify(callable $notify): void
    {
        try {
            $notify();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * charge.refunded: 買い切りチケットを累積返金額に応じて逆仕訳 (clawback) する。
     * payment_intent が無い charge (手動 charge 等) は対象外。
     *
     * @param  array<mixed>  $payload
     */
    private function clawbackRefundedTickets(array $payload): void
    {
        $paymentIntentId = $this->stringAt($payload, 'data.object.payment_intent');
        if ($paymentIntentId === null) {
            return;
        }

        $amountRefunded = data_get($payload, 'data.object.amount_refunded');
        $this->tickets->clawbackPurchasedByPaymentIntent(
            $paymentIntentId,
            is_int($amountRefunded) ? $amountRefunded : 0,
        );
    }

    /**
     * invoice payload から紐づく subscription id を解決する (signup grant の安定冪等キー用)。
     * 旧 Stripe API は top-level `subscription`、新 API は lines 配下に持つため両系を fallback で拾う。
     *
     * @param  array<mixed>  $payload
     */
    private function resolveInvoiceSubscriptionId(array $payload): ?string
    {
        return $this->stringAt($payload, 'data.object.subscription')
            ?? $this->stringAt($payload, 'data.object.lines.data.0.subscription')
            ?? $this->stringAt($payload, 'data.object.lines.data.0.parent.subscription_item_details.subscription');
    }

    /**
     * invoice の対象プランを解決する。invoice 明細の price → plan_prices 逆引きを優先し、
     * 取れなければ organizations.plan_code に fallback (順序逆転への防御)。
     *
     * @param  array<mixed>  $payload
     */
    private function resolveInvoicePlan(array $payload, Organization $organization): ?Plan
    {
        $priceId = $this->stringAt($payload, 'data.object.lines.data.0.price.id')
            ?? $this->stringAt($payload, 'data.object.lines.data.0.pricing.price_details.price');

        if ($priceId !== null) {
            $plan = $this->planByStripePriceId($priceId);
            if ($plan !== null) {
                return $plan;
            }
        }

        $plan = $organization->plan;

        return $plan instanceof Plan ? $plan : null;
    }

    /**
     * payload の customer (stripe_id) から組織を解決する。
     * 不明な customer は受理のみで終わる (他環境の webhook 等)。
     *
     * @param  array<mixed>  $payload
     */
    private function resolveOrganization(array $payload): ?Organization
    {
        $customerId = $this->stringAt($payload, 'data.object.customer');
        if ($customerId === null) {
            return null;
        }

        return Organization::query()->where('stripe_id', $customerId)->first();
    }

    private function planByStripePriceId(string $priceId): ?Plan
    {
        $price = PlanPrice::query()->where('stripe_price_id', $priceId)->first();
        $plan = $price?->plan;

        return $plan instanceof Plan ? $plan : null;
    }

    /**
     * payload から string 値を安全に取り出す (それ以外の型は null)。
     *
     * @param  array<mixed>  $payload
     */
    private function stringAt(array $payload, string $path): ?string
    {
        $value = data_get($payload, $path);

        return is_string($value) ? $value : null;
    }
}
