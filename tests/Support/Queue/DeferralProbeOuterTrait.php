<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Queue\InteractsWithQueue;

/** マーカー正例 fixture (推移閉包の中間層)。退避そのものは内側の trait が持つ。 */
trait DeferralProbeOuterTrait
{
    use DeferralProbeInnerReleasingTrait;
    use InteractsWithQueue;
}
