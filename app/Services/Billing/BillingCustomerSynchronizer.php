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
     * **必ず `DB::transaction` クロージャの内側から呼ぶこと** (呼び出し元 2 経路は既にそう)。
     *
     * 【契約の反転 (AG-114 確定 1 / T137)】
     * - 旧主張: transaction 内で `afterCommit()` を付け、outer commit 後に発火させる
     * - 旧目的: commit 前の stale read を防ぐ (IV-3)
     * - 新主張: `afterCommit()` を付けず、業務 tx の内側で素直に dispatch する
     * - 新前提: jobs 行が業務 tx に乗るため、**worker が job を可視化できるのは commit 後**
     * - 前提を守る機構: QueueDispatchAtomicityGuard (driver=database / キュー DB 接続 =
     *   業務 DB / after_commit=false を起動時に fail-closed 検査)
     * - 反転根拠: 本 job は SerializesModels で organization を **ID で直列化し handle 時に
     *   再取得**する。可視化が commit 後である以上、再取得値は必ず commit 後の値になり、
     *   IV-3 は afterCommit なしで (むしろより強く) 保たれる。加えて afterCommit は
     *   「commit したのに dispatch されない」窓を残すため、確定 1 の下では有害である
     *
     * Stripe customer 未作成 (`stripe_id === null`) の組織は no-op (IV-4、例外にしない)。
     */
    public function dispatchFor(Organization $organization): void
    {
        if ($organization->stripe_id === null) {
            return;
        }

        SyncBillingCustomerDetails::dispatch($organization);
    }
}
