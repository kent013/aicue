<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 振る舞い probe: **退避 (release) を正常系として行う**ジョブ (B0 / B2 / B3 用)。
 *
 * 標準形 v1 (retryUntil + $maxExceptions) を満たす。雛形 `DeferringJobTemplate` に
 * release を書かないのは、配布物に「何もしない handle()」以上のものを入れないためである。
 */
final class DeferringReleaseProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $maxExceptions = 3;

    private const HORIZON_MINUTES = 30;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::HORIZON_MINUTES);
    }

    public function handle(): void
    {
        // 順番待ちのためにキューへ戻す (例外は投げない = 正常系の退避)。
        $this->release(0);
    }
}
