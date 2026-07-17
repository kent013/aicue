<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * entitlement (利用可否) を否定する理由。
 *
 * `SubscriptionService::deriveEntitlement` が `entitled=false` のとき必ず付随させる。
 * フロントは reason 別に状態説明 (paused / trial 終了 & PM 無 / 請求失敗) を出し分ける。
 *
 * 注意: `PastDue` (state=PastDue) かつ PM 有りは entitled=true (請求失敗中も利用継続) のため、
 * ここに PastDue を「利用継続中」の理由としては置かない。past_due で entitled=false になる
 * のは PM 無し past_due のみで、それは trial 終了 & カード無しとして
 * `TrialEndedWithoutPaymentMethod` で表現する (trial 終了後の paused と区別)。
 */
enum EntitlementDeniedReason: string
{
    /** subscription が無い / Inactive (canceled・unpaid・incomplete 等)。 */
    case NoActiveSubscription = 'no_active_subscription';

    /** trial 終了後カード未登録で Stripe が paused にした (read-only)。 */
    case TrialEndedWithoutPaymentMethod = 'trial_ended_without_payment_method';

    /** Stripe status=paused (= 上記の確定状態)。 */
    case Paused = 'paused';
}
