<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\Enums\Billing\BillingRetentionTarget;
use App\Models\Billing\TicketAutoRechargeAttempt;
use Illuminate\Database\Eloquent\Builder;

/**
 * オートリチャージの課金試行の purge (起算点 = resolved_at)。
 *
 * pending のまま保持期限を超えた行は**異常**として計上する (資金回収済み・チケット未付与の
 * 滞留が何年も残っている状態であり、消してよい記録ではない)。
 *
 * @extends AbstractBillingRetentionPurger<TicketAutoRechargeAttempt>
 */
final class TicketAutoRechargeAttemptPurger extends AbstractBillingRetentionPurger
{
    public function target(): BillingRetentionTarget
    {
        return BillingRetentionTarget::TicketAutoRechargeAttempt;
    }

    /** @return Builder<TicketAutoRechargeAttempt> */
    protected function baseQuery(): Builder
    {
        return TicketAutoRechargeAttempt::query();
    }
}
