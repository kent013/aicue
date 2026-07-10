<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Organization;

/**
 * 組織が業務機能を利用してよいか (課金ゲート) の判定。
 *
 * **課金による利用可否の判定は必ず本クラスを経由する** (middleware / controller /
 * service での subscription 直参照は禁止)。判定基準を 1 クラスに閉じ込めることで、
 * アプリ側は本クラスの書き換え (または container での差し替え bind) だけで
 * gate 方針を変更できる (例: 専用の billing 状態カラムでの判定や、
 * entitlement 導出への差し替え)。
 *
 * テンプレート既定は最小実装: Cashier の `subscription('default')` が
 * active / trialing なら許可。past_due / canceled / incomplete 等は不許可
 * (未契約と同じ扱いで billing へ誘導する)。
 */
class BillingAccess
{
    /** アクセスを許可する Stripe subscription status */
    private const array GRANTING_STATUSES = ['active', 'trialing'];

    public function hasActiveAccess(Organization $organization): bool
    {
        $subscription = $organization->subscription('default');

        // subscription 不在 (未契約) は fail-closed で不許可
        return $subscription !== null
            && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
    }
}
