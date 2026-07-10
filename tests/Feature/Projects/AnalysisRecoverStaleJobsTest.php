<?php

declare(strict_types=1);

use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisPipeline;
use Carbon\CarbonImmutable;

/*
 * stale 回復 cron (analysis:recover-stale-jobs。概念設計 §4):
 * - queued (dispatch 喪失) / running (worker 異常終了) の閾値超過 → failJob
 * - 閾値内・terminal は対象外
 * - 回収後の遅延配送は queued guard で no-op
 */

/**
 * @return array{Organization, VideoManual, AnalysisJob}
 */
function staleJobContext(string $status = 'queued'): array
{
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
    $job = AnalysisJob::factory()->forManual($manual)->create(['status' => $status]);

    return [$organization, $manual, $job];
}

test('queued が 31 分滞留 → failed + manual 復帰 + 予約 released', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [$organization, $manual, $job] = staleJobContext();
    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');
    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
    $job->ticketReservation()->associate($reservation);
    $job->save();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $this->artisan('analysis:recover-stale-jobs')
        ->expectsOutputToContain('recovered 1 stale analysis job(s)')
        ->assertSuccessful();

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe('解析がタイムアウトしました。再実行してください。');
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Draft);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
});

test('running の updated_at が 31 分古い → 回収される', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, $manual, $job] = staleJobContext('running');

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $this->artisan('analysis:recover-stale-jobs')->assertSuccessful();

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Draft);
});

test('閾値内の queued / running は回収されない', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, , $queued] = staleJobContext();
    [, , $running] = staleJobContext('running');

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:10:00'));
    $this->artisan('analysis:recover-stale-jobs')
        ->expectsOutputToContain('recovered 0 stale analysis job(s)')
        ->assertSuccessful();

    expect($queued->refresh()->status)->toBe(JobStatus::Queued);
    expect($running->refresh()->status)->toBe(JobStatus::Running);
});

test('terminal (succeeded / failed) は対象外', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, , $succeeded] = staleJobContext('succeeded');
    [, , $failed] = staleJobContext('failed');

    $this->travelTo(CarbonImmutable::parse('2026-07-11 01:00:00'));
    $this->artisan('analysis:recover-stale-jobs')
        ->expectsOutputToContain('recovered 0 stale analysis job(s)')
        ->assertSuccessful();

    expect($succeeded->refresh()->status)->toBe(JobStatus::Succeeded);
    expect($failed->refresh()->status)->toBe(JobStatus::Failed);
});

test('回収後の遅延配送は queued guard で no-op (二重実行しない)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, $manual, $job] = staleJobContext();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $this->artisan('analysis:recover-stale-jobs')->assertSuccessful();
    expect($job->refresh()->status)->toBe(JobStatus::Failed);

    // 遅延配送 (queue 詰まりが解けて後から届いた) → LLM 呼び出しなしで即 return
    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($manual->refresh()->cuts()->count())->toBe(0);
});
