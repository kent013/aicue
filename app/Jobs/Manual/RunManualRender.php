<?php

declare(strict_types=1);

namespace App\Jobs\Manual;

use App\Enums\Manual\RenderErrorCode;
use App\Models\RenderJob;
use App\Services\Manual\RenderJobService;
use App\Services\Manual\RenderPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * レンダの queue job (薄い殻。本体は RenderPipeline)。
 *
 * - payload は renderJobId のみ (モデル/チケット/org 値を payload に持たない = payload 不信任)
 * - 専用 connection database-render (retry_after=1680) で流す。運用契約:
 *   本番/ステージングは `php artisan queue:work database-render` を worker 定義に必須登録
 *   (docs/architecture.md。滞留は recoverStale cron が queued=10 分 / running=30 分で failJob する)
 */
class RunManualRender implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 自動再試行しない (§10.8-1。再実行は render/preview 再トリガーのみ) */
    public int $tries = 1;

    /**
     * 25 分。尺上限ソフトゲート (manual.render_max_total_source_ms = 20 分) の実レンダが収まる値。
     * timeout (1,500) < retry_after (1,680) < 予約 TTL (1,800) の連鎖は
     * RenderTimeBudgetInvariantTest が CI 固定する。
     */
    public int $timeout = 1500;

    public function __construct(public readonly int $renderJobId)
    {
        // retry_after をレンダ専用値にした connection (config/queue.php)。既定 database は 90s のため。
        // Queueable trait が $connection プロパティを既に定義しているため onConnection() で指定する
        $this->onConnection('database-render');
    }

    public function handle(RenderPipeline $pipeline): void
    {
        $pipeline->run($this->renderJobId);
    }

    /**
     * catch を通らない失敗 (timeout kill 等) の最終防衛線。failJob は冪等。
     * error_code は既定 Internal とし、timeout と判別できる場合のみ Timeout
     * (deploy 中断・worker 停止を Timeout に誤分類すると運用判断を誤るため)。
     */
    public function failed(?Throwable $exception): void
    {
        $job = RenderJob::query()->find($this->renderJobId);
        if ($job === null) {
            return;
        }
        $code = $exception instanceof TimeoutExceededException
            ? RenderErrorCode::Timeout
            : RenderErrorCode::Internal;
        app(RenderJobService::class)->failJob($job, $code, '書き出しが中断されました。再実行してください。');
    }
}
