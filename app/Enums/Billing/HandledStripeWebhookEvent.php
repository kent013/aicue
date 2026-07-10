<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * StripeWebhookProcessor が明示的に処理する Stripe webhook イベントの単一出典。
 *
 * 背景: 「processor が処理するイベント集合」と「endpoint が購読する集合
 * (= config('cashier.webhook.events'))」のズレは、production で付与イベント
 * (checkout.session.completed / invoice.paid) を silent に取りこぼす事故の構造的原因になる。
 * 本 enum を購読集合導出の出典にし、`handled ⊆ subscribed` を CI invariant
 * (WebhookEventSubscriptionInvariantTest) で固定して再発を防ぐ。
 *
 * config の購読集合は本 enum と Cashier の DEFAULT_EVENTS の合成で導出する
 * (DEFAULT_EVENTS は Cashier 自身の customer.* housekeeping のため残す)。
 * 新たに processor で処理するイベントを追加する際は、本 enum に case を足し
 * StripeWebhookProcessor::process の match に arm を足すこと。
 */
enum HandledStripeWebhookEvent: string
{
    case SubscriptionCreated = 'customer.subscription.created';
    case SubscriptionUpdated = 'customer.subscription.updated';
    case SubscriptionDeleted = 'customer.subscription.deleted';
    case InvoicePaid = 'invoice.paid';
    // テンプレートでは受理のみの拡張点 (派生アプリで督促通知・決済ゲート等を実装する)。
    // 購読漏れによる silent 取りこぼしを防ぐため、拡張点も購読集合には最初から含める。
    case InvoicePaymentFailed = 'invoice.payment_failed';
    // テンプレートでは受理のみの拡張点 (派生アプリで TicketLedgerService::grantPurchased による
    // チケット購入付与等を実装する)。
    case CheckoutSessionCompleted = 'checkout.session.completed';
    // 返金 → 買い切りチケットの逆仕訳 (clawback)。「返金済みなのに消費可能」の整合性の穴を塞ぐ。
    case ChargeRefunded = 'charge.refunded';

    /**
     * processor が明示処理する全イベント値。config 購読集合の導出と invariant に使う。
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $event): string => $event->value, self::cases());
    }
}
