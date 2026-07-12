<?php

declare(strict_types=1);

namespace App\Services\Billing\Fakes;

use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;
use App\Services\Billing\SubscriptionCheckoutGateway;

/**
 * SubscriptionCheckoutGateway の runtime fake (fake_externals 環境専用)。
 * 契約は FakeTicketCheckoutGateway と同じ「中立帰還」。subscription 状態は変更しない
 * (active subscription の正本は BughuntBillingSeeder)。
 */
final class FakeSubscriptionCheckoutGateway implements SubscriptionCheckoutGateway
{
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
    ): ExternalBillingRedirect {
        return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($cancelUrl));
    }

    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect
    {
        return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($returnUrl));
    }
}
