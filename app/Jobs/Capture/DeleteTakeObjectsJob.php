<?php

declare(strict_types=1);

namespace App\Jobs\Capture;

use App\Services\Capture\TakeObjectStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * S3 オブジェクト削除 (media queue。doc/10 §10.8-4)。payload は S3 キーの list のみ
 * (モデル/org 値を持たない = payload 不信任。RunManualAnalysis と同じ規約)。
 * 冪等: 既に無いキーの削除は no-op。
 */
class DeleteTakeObjectsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 削除は冪等のため再試行可 */
    public int $tries = 3;

    /** @var list<int> 再試行間隔 (秒) */
    public array $backoff = [60, 180];

    /**
     * @param  list<string>  $paths
     */
    public function __construct(public readonly array $paths)
    {
        // メディア掃除専用 connection (config/queue.php database-media)
        $this->onConnection('database-media');
    }

    public function handle(TakeObjectStorage $storage): void
    {
        foreach ($this->paths as $path) {
            $storage->delete($path);
        }
    }
}
