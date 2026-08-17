<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Queue\InteractsWithQueue;

/**
 * マーカー**負例** fixture: `InteractsWithQueue` を使うが `release()` を呼ばないジョブ。
 *
 * 走査根には vendor の `Illuminate\Queue\InteractsWithQueue` が入り、その trait は
 * `release()` の**宣言本体**に `return $this->job->release($delay);` を持つ。
 * 「退避できる能力の定義」を使用サイトに数えてしまう実装だと、この fixture が赤くなる
 * (= すべてのジョブが退避ありと判定される修正不能な偽レッド)。
 */
final class DeferralProbeInteractsOnly
{
    use InteractsWithQueue;

    public function handle(): void
    {
        // 何もしない (退避しない)。
    }
}
