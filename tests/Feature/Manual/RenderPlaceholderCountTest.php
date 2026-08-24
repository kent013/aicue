<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\VideoManualStatus;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\RenderJobService;
use App\Services\Manual\RenderPipeline;
use App\Services\Render\VideoComposer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * 事後説明 render_jobs.placeholder_cut_count (T148 / bug-hunt F-1-01)。
 *
 * 「生成された動画に黒背景の区間が何カット分あったか」を**生成物の説明**として記録する。
 * 値契約: 既存行 / queued / running / failed = null、succeeded preview = 実数、
 * succeeded render = 0。**null を 0 と同一視しない / 現在状態から再計算しない**。
 */

/** 本ファイル専用の fake composer (実 ffmpeg に触れない。RenderPipelineTest とは別クラス) */
final class PlaceholderCountComposer implements VideoComposer
{
    public ?RenderManifest $lastManifest = null;

    /** compose 中 (buildManifest 後・finalize 前) に呼ばれる hook。状態変化のインターリーブ細工用 */
    public ?Closure $duringCompose = null;

    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
    {
        $this->lastManifest = $manifest;
        if ($this->duringCompose !== null) {
            ($this->duringCompose)($manifest);
        }
        $durations = [];
        foreach ($manifest->clips as $index => $clip) {
            $durations[$clip->cutId] = 1_000;
            $onClipComposed($index + 1, count($manifest->clips));
        }
        $localPath = "{$workDir}/output.mp4";
        file_put_contents($localPath, 'fake-mp4');

        return new ComposedLocalVideo($localPath, $durations, (int) array_sum($durations));
    }
}

/**
 * ready manual + 採用済み ready テイク付き step 1 枚 + fake composer。
 *
 * @return array{Organization, User, Project, VideoManual, Cut, PlaceholderCountComposer}
 */
function placeholderCountContext(int $tickets = 3): array
{
    Queue::fake();
    Storage::fake('s3');
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 2,
    ]);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();
    Storage::disk('s3')->put($take->video_path, 'fake-take-video');
    if ($tickets > 0) {
        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
    }

    $fake = new PlaceholderCountComposer;
    app()->instance(VideoComposer::class, $fake);

    return [$organization, $owner, $project, $manual, $cut, $fake];
}

test('B-1: succeeded な preview に生成時のプレースホルダ件数が記録される', function (): void {
    [, , $project, $manual] = placeholderCountContext(tickets: 0);
    // 4 カット中 3 カットが未充足 (1 枚目のみ採用済み ready)
    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    Cut::factory()->forManual($manual)->withSortOrder(2)->create();
    Cut::factory()->forManual($manual)->withSortOrder(3)->create();

    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
    app(RenderPipeline::class)->run($previewJob->id);

    $previewJob->refresh();
    expect($previewJob->status)->toBe(JobStatus::Succeeded);
    expect($previewJob->placeholder_cut_count)->toBe(3);
});

test('B-2: succeeded な render の placeholder_cut_count は 0 になる', function (): void {
    [, , $project, $manual] = placeholderCountContext();

    $job = app(RenderJobService::class)->trigger($project, $manual);
    app(RenderPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    expect($job->placeholder_cut_count)->toBe(0);
});

test('B-3: queued / running / failed の placeholder_cut_count は null のまま', function (): void {
    [, , , $manual] = placeholderCountContext(tickets: 0);

    $queued = RenderJob::factory()->forManual($manual)->preview()->create();
    $running = RenderJob::factory()->forManual($manual)->preview()->running()->create();
    $failed = RenderJob::factory()->forManual($manual)->preview()
        ->failed(RenderErrorCode::Internal)->create();

    expect($queued->refresh()->placeholder_cut_count)->toBeNull();
    expect($running->refresh()->placeholder_cut_count)->toBeNull();
    expect($failed->refresh()->placeholder_cut_count)->toBeNull();
});

test('B-4: プレビュー生成後にテイクを採用しても記録済み件数は変わらない', function (): void {
    [, , $project, $manual] = placeholderCountContext(tickets: 0);
    $missing = Cut::factory()->forManual($manual)->withSortOrder(1)->create();

    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
    app(RenderPipeline::class)->run($previewJob->id);
    expect($previewJob->refresh()->placeholder_cut_count)->toBe(1);

    // 生成後に採用しても「生成物の説明」は書き換わらない (再計算禁止)
    $take = Take::factory()->forCut($missing)->create(['duration_ms' => 1_000]);
    $missing->forceFill(['adopted_take_id' => $take->id])->save();

    expect($previewJob->refresh()->placeholder_cut_count)->toBe(1);
});

test('B-4b: 合成中に採用しても記録されるのは manifest 時点の件数である (finalize での再計算禁止)', function (): void {
    [, , $project, $manual, , $fake] = placeholderCountContext(tickets: 0);
    $missingA = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    Cut::factory()->forManual($manual)->withSortOrder(2)->create();

    // buildManifest (件数確定) の**後**・finalize の**前**に採用してしまう。
    // manifest 由来なら 2、finalize 時点の現在状態から数え直すと 1 になる。
    $fake->duringCompose = function () use ($missingA): void {
        $take = Take::factory()->forCut($missingA)->create(['duration_ms' => 1_000]);
        $missingA->forceFill(['adopted_take_id' => $take->id])->save();
    };

    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
    app(RenderPipeline::class)->run($previewJob->id);

    expect($previewJob->refresh()->status)->toBe(JobStatus::Succeeded);
    expect($previewJob->placeholder_cut_count)->toBe(2);
});

test('B-5: ポーリング応答と詳細画面 props に placeholder_cut_count が載る', function (): void {
    [$organization, $owner, $project, $manual] = placeholderCountContext(tickets: 0);
    Cut::factory()->forManual($manual)->withSortOrder(1)->create();

    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
    app(RenderPipeline::class)->run($previewJob->id);

    $this->actingAs($owner)->getJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$previewJob->id}",
    )->assertOk()->assertJson(['placeholder_cut_count' => 1]);

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('render.playbackJob.id', $previewJob->id)
            ->where('render.playbackJob.placeholder_cut_count', 1));
});

test('B-6: 本変更以前からの succeeded 行 (legacy) は null のままで backfill しない', function (): void {
    [$organization, $owner, $project, $manual] = placeholderCountContext(tickets: 0);
    $legacy = RenderJob::factory()->forManual($manual)->preview()
        ->legacySucceeded("projects/{$project->id}/manuals/{$manual->id}/previews/v2-1.mp4")
        ->create();

    expect($legacy->refresh()->placeholder_cut_count)->toBeNull();

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('render.playbackJob.placeholder_cut_count', null));
});
