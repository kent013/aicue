<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/**
 * 負例 fixture: C2 — `#[Tries]` を **trait 経由**で持つ。
 *
 * `ReadsClassAttributes::getAttributeInstance()` は**クラス → trait → 親クラス**の順に
 * 遡って解決するので、検査側も同じ範囲を見ないと素通りする。
 */
final class DeferralProbeTriesViaTrait
{
    use DeferralProbeTriesAttributeTrait;

    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
