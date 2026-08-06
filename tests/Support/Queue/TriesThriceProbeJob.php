<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * worker timeout 到達時の遷移検証用のプローブジョブ (tries=3)。
 *
 * ★ `app/` 配下ではないので QueuedJobLeaseInventoryTest の目録走査を汚さない。
 *   timeout で kill されても failed にならず、予約 (reserved_at) が残る側を再現する。
 */
final class TriesThriceProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 再試行あり = timeout 到達では failed にならず retry_after 経過まで予約が残る側 */
    public int $tries = 3;

    public function handle(): void {}
}
