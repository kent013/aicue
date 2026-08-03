<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Enums\Billing\SubscriptionSwapOutcome;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * StripeGatewayInterface のテスト用 spy (Stripe に到達しない)。
 *
 * - createSubscriptionCheckout: 呼び出しを記録し、idempotency key から決定的な
 *   session id / URL を返す (Stripe の idempotency replay と同じ収束特性を再現)
 * - expireCheckoutSession: 呼び出しを記録し、$expireResult を返す ($failOnExpire で throw)
 * - swapSubscriptionPrices: 呼び出しを記録し、$swapOutcome を返す (プラン変更 = 実 Stripe に出ない)
 */
final class FakeStripeGateway implements StripeGatewayInterface
{
    /** @var list<array{organizationId: int, stripePriceId: string, successUrl: string, cancelUrl: string, metadata: array<string, string>, idempotencyKey: string}> */
    public array $created = [];

    /** @var list<string> expire を要求された session id */
    public array $expired = [];

    /** expireCheckoutSession の返り値 ('expired' / 'complete' 等) */
    public string $expireResult = 'expired';

    /** true にすると expireCheckoutSession が throw する (Stripe 障害の再現) */
    public bool $failOnExpire = false;

    /** true にすると createSubscriptionCheckout が throw する */
    public bool $failOnCreate = false;

    /** @var list<int> syncCustomerDetails を呼ばれた org id */
    public array $synced = [];

    /** @var list<array{organizationId: int, basePriceId: string, idempotencyKey: string}> swap 呼び出し */
    public array $swapped = [];

    /** swapSubscriptionPrices の返り値 (remote 照合結果の再現) */
    public SubscriptionSwapOutcome $swapOutcome = SubscriptionSwapOutcome::Applied;

    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): CreatedCheckoutSession {
        if ($this->failOnCreate) {
            throw new RuntimeException('fake stripe: createSubscriptionCheckout failed');
        }

        $this->created[] = [
            'organizationId' => (int) $organization->getKey(),
            'stripePriceId' => $stripePriceId,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'metadata' => $metadata,
            'idempotencyKey' => $idempotencyKey,
        ];

        $token = substr(hash('sha256', $idempotencyKey), 0, 32);

        return new CreatedCheckoutSession(
            sessionId: "cs_test_{$token}",
            url: "https://checkout.stripe.test/c/pay/cs_test_{$token}",
            expiresAt: CarbonImmutable::now()->addDay(),
        );
    }

    public function swapSubscriptionPrices(
        Organization $organization,
        string $basePriceId,
        string $idempotencyKey,
    ): SubscriptionSwapOutcome {
        $this->swapped[] = [
            'organizationId' => (int) $organization->getKey(),
            'basePriceId' => $basePriceId,
            'idempotencyKey' => $idempotencyKey,
        ];

        return $this->swapOutcome;
    }

    public function expireCheckoutSession(string $stripeSessionId): string
    {
        if ($this->failOnExpire) {
            throw new RuntimeException('fake stripe: expireCheckoutSession failed');
        }

        $this->expired[] = $stripeSessionId;

        return $this->expireResult;
    }

    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
    {
        return new ExternalBillingRedirect('https://billing.stripe.test/p/session/test?return='.urlencode($returnUrl));
    }

    public function syncCustomerDetails(Organization $organization): void
    {
        $this->synced[] = (int) $organization->getKey();
    }
}
