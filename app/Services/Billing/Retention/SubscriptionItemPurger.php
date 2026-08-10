<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\Enums\Billing\BillingRetentionTarget;
use App\Models\Billing\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Cashier\SubscriptionItem;

/**
 * 継続課金の明細 (subscription_items) の purge。
 *
 * **起算点は自テーブルに無い** — 明細は「いつ終わったか」を持たず、終了時刻は親契約の
 * `subscriptions.ends_at` である。よって目録の起算点は修飾名 `subscriptions.ends_at` で、
 * ここでは親への `whereHas` に落とす。親が継続中 (`ends_at IS NULL`) の明細は
 * **起算未到来**であって異常ではない (補助時計を持たない = 異常検出をしない)。
 *
 * 親を先に消せば FK cascade で明細も消えるが、**それでは何件消えたかを報告できない**
 * (規約準拠の証明は件数で行う)。子 → 親の順で明示的に消す。
 *
 * @extends AbstractBillingRetentionPurger<SubscriptionItem>
 */
final class SubscriptionItemPurger extends AbstractBillingRetentionPurger
{
    public function target(): BillingRetentionTarget
    {
        return BillingRetentionTarget::SubscriptionItem;
    }

    /** @return Builder<SubscriptionItem> */
    protected function baseQuery(): Builder
    {
        return SubscriptionItem::query();
    }

    /**
     * 親契約が終了済み (ends_at 非 null) かつ期限超過の明細。
     *
     * 親の絞り込みは**副問合せ**で書く (`whereHas` のクロージャは親の型が
     * 静的に決まらず、型検査の効かない場所を作るため)。
     *
     * @return Builder<SubscriptionItem>
     */
    protected function expiredQuery(CarbonImmutable $threshold): Builder
    {
        return $this->baseQuery()->whereIn(
            'subscription_id',
            Subscription::query()
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', $threshold)
                ->select('id'),
        );
    }
}
