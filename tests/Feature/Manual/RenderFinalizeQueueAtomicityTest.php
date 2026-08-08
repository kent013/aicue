<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Jobs\Manual\DeleteRenderOutputsJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\RenderJobService;
use App\Services\Manual\RenderPipeline;
use App\Services\Render\VideoComposer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
|--------------------------------------------------------------------------
| キュー投入の原子性 (RenderPipeline::finalize の世代交代削除。AG-114 確定 1)
|--------------------------------------------------------------------------
|
| 主契約は tx level 観測 (baseline + 1 以上)。`Queue::fake()` は使わない
| (QueueFake::push は enqueueUsing を通らず原子性を観測できない)。
| 削除 job は冪等のため重複無害で、喪失時の回収役 (render:reconcile-outputs) は
| 別要因 (worker 異常終了) のために残す。
*/

/** finalize テスト専用の fake composer (実 ffmpeg に触れない)。 */
final class FinalizeAtomicityComposer implements VideoComposer
{
    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
    {
        $durations = [];
        foreach ($manifest->clips as $index => $clip) {
            $durations[$clip->cutId] = 1_000 * ($index + 1);
            $onClipComposed($index + 1, count($manifest->clips));
        }
        $localPath = "{$workDir}/output.mp4";
        file_put_contents($localPath, 'fake-mp4');

        return new ComposedLocalVideo($localPath, $durations, (int) array_sum($durations));
    }
}

/**
 * 世代交代を起こせる render 一式 (1 世代目 succeeded 済み・2 世代目 trigger 済み)。
 *
 * @return array{Project, VideoManual, RenderJob, RenderJob}
 */
function finalizeAtomicityContext(): array
{
    Storage::fake('s3');
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 2,
    ]);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();
    Storage::disk('s3')->put($take->video_path, 'fake-take-video');
    app(TicketLedgerService::class)->grant($organization, 6, 'テスト残高');
    app()->instance(VideoComposer::class, new FinalizeAtomicityComposer);

    $first = app(RenderJobService::class)->trigger($project, $manual);
    app(RenderPipeline::class)->run($first->id);
    expect($first->refresh()->status)->toBe(JobStatus::Succeeded);

    $manual->refresh()->forceFill([
        'status' => VideoManualStatus::Ready,
        'scenario_version' => 3,
    ])->save();
    $second = app(RenderJobService::class)->trigger($project, $manual);

    return [$project, $manual, $first, $second];
}

test('finalize の DeleteRenderOutputsJob は terminal tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database-media.after_commit'))->toBeFalse();

    [, , $first, $second] = finalizeAtomicityContext();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(RenderPipeline::class)->run($second->id),
    );
    expect($second->refresh()->status)->toBe(JobStatus::Succeeded);

    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), DeleteRenderOutputsJob::class);
    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
    expect($first->refresh()->status)->toBe(JobStatus::Succeeded);
});
