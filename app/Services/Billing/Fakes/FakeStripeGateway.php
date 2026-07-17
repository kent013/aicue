<?php

declare(strict_types=1);

namespace App\Services\Billing\Fakes;

use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;

/**
 * StripeGatewayInterface の runtime fake (fake_externals 環境専用)。
 * 契約は FakeTicketCheckoutGateway と同じ「中立帰還」。subscription 状態は変更しない
 * (active subscription の正本は BughuntBillingSeeder)。
 */
final class FakeStripeGateway implements StripeGatewayInterface
{
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
    ): ExternalBillingRedirect {
        return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($cancelUrl));
    }

    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
    {
        return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($returnUrl));
    }

    public function syncCustomerDetails(Organization $organization): void
    {
        // no-op: fake 環境は実 Stripe を叩かない (実呼び出しの正本は CashierStripeGateway)。
    }
}
