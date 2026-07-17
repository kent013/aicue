<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\OnboardingBillingState;
use App\Enums\CheckoutSessionStatus;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Organization の課金状態を「流入制御目線」で判定する責務。
 *
 * **課金による利用可否の判定は必ず本クラスを経由する** (middleware / controller /
 * service での subscription 直参照は禁止)。OrganizationPolicy 等の user action 認可とは
 * 責務分離する (Policy は user × organization、本 service は organization × subscription state)。
 *
 * 利用可否は `SubscriptionState` 単体ではなく `SubscriptionService::deriveEntitlement` で
 * 確定する (PM 有無 / trial 終了 / paused / past_due を合成)。
 */
class BillingAccess
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * 組織が業務機能を利用してよいか (billing entitlement)。
     *
     * `state()->grantsAccess()` が本来の判定。これに加えて **移行 OR を 1 行持つ**:
     * 現行の意図的な free 許可 (= `plan_code === null` の未契約組織) をそのまま通す。
     *
     * **この移行 OR は P4 (ゲート反転) で削除する**。削除条件は grandfathering backfill
     * (`organizations.free_plan_code = 'personal'`) の完了で、backfill が `ActiveFreePlan` を
     * 成立させることで既存の free 組織が `state()` 側で許可される。**本行を消すことが
     * ゲート反転そのもの**であり、P4 はこの 1 行削除 + 期待反転の diff だけで済む。
     */
    public function hasActiveAccess(Organization $organization): bool
    {
        if ($this->state($organization)->grantsAccess()) {
            return true;
        }

        return $organization->plan_code === null;
    }

    /**
     * 流入制御目線の課金状態。**`plan_code` を一切見ない** (entitlement は subscription /
     * free_plan_code / checkout session から導出する)。
     *
     * 読み取り経路のため **DB 書き込みをしない**。stale な pending checkout は in-memory で
     * expired 扱いにしてアクセス判定の整合性を保ち、実 DB の expired 化は sweeper に委ねる
     * (require.subscription が付く多数の GET 経路で毎回 UPDATE が走る副作用を排除する)。
     */
    public function state(Organization $organization): OnboardingBillingState
    {
        $sub = $organization->subscription('default');
        $entitled = $sub instanceof Subscription
            && $this->subscriptions->deriveEntitlement($sub)->entitled;

        // 利用可否は SubscriptionState 単体ではなく deriveEntitlement で確定する
        // (SubscriptionState::grantsAccess を直接参照しない)。
        if ($entitled) {
            return OnboardingBillingState::Subscribed;
        }

        // 現在 entitled な Stripe subscription が「ない」(行の不在ではなく entitlement で判定。
        // canceled 等の過去行が残っていてもよい = paid→free 経路) とき free entitlement を見る。
        // 判定は定数比較 (未知値は fail-closed で通さない)。entitled subscription があれば上で
        // Subscribed 優先 (free と併存しない invariant)。
        if ($organization->free_plan_code === PersonalPlanService::FREE_PLAN_CODE) {
            return OnboardingBillingState::ActiveFreePlan;
        }

        if ($sub instanceof Subscription) {
            // 利用不可 (Inactive / Paused / trial 終了 & PM 無 / PM 無 past_due) は gate を通さない
            // → ExpiredCheckout 扱い (未契約導線へ)。
            return OnboardingBillingState::ExpiredCheckout;
        }

        $threshold = self::staleThresholdAt(CarbonImmutable::now());
        /** @var Collection<int, BillingCheckoutSession> $pendingRows */
        $pendingRows = BillingCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('status', CheckoutSessionStatus::Pending->value)
            ->get(['id', 'created_at']);

        $hasLivePending = false;
        $hasStalePending = false;
        foreach ($pendingRows as $row) {
            if ($row->created_at !== null && $row->created_at->lessThan($threshold)) {
                $hasStalePending = true;
            } else {
                $hasLivePending = true;
            }
        }

        if ($hasLivePending) {
            return OnboardingBillingState::PendingCheckout;
        }

        $hasExpired = $hasStalePending || BillingCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [
                CheckoutSessionStatus::Expired->value,
                CheckoutSessionStatus::Failed->value,
            ])
            ->exists();

        return $hasExpired ? OnboardingBillingState::ExpiredCheckout : OnboardingBillingState::NoSubscription;
    }

    /**
     * pending checkout の stale 境界 (単一出典)。
     *
     * **live = `created_at >= staleThresholdAt($now)` / stale = `created_at < staleThresholdAt($now)`**
     * の排他で統一する。sweeper (実 DB の expire) も本 helper を `<` で読むこと。
     */
    public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDay();
    }
}
