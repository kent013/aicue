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
 *
 * **`plan_code` は entitlement 判定に一切使わない** (quota の解決キーでしかない)。かつては
 * 「plan_code null = fallback free プラン = 支払い不要 tier として許可」していたが
 * (devnotes/20260712-0927-bugfix-billing-free-access。歴史として保持する)、ゲート反転で
 * 無料枠は `organizations.free_plan_code = 'personal'` の明示申告 (`ActiveFreePlan`) として
 * 表現するようになった。plan_code が null であること自体は許可の理由にならない。
 */
class BillingAccess
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * 組織が業務機能を利用してよいか (billing entitlement)。
     *
     * 判定は `state()->grantsAccess()` の一本 (= 無料枠は `ActiveFreePlan`、有償は
     * `Subscribed` でのみ許可)。移行 OR (`plan_code === null` を通す 1 行) はゲート反転で
     * 削除済み — 既存の未契約組織は grandfathering backfill が `free_plan_code = 'personal'`
     * を書いて `ActiveFreePlan` として許可されるため、締め出しは発生しない。
     */
    public function hasActiveAccess(Organization $organization): bool
    {
        return $this->state($organization)->grantsAccess();
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

        // live/stale の判定は BillingCheckoutSession の述語だけが定義する (P9 C-1)。
        // 閾値 literal をここに再発明しない (CheckoutLiveThresholdSingleSourceTest が機械検出)。
        $now = CarbonImmutable::now();
        /** @var Collection<int, BillingCheckoutSession> $pendingRows */
        $pendingRows = BillingCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('status', CheckoutSessionStatus::Pending->value)
            ->get(['id', 'status', 'created_at']);

        $hasLivePending = false;
        $hasStalePending = false;
        foreach ($pendingRows as $row) {
            if ($row->isLivePending($now)) {
                $hasLivePending = true;
            } else {
                $hasStalePending = true;
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
}
