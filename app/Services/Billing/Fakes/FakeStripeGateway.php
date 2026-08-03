<?php

declare(strict_types=1);

namespace App\Services\Billing\Fakes;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;

/**
 * StripeGatewayInterface の runtime fake (fake_externals 環境専用)。
 * 契約は FakeTicketCheckoutGateway と同じ「中立帰還」。subscription 状態は変更しない
 * (active subscription の正本は BughuntBillingSeeder)。
 *
 * session id は **idempotency key から決定的に導出**する (Stripe の idempotency replay と
 * 同じ収束特性 = 同一 key の再呼び出しで同一 sessionId)。
 */
final class FakeStripeGateway implements StripeGatewayInterface
{
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): CreatedCheckoutSession {
        $token = substr(hash('sha256', $idempotencyKey), 0, 32);

        return new CreatedCheckoutSession(
            sessionId: "cs_bughuntfake_{$token}",
            url: FakeExternalUrl::neutralReturn($cancelUrl),
            expiresAt: CarbonImmutable::now()->addDay(), // Stripe hosted checkout の既定 24h
        );
    }

    public function expireCheckoutSession(string $stripeSessionId): string
    {
        return 'expired';
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
