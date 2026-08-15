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
 * - 専用 connection database-analysis (retry_after=1680) で流す。運用契約:
 *   本番/ステージングは `php artisan queue:work database-analysis` を worker 定義に必須登録
 *   (docs/architecture.md。滞留は work:recover-stuck --stream=analysis_job が 30 分で失敗確定する)
 */
class RunManualAnalysis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 自動再試行しない (§10.8-1。再実行は analyze 再トリガーのみ) */
    public int $tries = 1;

    /**
     * 時間 budget の worst-case (概念設計 §時間 budget の連鎖):
     *   deadline D (1,080s = 3 × client timeout) — AnalysisPipeline::run() 入口 (T0) を起点に
     *                                              各 LLM 試行の開始前に検査する
     *   + client timeout C (360s)                — deadline 直前に開始した 1 呼び出し分
     *   + finalize モデル予算 M₁ (30s)           — terminal tx + commit/release + 通知
     *   + 安全余白 S (90s)                       — P (worker が alarm を張ってから run() 入口
     *                                              = payload 復元/handler 解決/DI)
     *                                              + タイマー精度 + シグナル配送 + ログ
     *   = 1,560s
     * モデル上限 D + C + M₁ = 1,470s に対し 90 秒の明示的余白がある。
     * timeout (1,560) < retry_after (1,680) < 予約 TTL (1,800) ≤ stale 閾値 (1,800) の連鎖は
     * AnalysisTimeBudgetInvariantTest が CI 固定する。
     *
     * NOTE: 「3 段 × 3 試行 × timeout」という積のモデルは廃止した (リトライは deadline で
     *       打ち切るため、worst-case は積ではなく D + C になる)。
     */
    public int $timeout = 1560;

    public function __construct(public readonly int $analysisJobId)
    {
        // retry_after を解析専用値にした connection (config/queue.php)。既定 database は 360s のため (T126)。
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
