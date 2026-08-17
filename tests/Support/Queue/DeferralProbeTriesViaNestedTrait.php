<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/** 負例 fixture: C2 — `#[Tries]` を **trait の trait** 経由で持つ。 */
final class DeferralProbeTriesViaNestedTrait
{
    use DeferralProbeTriesOuterAttributeTrait;

    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
