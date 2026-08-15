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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * レンダジョブ terminal 遷移の通知配線 (施策3/4):
 * - render 成功 (pipeline finalize true) / 失敗 (failJob true) → 1 件
 * - preview は成功/失敗とも通知 0
 * - failJob 2 回目 no-op で二重発火しない / 滞留回収経由の失敗通知
 */

/** テスト用 fake composer (実 ffmpeg に触れない) */
final class NotificationFakeRenderComposer implements VideoComposer
{
    public ?Throwable $throws = null;

    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }
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
 * ready manual (採用済みテイク) + 残高 + fake composer 一式。creator = owner。
 *
 * @return array{Organization, User, Project, VideoManual, NotificationFakeRenderComposer}
 */
function renderNotificationContext(): array
{
    Queue::fake();
    Storage::fake('s3');
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->createdBy($owner)->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 2,
        'title' => '通知テスト動画',
    ]);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();
    Storage::disk('s3')->put($take->video_path, 'fake-take-video');
    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');

    $fake = new NotificationFakeRenderComposer;
    app()->instance(VideoComposer::class, $fake);

    return [$organization, $owner, $project, $manual, $fake];
}

test('render 成功 → creator と triggeredBy に各 1 件 (succeeded=true)', function (): void {
    [$organization, $owner, $project, $manual] = renderNotificationContext();
    $editor = attachOrganizationMember($organization);

    $job = app(RenderJobService::class)->trigger($project, $manual, $editor);
    app(RenderPipeline::class)->run($job->id);
    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);

    foreach ([$owner, $editor] as $recipient) {
        $rows = DB::table('notifications')
            ->where('notifiable_id', $recipient->id)
            ->where('type', 'manual_rendered')
            ->get();
        expect($rows)->toHaveCount(1);
        $data = json_decode((string) $rows[0]->data, true);
        expect($data['succeeded'])->toBeTrue();
        expect($data['manual_title'])->toBe('通知テスト動画');
        expect((int) $rows[0]->organization_id)->toBe($organization->id);
    }
    expect(DB::table('notifications')->count())->toBe(2);
});

test('render 失敗 (failJob) → 1 件 (succeeded=false)。2 回目 no-op で二重発火しない', function (): void {
    [, $owner, $project, $manual] = renderNotificationContext();
    $job = app(RenderJobService::class)->trigger($project, $manual, $owner);

    $failed = app(RenderJobService::class)->failJob($job, RenderErrorCode::Internal, '書き出しに失敗しました。');
    expect($failed)->toBeTrue();

    $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
    expect($rows)->toHaveCount(1);
    $data = json_decode((string) $rows[0]->data, true);
    expect($data['succeeded'])->toBeFalse();
    expect($data['error'])->toBe('書き出しに失敗しました。');

    expect(app(RenderJobService::class)->failJob($job->refresh(), RenderErrorCode::Internal, '二重'))->toBeFalse();
    expect(DB::table('notifications')->count())->toBe(1);
});

test('preview は成功/失敗とも通知 0', function (): void {
    [, $owner, $project, $manual, $fake] = renderNotificationContext();

    // 成功 preview
    $preview = app(RenderJobService::class)->triggerPreview($project, $manual, $owner);
    app(RenderPipeline::class)->run($preview->id);
    expect($preview->refresh()->status)->toBe(JobStatus::Succeeded);
    expect(DB::table('notifications')->count())->toBe(0);

    // 失敗 preview (failJob 直呼び)
    $failing = app(RenderJobService::class)->triggerPreview($project, $manual, $owner);
    expect(app(RenderJobService::class)->failJob($failing, RenderErrorCode::Internal, '失敗'))->toBeTrue();
    expect(DB::table('notifications')->count())->toBe(0);
});

test('滞留回収経由の render 失敗も通知される', function (): void {
    [, $owner, $project, $manual] = renderNotificationContext();
    $job = app(RenderJobService::class)->trigger($project, $manual, $owner);
    DB::table('render_jobs')->where('id', $job->id)->update([
        'status' => JobStatus::Running->value,
        'updated_at' => now()->subHours(2),
    ]);

    expect(recoverStaleRenderJobs())->toBe(1);

    $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
    expect($rows)->toHaveCount(1);
    expect(json_decode((string) $rows[0]->data, true)['succeeded'])->toBeFalse();
});

test('stale 先勝ちで finalize が false のとき成功通知は発火しない (失敗通知のみ)', function (): void {
    [, $owner, $project, $manual, $fake] = renderNotificationContext();
    $job = app(RenderJobService::class)->trigger($project, $manual, $owner);
    // compose 中に stale 回復 cron が先勝ちした状況を細工 (failJob が先に terminal 化)
    $fake->throws = null;
    app(RenderJobService::class)->failJob($job, RenderErrorCode::Timeout, 'タイムアウト');
    expect(DB::table('notifications')->count())->toBe(1); // 失敗通知

    // 遅延実行された pipeline は queued guard で no-op → 通知は増えない
    app(RenderPipeline::class)->run($job->id);
    expect(DB::table('notifications')->count())->toBe(1);
    expect(RenderJob::query()->findOrFail($job->id)->status)->toBe(JobStatus::Failed);
});
