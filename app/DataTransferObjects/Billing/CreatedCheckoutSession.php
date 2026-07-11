<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use Carbon\CarbonImmutable;

/**
 * Gateway が作成した Stripe Checkout Session の snapshot (hosted mode 前提)。
 */
final readonly class CreatedCheckoutSession
{
    public function __construct(
        public string $sessionId,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
