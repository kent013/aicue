<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/** 負例 fixture: C3 — `$maxExceptions = 0` (値域違反。0 は「数えない」と同義)。 */
final class DeferralProbeZeroMaxExceptions
{
    public int $maxExceptions = 0;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
