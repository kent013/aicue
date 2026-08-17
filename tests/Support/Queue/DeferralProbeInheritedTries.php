<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/** 負例 fixture: C2 — `$tries` を**親から継承**している (自ファイルには現れない)。 */
final class DeferralProbeInheritedTries extends DeferralProbeTriesBase
{
    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
