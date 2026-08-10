<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\Enums\Billing\BillingRetentionTarget;
use App\Models\Billing\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * 継続課金契約 (subscriptions) の purge (起算点 = ends_at)。
 *
 * ★`ends_at IS NULL` は**継続中の契約 = 正常な起算未到来**であり、何年前に作られていても
 *   期限超過にも異常にもならない (補助時計を持たない)。ここを `created_at` で起算すると
 *   **生きている契約の記録を消す**。
 *
 * ★明細 (`subscription_items`) が残っている契約は**消さない** (fail-closed)。FK は
 *   cascade なので DELETE 自体は成功してしまうが、それは子 purger が決着させられなかった
 *   行を件数報告を経由せず道連れにすることを意味する。残して報告する方を採る。
 *
 * @extends AbstractBillingRetentionPurger<Subscription>
 */
final class SubscriptionPurger extends AbstractBillingRetentionPurger
{
    public function target(): BillingRetentionTarget
    {
        return BillingRetentionTarget::Subscription;
    }

    /** @return Builder<Subscription> */
    protected function baseQuery(): Builder
    {
        return Subscription::query();
    }

    /**
     * 期限超過だが明細が残っている契約 (消さずに計上する)。
     *
     * @return Builder<Subscription>
     */
    protected function blockedQuery(CarbonImmutable $threshold): Builder
    {
        return $this->expiredQuery($threshold)->has('items');
    }

    /**
     * 実際に削除する契約 = 期限超過かつ明細が残っていないもの。
     *
     * @return Builder<Subscription>
     */
    protected function deletableQuery(CarbonImmutable $threshold): Builder
    {
        return $this->expiredQuery($threshold)->doesntHave('items');
    }
}
