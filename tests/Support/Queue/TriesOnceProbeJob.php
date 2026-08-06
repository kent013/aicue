<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * worker timeout 到達時の遷移検証用のプローブジョブ (tries=1)。
 *
 * ★ `app/` 配下ではないので QueuedJobLeaseInventoryTest の目録走査を汚さない。
 *   handle() は何もしない (検証対象は「失敗記録の有無」であって処理内容ではない)。
 */
final class TriesOnceProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 再試行しない = timeout 到達で即 failed になる側 */
    public int $tries = 1;

    public function handle(): void {}
}
