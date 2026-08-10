<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\Enums\Billing\BillingRetentionTarget;
use App\Models\Billing\TicketCheckoutSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * チケット買い切り購入 Checkout の追跡行の purge (起算点 = completed_at)。
 *
 * @extends AbstractBillingRetentionPurger<TicketCheckoutSession>
 */
final class TicketCheckoutSessionPurger extends AbstractBillingRetentionPurger
{
    public function target(): BillingRetentionTarget
    {
        return BillingRetentionTarget::TicketCheckoutSession;
    }

    /** @return Builder<TicketCheckoutSession> */
    protected function baseQuery(): Builder
    {
        return TicketCheckoutSession::query();
    }
}
