<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Jobs\Billing\SyncBillingCustomerDetails;
use App\Models\Organization;

/**
 * Stripe customer 同期 job の dispatch を集約する単一窓口 (IV-2)。
 *
 * 同期を発火するのは `RenameOrganizationAction` (組織名) と `UpdateBillingContactAction`
 * (請求先メール。宛名は Stripe へ送らない) のみ。
 * webhook ハンドラはこの経路を通らないため、Stripe→アプリ→Stripe の同期ループは構造的に発生しない。
 */
final class BillingCustomerSynchronizer
{
    /**
     * Stripe customer 同期 job を dispatch する。
     *
     * **必ず `DB::transaction` クロージャの内側から呼ぶこと。** transaction 内で
     * `afterCommit()` を付けることで outer commit 後に発火し、commit 前の stale read を防ぐ (IV-3)。
     * transaction の外で呼ぶと `afterCommit()` が即時実行になり遅延保証が崩れるため禁止。
     *
     * Stripe customer 未作成 (`stripe_id === null`) の組織は no-op (IV-4、例外にしない)。
     */
    public function dispatchFor(Organization $organization): void
    {
        if ($organization->stripe_id === null) {
            return;
        }

        SyncBillingCustomerDetails::dispatch($organization)->afterCommit();
    }
}
