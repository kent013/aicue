<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\VideoManualStatus;
use App\Models\AnalysisJob;
use App\Models\RenderJob;
use App\Models\VideoManual;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\RenderJobService;

/*
 * T032 施策2: failJob が失敗確定時の manual.scenario_version を job にスナップショットする。
 * この snapshot が stale alert 判定 (VideoManualService::displayXxxJob) の順序基準になる。
 */

test('AnalysisJobService::failJob は失敗確定時の scenario_version を snapshot する', function (): void {
    $manual = VideoManual::factory()->create([
        'status' => VideoManualStatus::Analyzing->value,
        'scenario_version' => 3,
    ]);
    $job = AnalysisJob::factory()->forManual($manual)->running()->create();

    $result = app(AnalysisJobService::class)->failJob($job, '解析に失敗しました');

    expect($result)->toBeTrue();
    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->scenario_version_at_terminal)->toBe(3);
});

test('RenderJobService::failJob (preview) は snapshot を記録し manual.status を触らない', function (): void {
    $manual = VideoManual::factory()->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 5,
    ]);
    $job = RenderJob::factory()->forManual($manual)->preview()->running()->create();

    $result = app(RenderJobService::class)->failJob($job, RenderErrorCode::Internal, '失敗');

    expect($result)->toBeTrue();
    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->scenario_version_at_terminal)->toBe(5);
    // preview 失敗では manual を lock 取得するようになるが status は不変 (編集と並走)
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
});

test('RenderJobService::failJob (render) は snapshot を記録し rendering→ready へ復帰する', function (): void {
    $manual = VideoManual::factory()->create([
        'status' => VideoManualStatus::Rendering->value,
        'scenario_version' => 2,
    ]);
    $job = RenderJob::factory()->forManual($manual)->running()->create(['scenario_version' => 2]);

    app(RenderJobService::class)->failJob($job, RenderErrorCode::Internal, '失敗');

    $job->refresh();
    expect($job->scenario_version_at_terminal)->toBe(2);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
});

test('terminal 済み job への再 failJob は no-op (snapshot 不変)', function (): void {
    $manual = VideoManual::factory()->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 4,
    ]);
    // 既に失敗確定 (snapshot=1 の旧世代) の job。scenario_version はその後 4 まで進んでいる
    $job = AnalysisJob::factory()->forManual($manual)->failed()->create([
        'scenario_version_at_terminal' => 1,
    ]);

    $result = app(AnalysisJobService::class)->failJob($job, '再失敗');

    expect($result)->toBeFalse();
    // terminal guard で早期 return: snapshot も status も上書きされない
    expect($job->refresh()->scenario_version_at_terminal)->toBe(1);
});
