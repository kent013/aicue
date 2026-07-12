<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;

/**
 * サブスクリプションの Stripe Checkout / Customer Portal 抽象
 * (実装: CashierSubscriptionCheckoutGateway。fake_externals 時は fake を bind)。
 * Stripe 呼び出しを本 interface に閉じ、Controller は戻り値 DTO の URL へ
 * Inertia::location するのみ。
 */
interface SubscriptionCheckoutGateway
{
    /**
     * subscription (type=default) の hosted Checkout Session を作り遷移先を返す。
     */
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
    ): ExternalBillingRedirect;

    /**
     * Customer Portal セッションを作り遷移先を返す
     * (configuration は PortalConfigurationSpec 準拠。実装側で解決する)。
     */
    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect;
}
