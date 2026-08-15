<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 滞留した webhook 記録に対して回収が行った処置。
 *
 * `Skipped` を持たない理由: 「受理条件を満たさなかった」場合は
 * `StripeWebhookProcessor::claimStale()` が `null` を返す (行が消えていた場合も同じ)。
 * 「何もしなかった」を DTO の 1 case としても持つと表現が 2 通りになるため、
 * **処置をしたときだけ DTO を作る**。
 */
enum WebhookStaleClaimOutcome: string
{
    /** 再実行のために受理した (attempts を 1 進めた)。 */
    case ClaimedForReplay = 'claimed_for_replay';

    /** 自動再実行の対象外と判定して回収待ちへ置いた。 */
    case MovedToRecoveryPending = 'moved_to_recovery_pending';
}
