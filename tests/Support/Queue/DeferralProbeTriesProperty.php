<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/** 負例 fixture: C2 — `$tries` をプロパティで宣言している。 */
final class DeferralProbeTriesProperty
{
    public int $tries = 5;

    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
