<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * billing_checkout_sessions の購入意図。
 *
 * 移植元の `CreditPurchase` はチケットスポット購入を担う既存の別テーブル
 * (`App\Models\Billing\TicketCheckoutSession`) が受け持ち、`SignupFunding` は
 * campaign / trial 機構が AI-CUE に存在しないため、いずれも移植しない。
 */
enum CheckoutIntent: string
{
    case SubscriptionStart = 'subscription_start';

    /** オートリチャージ用の決済手段保存 (Checkout mode=setup)。課金は伴わない。 */
    case SetupPaymentMethod = 'setup_payment_method';
}
