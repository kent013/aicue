<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/** 負例 fixture の親: `$tries` を継承させるためだけの基底。 */
abstract class DeferralProbeTriesBase
{
    public int $tries = 7;
}
