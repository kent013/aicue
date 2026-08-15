<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * entitlement (利用可否) を否定する理由。
 *
 * `SubscriptionService::deriveEntitlement` が `entitled=false` のとき必ず付随させる。
 *
 * **現時点では画面 props に露出していない** (`app/Http/` にも `resources/js/` にも参照が無く、
 * 遮断時の文言は `RequireActiveSubscription::BLOCKED_MESSAGE` と着地ページが持つ)。
 * 非露出は `EntitlementReasonExposureTest` が固定している。露出させるときは同テストの契約を
 * 変え、TypeScript の union と表示テストを同時に足すこと。
 *
 * 注意: `PastDue` (state=PastDue) かつ PM 有りは**猶予の期限内なら** entitled=true
 * (請求失敗中も利用継続) のため、ここに PastDue を「利用継続中」の理由としては置かない。
 * past_due で entitled=false になるのは、PM 無し past_due (trial 終了 & カード無しとして
 * `TrialEndedWithoutPaymentMethod` で表現する) と、猶予切れ (`PaymentGraceExpired`) の 2 つ。
 */
enum EntitlementDeniedReason: string
{
    /** subscription が無い / Inactive (canceled・unpaid・incomplete 等)。 */
    case NoActiveSubscription = 'no_active_subscription';

    /** trial 終了後カード未登録で Stripe が paused にした (read-only)。 */
    case TrialEndedWithoutPaymentMethod = 'trial_ended_without_payment_method';

    /** Stripe status=paused (= 上記の確定状態)。 */
    case Paused = 'paused';

    /** 支払い失敗 (past_due) の猶予期限が切れた (起点は past_due_since / 期限は PaymentGracePolicy)。 */
    case PaymentGraceExpired = 'payment_grace_expired';
}
