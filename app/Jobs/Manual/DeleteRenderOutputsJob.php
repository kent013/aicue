<?php

declare(strict_types=1);

namespace App\Jobs\Manual;

use App\Models\RenderJob;
use App\Services\Manual\RenderJobService;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * 世代交代した render 出力の S3 削除 (payload は job id のみ = 任意キー削除の権限を持たない。
 * DeleteTakeObjectsJob は Take 概念の job のため流用しない = 「似ているからで統合しない」)。
 * media queue。冪等: output_path NULL / 最新 succeeded 自身 / prefix 不一致は no-op。
 */
class DeleteRenderOutputsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 削除は冪等のため再試行可 */
    public int $tries = 3;

    /** @var list<int> 再試行間隔 (秒) */
    public array $backoff = [60, 180];

    public function __construct(public readonly int $renderJobId)
    {
        // メディア掃除専用 connection (config/queue.php database-media)
        $this->onConnection('database-media');
    }

    /**
     * 外部 I/O (S3) は tx 外 (行ロック保持のまま S3 を呼ぶとロック/接続を長時間保持するため)。
     * 検証 (読み取りのみ) → S3 削除 → CAS NULL 化の 3 段。判定が古くなっても段 3 の CAS が守る。
     */
    public function handle(RenderObjectStorage $storage, RenderJobService $jobs): void
    {
        // 段 1: 検証 (ロックは取らず読むだけで良い)
        $job = RenderJob::query()->find($this->renderJobId);
        if ($job === null || $job->output_path === null) {
            return; // 冪等 (削除済み / NULL 化済み)
        }
        if (! $jobs->newerSucceededExists($job)) {
            return; // 最新 succeeded (世代交代していない) は削除しない
        }
        $manual = $job->videoManual;
        if ($manual === null || ! str_starts_with($job->output_path, $storage->keyPrefixFor($manual))) {
            Log::warning('render output prefix mismatch', [
                'render_job_id' => $job->id,
                'output_path' => $job->output_path,
            ]);

            return; // 期待 prefix 外は削除しない (過大削除の防止)
        }
        $pathToDelete = $job->output_path;

        // 段 2: S3 削除 (tx 外。存在しないキーは no-op = 冪等)
        $storage->delete($pathToDelete);

        // 段 3: CAS で NULL 化 (検証時の値と一致する行のみ。最新世代を誤って NULL 化しない。
        // ここで失敗/クラッシュしても再実行・reconcile が段 1-2 の冪等性で収束させる)
        RenderJob::query()->whereKey($job->id)
            ->where('output_path', $pathToDelete)
            ->update(['output_path' => null]);
    }
}
