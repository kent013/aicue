<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * 冪等 checkout 開始の結果。
 *
 * - url あり → Inertia::location で Stripe Checkout へ遷移 (新規 or replay)
 * - url null + alreadyCompleted → 「受付済み」着地 (付与反映待ちの案内)
 */
final readonly class TicketCheckoutRedirect
{
    public function __construct(
        public ?string $url,
        public bool $alreadyCompleted,
    ) {}
}
