<?php

declare(strict_types=1);

namespace App\Jobs\Capture;

use App\Services\Capture\TakeThumbnailPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * テイクのサムネイル生成 (薄い殻。本体は TakeThumbnailPipeline)。
 *
 * - payload は takeId のみ (モデル/org 値を payload に持たない = payload 不信任)
 * - media queue (`database-media`。queue=media / retry_after=300) で流す。
 *   運用契約: 本番/ステージングは `php artisan queue:work database-media --timeout=240` を
 *   worker 定義に必須登録 (docs/architecture.md §撮影 PWA。既存の削除ジョブと同じ worker)
 * - 時間予算の連鎖: ffmpeg 60 < $timeout 180 < worker --timeout 240 < retry_after 300
 * - **失敗しても take は ready のまま**である (サムネイルは採用・レンダの必須条件ではない)。
 *   最終失敗は failed_jobs に残るだけで、UI はプレースホルダへ degrade する
 */
class GenerateTakeThumbnailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** S3 / ffmpeg の一過性障害を吸収する (生成は冪等なので再試行して安全) */
    public int $tries = 3;

    /** @var list<int> 再試行間隔 (秒) */
    public array $backoff = [60, 180];

    /** worker の --timeout=240 より短く取り、強制終了より先に自前の finally へ入る余地を残す */
    public int $timeout = 180;

    public function __construct(public readonly int $takeId)
    {
        // メディア処理専用 connection (config/queue.php database-media)
        $this->onConnection('database-media');
    }

    public function handle(TakeThumbnailPipeline $pipeline): void
    {
        $pipeline->run($this->takeId);
    }
}
