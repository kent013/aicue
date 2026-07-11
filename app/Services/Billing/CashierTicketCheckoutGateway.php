<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Webmozart\Assert\Assert;

/**
 * TicketCheckoutGateway の Cashier (Stripe SDK) 実装。
 *
 * Cashier の checkout() ヘルパは per-request idempotency key を公開しないため、
 * Cashier の Stripe クライアント ($organization->stripe()) を直接使う。
 */
final class CashierTicketCheckoutGateway implements TicketCheckoutGateway
{
    public function createTicketCheckout(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata,
    ): CreatedCheckoutSession {
        $organization->createOrGetStripeCustomer();

        $session = $organization->stripe()->checkout->sessions->create(
            $this->buildSessionPayload($organization, $stripePriceId, $quantity, $successUrl, $cancelUrl, $metadata),
            ['idempotency_key' => $idempotencyKey],
        );

        // hosted mode では url / expires_at が常に返る (欠落は SDK/設定異常として fail-fast)
        Assert::string($session->url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');
        Assert::integer($session->expires_at, 'Checkout Session に expires_at がありません');

        return new CreatedCheckoutSession(
            sessionId: $session->id,
            url: $session->url,
            expiresAt: CarbonImmutable::createFromTimestamp($session->expires_at),
        );
    }

    public function expireCheckoutSession(string $sessionId): string
    {
        // 決済主体は organization だが expire は session id 単独で完結する
        // (呼び出し側が自 org 行の session id のみ渡す契約)
        $session = Cashier::stripe()->checkout->sessions->expire($sessionId);

        return is_string($session->status) ? $session->status : 'expired';
    }

    /**
     * Checkout Session payload (pure)。
     *
     * invariant (TicketPurchaseWebhookTest / gateway ユニットテストで固定):
     * `allow_promotion_codes` / `automatic_tax` を含まない。webhook の金額照合
     * `amount_subtotal === count × unit` はこの前提に依存する。promo/tax を将来
     * 有効化する場合は照合式を amount_total 系へ移行し、invariant テストの更新が変更の入口。
     *
     * @param  array<string, string>  $metadata
     * @return array{
     *   mode: 'payment',
     *   customer: string,
     *   line_items: array{array{price: string, quantity: int}},
     *   payment_method_types: array{'card'},
     *   success_url: string,
     *   cancel_url: string,
     *   metadata: array<string, string>
     * }
     */
    public function buildSessionPayload(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
    ): array {
        // createOrGetStripeCustomer() 後は必ず存在する (欠落は設定異常として fail-fast)
        $customerId = $organization->stripe_id;
        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では Checkout を作れません');

        return [
            'mode' => 'payment',
            'customer' => $customerId,
            'line_items' => [
                [
                    'price' => $stripePriceId,
                    'quantity' => $quantity,
                ],
            ],
            // 即時決済のみ (非同期決済を許可すると「completed = 決済済み」の前提が壊れる)
            'payment_method_types' => ['card'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
        ];
    }
}
