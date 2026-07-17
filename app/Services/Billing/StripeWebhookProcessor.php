<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\BillingNotificationType;
use App\Enums\Billing\HandledStripeWebhookEvent;
use App\Enums\Billing\TicketCheckoutSessionStatus;
use App\Enums\Billing\WebhookEventStatus;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Billing\Subscription;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Organization;
use App\Notifications\Billing\PaymentFailedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

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
 *
 * plan_code 不変条件: `organizations.plan_code` は Stripe Price を持つ有償プランの
 * 契約 (active/trialing) 時のみ本クラスが set し、`customer.subscription.deleted` で
 * null に戻す状態キー。**null = 未契約 = 支払い不要の free tier**
 * (config/quota.php の fallback_plan が適用される)。BillingAccess はこの契約を
 * entitlement 判定の根拠にするため、支払い不要のプランを plan_code に載せる場合は
 * BillingAccess とセットで見直すこと (RequireActiveSubscriptionMiddlewareTest が固定)。
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
        private readonly PersonalPlanService $personalPlan,
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
                    // 付与系イベントの取りこぼしは「決済済み・未付与」を残すため運用アラート経路
                    // (report) にも載せる (failure_reason 参照 → 手動 grantPurchased 判断)
                    if (in_array($type, [
                        HandledStripeWebhookEvent::CheckoutSessionCompleted->value,
                        HandledStripeWebhookEvent::InvoicePaid->value,
                    ], true)) {
                        report(new RuntimeException("stripe webhook terminal failure (grant イベント): {$eventId} ({$type})"));
                    }

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
            // チケットスポット購入の冪等付与 (T007。真実源は ticket_checkout_sessions 行)
            HandledStripeWebhookEvent::CheckoutSessionCompleted => $this->grantPurchasedTickets($payload),
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

        // 初回 signup grant (「まず触れる」導線)。冪等キーは org スコープのため subscription id は不要。
        // 1 組織 1 回の不変条件は idempotency_key + 部分 UNIQUE index が保証する。
        // (通常は登録時に付与済のため、ここは非個人組織のサブスク等に対する no-op ないし 1 回付与の安全網)
        if ($billingReason === 'subscription_create') {
            $organizationId = $organization->getKey();
            Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');

            // 移行期規約 (CreateNewUser / PersonalPlanService::activate と同一): org 行ロック下の
            // 単一 transaction で「marker の条件付き先取 → 先取できたときのみ付与」を原子的に行う。
            // marker (organizations.signup_tickets_granted_at) が付与の唯一の真実源であるため:
            //  - marker を立てないと、「登録経由でない org (追加組織) が初回契約で付与を受ける」経路で
            //    付与済みなのに marker が NULL のまま残り、後続の activate() が claim に成功して
            //    granted=true を返すのに ledger の org スコープ UNIQUE が実 insert を止める
            //    (= 残高は動かないのに「付与した」と応答する) 不整合が生じる。
            //  - 逆に marker だけ先に commit されて付与が失敗すると、marker が立っているため
            //    再送でも二度と付与されない (= 付与の取りこぼしが恒久化する)。よって同一 tx に閉じる。
            DB::transaction(function () use ($organizationId): void {
                $locked = Organization::query()->lockForUpdate()->findOrFail($organizationId);

                if ($this->personalPlan->claimSignupGrantMarker($locked)) {
                    $this->tickets->grantSignupGrant($locked, "signup_grant:org:{$organizationId}");
                }
            });
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
     * checkout.session.completed: チケットスポット購入の冪等付与。
     *
     * - purpose ガード: metadata.purpose=ticket_purchase かつ mode=payment 以外は受理のみ
     *   (サブスク checkout / 他 purpose を failed にしない)
     * - 真実源は自 DB 行 (ticket_checkout_sessions)。payload の customer / metadata は照合のみ
     *   (tenant キー不信)。行不在・照合不一致・未決済・金額不一致は例外 throw =
     *   retryable failure (既存 handle() の catch で failed + Stripe 再送。恒久不整合は
     *   attempts 上限の terminal-ack + failure_reason で運用調査へ)
     * - 付与は TicketLedgerService::grantPurchased (idempotency_key purchase:{sessionId}
     *   UNIQUE) で冪等。event_id 違い再送でも二重付与しない
     *
     * @param  array<mixed>  $payload
     */
    private function grantPurchasedTickets(array $payload): void
    {
        // (1) purpose ガード: ticket_purchase 以外 (サブスク checkout / 他 purpose / mode≠payment) は受理のみ
        if ($this->stringAt($payload, 'data.object.metadata.purpose') !== 'ticket_purchase') {
            return;
        }
        if ($this->stringAt($payload, 'data.object.mode') !== 'payment') {
            return;
        }

        $sessionId = $this->stringAt($payload, 'data.object.id');
        if ($sessionId === null) {
            throw new RuntimeException('checkout.session.completed: session id 欠落 (ticket_purchase)');
        }

        // (2) 真実源は自 DB 行。行不在は retryable failure (crash 先着 webhook は同一 attempt の
        //     再試行で DB 行が記録された後、Stripe の event 再送で本経路に収束する)
        $session = TicketCheckoutSession::query()->where('stripe_session_id', $sessionId)->first();
        if ($session === null) {
            throw new RuntimeException("ticket purchase webhook: 未追跡 session {$sessionId} (DB 行なし、再送待ち)");
        }

        // (3) tenant キー不信: payload の customer / metadata.org_ref は照合のみ。不一致は throw (fail-closed)
        $organization = $session->organization;
        Assert::isInstanceOf($organization, Organization::class);
        $customerId = $this->stringAt($payload, 'data.object.customer');
        if ($customerId === null || $organization->stripe_id !== $customerId) {
            throw new RuntimeException("ticket purchase webhook: customer 照合不一致 (session {$sessionId})");
        }
        // org_ref は照合専用 (認可・org 解決には使わない。真実源は DB 行 → organization relation)
        $metaOrgRef = $this->stringAt($payload, 'data.object.metadata.org_ref');
        if ($metaOrgRef !== (string) $organization->id) {
            throw new RuntimeException("ticket purchase webhook: metadata org_ref 照合不一致 (session {$sessionId})");
        }

        // (4) payment_status=paid 必須 (card 固定下の防御線。未決済 completed を付与しない)
        if ($this->stringAt($payload, 'data.object.payment_status') !== 'paid') {
            throw new RuntimeException("ticket purchase webhook: payment_status が paid でない (session {$sessionId})");
        }

        // (5) 金額照合: amount_subtotal === count × pin 単価、currency === pin (欠落・不一致は throw)。
        //     amount_total は税・割引の運用設定ドリフトで壊れるため使わない
        //     (作成側でも promo / automatic tax を使わない構成に固定 = 二重防御)
        $amountSubtotal = data_get($payload, 'data.object.amount_subtotal');
        $currency = $this->stringAt($payload, 'data.object.currency');
        if (! is_int($amountSubtotal)
            || $amountSubtotal !== $session->ticket_count * $session->unit_amount
            || $currency !== $session->currency) {
            // expected/actual を記録する (failed 連鎖時の運用復旧を高速化)
            throw new RuntimeException(sprintf(
                'ticket purchase webhook: 金額/通貨照合不一致 (session %s, expected %d %s, actual %s %s)',
                $sessionId,
                $session->ticket_count * $session->unit_amount,
                $session->currency,
                is_int($amountSubtotal) ? (string) $amountSubtotal : 'missing',
                $currency ?? 'missing',
            ));
        }

        // (6) 冪等付与 (idempotency_key purchase:{sessionId} UNIQUE) + 行 completed 化 (同一 TX)
        $paymentIntentId = $this->stringAt($payload, 'data.object.payment_intent');
        DB::transaction(function () use ($organization, $session, $amountSubtotal, $paymentIntentId): void {
            $this->tickets->grantPurchased(
                $organization,
                $session->ticket_count,
                $session->stripe_session_id,
                $paymentIntentId,
                $amountSubtotal, // 返金按分の分母 (clawback が使う)
            );
            if ($session->status !== TicketCheckoutSessionStatus::Completed) {
                $session->status = TicketCheckoutSessionStatus::Completed;
                $session->completed_at = CarbonImmutable::now();
                $session->save();
            }
        });
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
