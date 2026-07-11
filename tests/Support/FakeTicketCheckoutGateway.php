<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\Models\Organization;
use App\Services\Billing\TicketCheckoutGateway;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * TicketCheckoutGateway のテスト用 fake (Stripe に到達しない)。
 *
 * - createTicketCheckout: 呼び出しを記録し、idempotency key から決定的な
 *   session id / URL を返す (Stripe の idempotency replay と同じ収束特性を再現)
 * - expireCheckoutSession: 呼び出しを記録し、$expireResult を返す
 */
final class FakeTicketCheckoutGateway implements TicketCheckoutGateway
{
    /** @var list<array{organizationId: int, stripePriceId: string, quantity: int, idempotencyKey: string, metadata: array<string, string>}> */
    public array $created = [];

    /** @var list<string> expire を要求された session id */
    public array $expired = [];

    /** expireCheckoutSession の返り値 ('expired' / 'complete' 等) */
    public string $expireResult = 'expired';

    /** true にすると createTicketCheckout が throw する (Stripe 障害の再現) */
    public bool $failOnCreate = false;

    public function createTicketCheckout(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata,
    ): CreatedCheckoutSession {
        if ($this->failOnCreate) {
            throw new RuntimeException('fake gateway: create 失敗');
        }

        $this->created[] = [
            'organizationId' => $organization->id,
            'stripePriceId' => $stripePriceId,
            'quantity' => $quantity,
            'idempotencyKey' => $idempotencyKey,
            'metadata' => $metadata,
        ];

        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)
        $token = str_replace('purchase:', '', $idempotencyKey);

        return new CreatedCheckoutSession(
            sessionId: "cs_test_{$token}",
            url: "https://checkout.stripe.test/c/pay/cs_test_{$token}",
            expiresAt: CarbonImmutable::now()->addDay(),
        );
    }

    public function expireCheckoutSession(string $sessionId): string
    {
        $this->expired[] = $sessionId;

        return $this->expireResult;
    }
}
