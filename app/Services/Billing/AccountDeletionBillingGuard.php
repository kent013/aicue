<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\SubscriptionState;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Webmozart\Assert\Assert;

/**
 * 退会 (アカウント削除) ガードのための **課金責務** 判定。
 *
 * **これは entitlement (利用可否) の判定ではない**。利用可否の唯一の窓口は BillingAccess /
 * SubscriptionService::deriveEntitlement であり、本クラスはそれとは別の問い
 * 「**この組織に、将来の請求を発生させうる subscription が残っているか**」に答える。
 * 両者は一致しない (例: PastDue かつ PM 無しは entitlement 上 denied だが請求責務は残りうる)。
 *
 * 判定は subscriptions 行のみを入力にする **読み取り専用**。決済事業者 API は呼ばない
 * (退会処理から Stripe を呼ばない原則。自 DB と外部サービスの二重書き込みを避ける)。
 */
final class AccountDeletionBillingGuard
{
    /**
     * 生きた課金責務があるか。
     *
     *   ある := SubscriptionState::fromSubscription($sub)->grantsAccess()
     *           (= Active / UpgradeRecovery / PastDue) かつ $sub->ends_at === null
     *           を満たす subscription 行が 1 つでも存在する
     *
     * - `paused` / `unpaid` / `canceled` / `incomplete*` は Paused / Unpaid / Inactive に
     *   写像されて通過 (いずれも grantsAccess は false = 請求が発生しない or 終端)。
     * - `ends_at !== null` (= 期末解約予約済み / 終了済み) は通過。Stripe が自動終了させるため
     *   追加請求が発生せず、ここで止めると「解約したのに退会できない」詰みを作る。
     */
    public function hasLiveBillingObligation(Organization $organization): bool
    {
        // Cashier の relation は基底 Model 型を返すため narrowing する。想定外の型は
        // **黙って読み飛ばさず落とす** (課金ガードで fail-open すると宙づり課金を通してしまう。
        // モデル差し替えは Cashier::useSubscriptionModel 済みなので通常は起きない)。
        foreach ($organization->subscriptions()->whereNull('ends_at')->get() as $subscription) {
            Assert::isInstanceOf($subscription, Subscription::class);

            if (SubscriptionState::fromSubscription($subscription)->grantsAccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Owner が 1 人も居ないのに生きた課金責務が残っている組織 (= 課金孤児)。
     * 検知バッチ専用の読み取り経路。
     *
     * 入力は「Owner 不在の組織」だけ (通常 0 件の異常系集合) なので、組織ごとに
     * subscription を引く N+1 を許容する。件数が増えたら exists subquery 化する
     * (判断の記録は docs/architecture.md)。
     *
     * **入力契約**: 呼び出し側が「Owner 不在の組織」を渡す。本メソッドは Owner の有無を判定せず、
     * 渡された集合を課金責務でフィルタするだけ (Owner 判定の責務は
     * OrganizationMembershipService::organizationsWithoutOwner() 側)。
     *
     * @param  Collection<int, Organization>  $ownerlessOrganizations
     * @return list<int> organization id のみ (組織名・メール等の PII を載せない)
     */
    public function orphanBillingOrganizationIds(Collection $ownerlessOrganizations): array
    {
        $ids = $ownerlessOrganizations
            ->filter(fn (Organization $org): bool => $this->hasLiveBillingObligation($org))
            ->map(function (Organization $org): int {
                // getKey() の mixed を PHPStan L10 で narrowing (黙って (int) キャストせず
                // 想定外の型を検出する)
                $key = $org->getKey();
                Assert::integer($key);

                return $key;
            })
            ->all();

        return array_values($ids);
    }
}
