<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use App\Models\Billing\Subscription;

/**
 * Subscription の派生状態。
 *
 * `Active` / `UpgradeRecovery` は流入制御を通過させる。
 * `Inactive` は `canceled` / `unpaid` / `incomplete` / `incomplete_expired` を統合した拒否状態。
 * `incomplete` / `unpaid` を `Active` に含めない理由: いずれも支払いが完了していない
 * (= 顧客カードが未承認 or 失敗) 状態のため、流入制御の目的 (= LLM コスト負担確認) に反する。
 *
 *  - `PastDue` = 有料化後 (PM 登録済) の請求失敗・dunning 中。**回復余地あり**で利用は継続させる
 *    (grantsAccess=true)。PM **無し** past_due (= trial 後カード無し dunning) は entitlement gate
 *    (`SubscriptionService::deriveEntitlement`) で別途遮断する。
 *  - `Paused` = trial 終了後カード未登録で Stripe が paused にした read-only 状態 (grantsAccess=false)。
 *
 * **重要**: 利用可否の最終判定を state 単体で行ってはならない。`grantsAccess` は state のみの粗い
 * 判定であり、PM 有無 / trial_ends_at / Stripe status snapshot を加味した最終判定は
 * `SubscriptionService::deriveEntitlement` が唯一の経路。
 *
 * 移植元の `ScheduledForUpgrade` は入力列 (`subscriptions.pending_plan_code`) が AI-CUE に無いため
 * 非移植。`upgrade_recovery_required` 列も無いため、`UpgradeRecovery` は schedule 部分完了
 * (`stripe_schedule_id` + `schedule_setup_status=Created`) の分岐のみを持つ。
 */
enum SubscriptionState: string
{
    case Active = 'active';
    case UpgradeRecovery = 'upgrade_recovery';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Inactive = 'inactive';

    /**
     * Subscription model から派生状態を導出。
     *
     * 評価順は重要 (stripe_status を最優先に保つ):
     *   1. stripe_status を最初に評価 → terminal/拒否系は即返却 (schedule_id に関わらず)
     *   2. paused / past_due は専用 state へ
     *   3. schedule_setup_status === Created (部分完了) は UpgradeRecovery 扱い
     */
    public static function fromSubscription(Subscription $sub): self
    {
        // paused / past_due は固有 state に分離 (stripe_status 最優先・schedule 状態に依らない)。
        if ($sub->stripe_status === 'paused') {
            return self::Paused;
        }
        if ($sub->stripe_status === 'past_due') {
            return self::PastDue;
        }

        // trialing は試用期間として通す。それ以外の非 active 系 (canceled/unpaid/incomplete*) は Inactive。
        $activeStatuses = ['active', 'trialing'];
        if (! in_array($sub->stripe_status, $activeStatuses, true)) {
            return self::Inactive;
        }

        // 部分完了 schedule は recovery 扱い (Stripe phases 未設定 = phase transition 起きない)。
        // enum cast 経由なので instance 比較。
        if ($sub->stripe_schedule_id !== null
            && $sub->schedule_setup_status === ScheduleSetupStatus::Created) {
            return self::UpgradeRecovery;
        }

        return self::Active;
    }

    /**
     * state 単体の粗いアクセス判定。**最終判定には使わない**
     * (`SubscriptionService::deriveEntitlement` 経由が唯一の経路)。
     *
     * - `PastDue` = true: 請求失敗中でも利用継続 (PM 無し past_due の遮断は deriveEntitlement)。
     * - `Paused` = false: trial 後カード無し read-only。
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Active, self::UpgradeRecovery, self::PastDue => true,
            self::Paused, self::Inactive => false,
        };
    }
}
