<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/**
 * 負例 fixture: C1 — 戻り型が `DateTimeInterface` でない。
 *
 * `getJobExpiration()` は DateTimeInterface でない値をそのまま payload へ通すため、
 * 戻り型を固定しないと「絶対時刻」の裁定が空洞化する。
 */
final class DeferralProbeTimestampHorizon
{
    public int $maxExceptions = 3;

    public function retryUntil(): int
    {
        return now()->addMinutes(30)->getTimestamp();
    }
}
