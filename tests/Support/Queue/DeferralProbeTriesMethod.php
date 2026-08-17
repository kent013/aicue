<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/**
 * 負例 fixture: C2 — `tries()` **メソッド形**。
 *
 * `Queue::getJobTries()` は `if (method_exists($job, 'tries')) { $tries = $job->tries(); }` を持つので、
 * プロパティと属性だけを見るとこの形が素通りする。
 */
final class DeferralProbeTriesMethod
{
    public int $maxExceptions = 3;

    public function tries(): int
    {
        return 5;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
