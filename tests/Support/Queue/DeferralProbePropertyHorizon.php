<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/**
 * 負例 fixture: C1 — 期限を**プロパティ形**で持つ。
 *
 * framework の `Queue::getJobExpiration()` は `$job->retryUntil ?? $job->retryUntil()` を読むので
 * プロパティ形でも**動く**が、標準形は 1 つに固定する (2 形が並走すると雛形の読み手が
 * 選択を迫られる = 思考原則 3)。
 */
final class DeferralProbePropertyHorizon
{
    public ?DateTimeInterface $retryUntil = null;

    public int $maxExceptions = 3;
}
