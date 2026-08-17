<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/**
 * マーカー正例 fixture (推移閉包の内側)。**この trait だけが退避を呼ぶ**。
 *
 * 走査根が「クラス自身が直接 use する trait」までしか辿らない実装では、
 * この trait のファイルが走査根に入らず取りこぼす。
 */
trait DeferralProbeInnerReleasingTrait
{
    public function handle(): void
    {
        $this
            ->release(60);
    }
}
