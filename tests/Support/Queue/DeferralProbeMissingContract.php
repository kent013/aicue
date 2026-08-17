<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/** 負例 fixture: C1 (retryUntil() が無い) + C3 ($maxExceptions が無い)。 */
final class DeferralProbeMissingContract
{
    public function handle(): void {}
}
