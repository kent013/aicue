<?php

declare(strict_types=1);

namespace App\Jobs\Manual;

use App\Models\AnalysisJob;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\AnalysisPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * AI 解析の queue job (薄い殻。本体は AnalysisPipeline)。
 *
 * - payload は analysisJobId のみ (モデル/チケット/org 値を payload に持たない = payload 不信任)
 * - 専用 connection database-analysis (retry_after=1560) で流す。運用契約:
 *   本番/ステージングは `php artisan queue:work database-analysis` を worker 定義に必須登録
 *   (docs/architecture.md。滞留は recoverStale cron が 30 分で failJob する)
 */
class RunManualAnalysis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 自動再試行しない (§10.8-1。再実行は analyze 再トリガーのみ) */
    public int $tries = 1;

    /**
     * worst-case (LLM 3 段 × 3 試行 × client timeout 120s = 1,080s) + 抽出/解析余裕 180s + マージン。
     * timeout (1,380) < retry_after (1,560) < 予約 TTL (1,800) の連鎖は
     * AnalysisTimeBudgetInvariantTest が CI 固定する。
     */
    public int $timeout = 1380;

    public function __construct(public readonly int $analysisJobId)
    {
        // retry_after を解析専用値にした connection (config/queue.php)。既定 database は 90s のため。
        // Queueable trait が $connection プロパティを既に定義しているため、プロパティ再宣言でなく
        // onConnection() で指定する (typed 再宣言は trait composition エラーになる)
        $this->onConnection('database-analysis');
    }

    public function handle(AnalysisPipeline $pipeline): void
    {
        $pipeline->run($this->analysisJobId);
    }

    /** catch を通らない失敗 (timeout kill 等) の最終防衛線。failJob は冪等 */
    public function failed(?Throwable $exception): void
    {
        $job = AnalysisJob::query()->find($this->analysisJobId);
        if ($job !== null) {
            app(AnalysisJobService::class)->failJob($job, '解析が中断されました。再実行してください。');
        }
    }
}
