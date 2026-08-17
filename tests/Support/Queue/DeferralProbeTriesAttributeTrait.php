<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Queue\Attributes\Tries;

/** 負例 fixture の trait: `#[Tries]` を trait 経由で持ち込む。 */
#[Tries(5)]
trait DeferralProbeTriesAttributeTrait {}
