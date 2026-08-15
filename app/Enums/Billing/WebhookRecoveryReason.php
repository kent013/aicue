<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 滞留した webhook 記録を**回収待ちへ置いた理由**。
 *
 * 状態 (`WebhookEventStatus::RecoveryPending`) が「次にどう扱うか (自動では動かさない)」を、
 * 本 enum が「なぜそこに置かれたか」を表す。運用の次の行動が理由ごとに違うため、
 * 自由文の `failure_reason` とは列を分ける (機械判定できる値と混ぜない)。
 *
 * **不変条件**: `recovery_reason IS NOT NULL` ⟺ `status = recovery_pending`。
 */
enum WebhookRecoveryReason: string
{
    /** 順序に依存する種類なので再実行しない (再実行すると契約状態が巻き戻る)。 */
    case OrderSensitive = 'order_sensitive';

    /** 試行上限 (StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS) に到達済み。 */
    case AttemptsExhausted = 'attempts_exhausted';

    public function label(): string
    {
        return match ($this) {
            self::OrderSensitive => '順序に依存するため再実行しない',
            self::AttemptsExhausted => '試行上限に到達',
        };
    }
}
