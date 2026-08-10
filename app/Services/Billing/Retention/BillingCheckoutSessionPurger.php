<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\Enums\Billing\BillingRetentionTarget;
use App\Models\Billing\BillingCheckoutSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * サブスク契約 Checkout の追跡行の purge (起算点 = completed_at)。
 *
 * 未完了 (pending / expired / failed) のまま保持期限を超えた行は**異常**として計上する
 * (完了しなかった手続きは「取引の終了時」を持たないため、起算できない)。
 *
 * @extends AbstractBillingRetentionPurger<BillingCheckoutSession>
 */
final class BillingCheckoutSessionPurger extends AbstractBillingRetentionPurger
{
    public function target(): BillingRetentionTarget
    {
        return BillingRetentionTarget::BillingCheckoutSession;
    }

    /** @return Builder<BillingCheckoutSession> */
    protected function baseQuery(): Builder
    {
        return BillingCheckoutSession::query();
    }
}
