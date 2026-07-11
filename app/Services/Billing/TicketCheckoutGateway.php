<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\Models\Organization;

/**
 * チケットスポット購入の Stripe Checkout 抽象 (実装: CashierTicketCheckoutGateway。
 * テストは fake を bind する)。Stripe 呼び出しを本 interface に閉じる。
 */
interface TicketCheckoutGateway
{
    /**
     * one-time Checkout Session を作る (mode=payment / card のみ / promo・tax なし)。
     * $idempotencyKey により同一 attempt の再送は Stripe 側で同一 session に収束する。
     *
     * @param  array<string, string>  $metadata  照合専用 (認可・org 解決には使わない)
     */
    public function createTicketCheckout(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata,
    ): CreatedCheckoutSession;

    /**
     * Stripe 側 session を expire する。
     *
     * @return string expire 後の session status ('expired'|'complete'|...)
     */
    public function expireCheckoutSession(string $sessionId): string;
}
