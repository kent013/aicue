<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/** 負例 fixture: C1 — 戻り型が nullable (期限なしへ倒れる口を残さない)。 */
final class DeferralProbeNullableHorizon
{
    public int $maxExceptions = 3;

    public function retryUntil(): ?DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
