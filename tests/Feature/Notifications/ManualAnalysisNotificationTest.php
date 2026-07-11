<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\AnalysisPipeline;
use App\Services\Notification\NotificationCenterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;

/*
 * 解析ジョブ terminal 遷移の通知配線 (施策3/4):
 * - 成功 (pipeline finalize true) → creator ∪ triggeredBy に各 1 件 (succeeded=true)
 * - creator = triggeredBy は dedup で 1 件のみ
 * - 失敗 (failJob true) → 1 件 (succeeded=false)。failJob 2 回目 no-op で二重発火しない
 * - recoverStale 経由の失敗も通知される
 * - 退会済み (org 非所属) creator へは送らない / manual 削除競合は通知スキップ (例外なし)
 */

beforeEach(function (): void {
    // executeSync は fake 中も PromptExecutionCompleted を発火し listener が FX 解決 (HTTP) を試みる
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
});

/**
 * queued 解析 job 一式 (creator = org member。triggered_by は $triggeredBy)。
 *
 * @return array{Organization, User, Project, VideoManual, AnalysisJob}
 */
function analysisNotificationContext(?User $creator = null, ?User $triggeredBy = null): array
{
    Storage::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $creator ??= $owner;
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->createdBy($creator)->create([
        'status' => 'analyzing',
        'title' => '通知テスト手順書',
    ]);
    $path = "projects/{$project->id}/manuals/{$manual->id}/source-documents/sop.txt";
    Storage::put($path, str_repeat("手順: 部品を取り付けてネジを締める。急所: トルクは 5Nm。\n", 5));
    $document = SourceDocument::factory()->forManual($manual)->create([
        'file_path' => $path,
        'mime' => 'text/plain',
    ]);
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create([
        'triggered_by' => $triggeredBy?->id,
    ]);
    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');

    return [$organization, $owner, $project, $manual, $job];
}

/** 成功 3 段の Prompt fake */
function fakeAnalysisLlmSuccess(): void
{
    Prompt::fake([
        TextResponseFake::make()->withText(json_encode([
            'header' => ['title' => 'SOP', 'department' => null, 'revision' => null],
            'sections' => [[
                'title' => null,
                'steps' => [[
                    'no' => 1, 'work_process' => 'ネジを締める', 'work_points' => [],
                    'safety_points' => [], 'quality_points' => [], 'pm_points' => [],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        TextResponseFake::make()->withText(json_encode([
            'steps' => [['no' => 1, 'action' => 'ネジを締める', 'points' => []]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        TextResponseFake::make()->withText(json_encode([
            'cuts' => [[
                'no' => 1, 'type' => 'step', 'parent_no' => null,
                'scene' => 'ネジ締め', 'shot_type' => 'hiki', 'shooting_point' => null,
                'narration' => 'ネジを締めます', 'subtitle_primary' => null, 'subtitle_secondary' => null,
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    ]);
}

test('解析成功 → creator と triggeredBy に各 1 件 (succeeded=true・org/manual スナップショット)', function (): void {
    [$organization, $owner, $project, $manual, $job] = analysisNotificationContext();
    $editor = attachOrganizationMember($organization);
    $job->forceFill(['triggered_by' => $editor->id])->save();

    fakeAnalysisLlmSuccess();
    app(AnalysisPipeline::class)->run($job->id);
    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);

    // creator (owner) と triggeredBy (editor) に各 1 件
    foreach ([$owner, $editor] as $recipient) {
        $rows = DB::table('notifications')
            ->where('notifiable_id', $recipient->id)
            ->where('type', 'manual_analyzed')
            ->get();
        expect($rows)->toHaveCount(1);
        $data = json_decode((string) $rows[0]->data, true);
        expect($data['succeeded'])->toBeTrue();
        expect($data['manual_title'])->toBe('通知テスト手順書');
        expect($data['project_id'])->toBe($project->id);
        expect($data['manual_id'])->toBe($manual->id);
        expect((int) $rows[0]->organization_id)->toBe($organization->id);
    }
    expect(DB::table('notifications')->count())->toBe(2);
});

test('creator = triggeredBy のとき通知は 1 件のみ (dedup)', function (): void {
    [, $owner, , , $job] = analysisNotificationContext();
    $job->forceFill(['triggered_by' => $owner->id])->save();

    fakeAnalysisLlmSuccess();
    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
    expect(DB::table('notifications')->count())->toBe(1);
    expect((int) DB::table('notifications')->firstOrFail()->notifiable_id)->toBe($owner->id);
});

test('解析失敗 (failJob) → 1 件 (succeeded=false + error 文言)。2 回目 no-op で二重発火しない', function (): void {
    [, $owner, , , $job] = analysisNotificationContext();
    $job->forceFill(['status' => JobStatus::Running->value])->save();

    $failed = app(AnalysisJobService::class)->failJob($job, '解析に失敗しました。時間をおいて再実行してください。');
    expect($failed)->toBeTrue();

    $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
    expect($rows)->toHaveCount(1);
    $data = json_decode((string) $rows[0]->data, true);
    expect($data['succeeded'])->toBeFalse();
    expect($data['error'])->toBe('解析に失敗しました。時間をおいて再実行してください。');

    // terminal 済み no-op (false) は通知しない = 二重発火なし
    expect(app(AnalysisJobService::class)->failJob($job->refresh(), '二重'))->toBeFalse();
    expect(DB::table('notifications')->count())->toBe(1);
});

test('recoverStale 経由の失敗も通知が 1 件発火する', function (): void {
    [, $owner, , , $job] = analysisNotificationContext();
    $job->forceFill(['status' => JobStatus::Running->value])->save();
    // stale 閾値超過に細工 (updated_at を過去へ)
    DB::table('analysis_jobs')->where('id', $job->id)
        ->update(['updated_at' => now()->subHours(2)]);

    expect(app(AnalysisJobService::class)->recoverStale())->toBe(1);

    $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
    expect($rows)->toHaveCount(1);
    expect(json_decode((string) $rows[0]->data, true)['succeeded'])->toBeFalse();
});

test('退会済み (org 非所属) creator へは通知しない', function (): void {
    $outsider = User::factory()->create(); // org に attach しない = 退会相当
    [, , , , $job] = analysisNotificationContext(creator: $outsider);
    $job->forceFill(['status' => JobStatus::Running->value])->save();

    app(AnalysisJobService::class)->failJob($job, '失敗');

    expect(DB::table('notifications')->count())->toBe(0);
});

test('manual 削除競合は通知スキップ (例外にしない)', function (): void {
    [, , , $manual, $job] = analysisNotificationContext();
    $job->forceFill(['status' => JobStatus::Failed->value, 'error' => '失敗'])->save();
    $manual->delete(); // terminal 遷移と通知の間に manual が消えた競合

    app(NotificationCenterService::class)->notifyAnalysisFinished($job);

    expect(DB::table('notifications')->count())->toBe(0);
});

test('trigger に actor を渡すと triggered_by が記録される (web 経路の配線)', function (): void {
    Queue::fake();
    Storage::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->createdBy($owner)->create(['status' => 'draft']);
    SourceDocument::factory()->forManual($manual)->create();
    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');

    $job = app(AnalysisJobService::class)->trigger($project, $manual, $owner);

    expect($job->triggered_by)->toBe($owner->id);
});
