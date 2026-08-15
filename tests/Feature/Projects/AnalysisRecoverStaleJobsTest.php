<?php

declare(strict_types=1);

use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\Recovery\RecoveryOutcome;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisPipeline;
use App\Services\Recovery\Streams\StaleAnalysisJobStream;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * stale 回復の定期実行 (work:recover-stuck --stream=analysis_job --apply。概念設計 §4):
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
    $this->artisan('work:recover-stuck --stream=analysis_job --apply')
        ->expectsOutputToContain('analysis_job: mode=apply candidates=1 recovered=1')
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
    $this->artisan('work:recover-stuck --stream=analysis_job --apply')->assertSuccessful();

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Draft);
});

test('閾値内の queued / running は回収されない', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, , $queued] = staleJobContext();
    [, , $running] = staleJobContext('running');

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:10:00'));
    $this->artisan('work:recover-stuck --stream=analysis_job --apply')
        ->expectsOutputToContain('analysis_job: mode=apply candidates=0 recovered=0')
        ->assertSuccessful();

    expect($queued->refresh()->status)->toBe(JobStatus::Queued);
    expect($running->refresh()->status)->toBe(JobStatus::Running);
});

test('terminal (succeeded / failed) は対象外', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, , $succeeded] = staleJobContext('succeeded');
    [, , $failed] = staleJobContext('failed');

    $this->travelTo(CarbonImmutable::parse('2026-07-11 01:00:00'));
    $this->artisan('work:recover-stuck --stream=analysis_job --apply')
        ->expectsOutputToContain('analysis_job: mode=apply candidates=0 recovered=0')
        ->assertSuccessful();

    expect($succeeded->refresh()->status)->toBe(JobStatus::Succeeded);
    expect($failed->refresh()->status)->toBe(JobStatus::Failed);
});

test('回収後の遅延配送は queued guard で no-op (二重実行しない)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, $manual, $job] = staleJobContext();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $this->artisan('work:recover-stuck --stream=analysis_job --apply')->assertSuccessful();
    expect($job->refresh()->status)->toBe(JobStatus::Failed);

    // 遅延配送 (queue 詰まりが解けて後から届いた) → LLM 呼び出しなしで即 return
    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($manual->refresh()->cuts()->count())->toBe(0);
});

/*
 * 誤回収の防止 (T171 で塞いだ欠陥): 候補を列挙してから行ロックを取るまでの間に
 * worker が進捗を書いた running ジョブを、正常に動いているのに失敗として確定してしまう窓。
 * 行ロック下で滞留の述語ごと再評価する形にしたので Skipped になる。
 */

test('候補列挙後に進捗が進んだ running ジョブは Skipped で failed にならない', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, , $job] = staleJobContext('running');
    $stream = app(StaleAnalysisJobStream::class);

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $sweptAt = CarbonImmutable::now();
    $ids = $stream->candidateIds($sweptAt, null, 10);
    expect($ids)->toBe([$job->id]);

    // worker が進捗を書いた (updated_at が現在時刻へ進む)
    DB::table('analysis_jobs')->where('id', $job->id)->update(['updated_at' => $sweptAt]);

    expect($stream->recover($job->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
    expect($job->refresh()->status)->toBe(JobStatus::Running);
});

test('候補列挙後に succeeded へ先着されたジョブは Skipped', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, , $job] = staleJobContext('running');
    $stream = app(StaleAnalysisJobStream::class);

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $sweptAt = CarbonImmutable::now();
    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$job->id]);

    DB::table('analysis_jobs')->where('id', $job->id)->update(['status' => JobStatus::Succeeded->value]);

    expect($stream->recover($job->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
});

test('candidateIds は昇順・afterId より大きい id だけ・pageSize を超えない', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, , $first] = staleJobContext();
    [, , $second] = staleJobContext();
    [, , $third] = staleJobContext();
    $stream = app(StaleAnalysisJobStream::class);

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $sweptAt = CarbonImmutable::now();

    expect($stream->candidateIds($sweptAt, null, 2))->toBe([$first->id, $second->id]);
    expect($stream->candidateIds($sweptAt, $first->id, 10))->toBe([$second->id, $third->id]);
    expect($stream->candidateIds($sweptAt, $third->id, 10))->toBe([]);
});

test('列挙とロック取得は同じ述語を使う (閾値の境界 4 点で結果が一致する)', function (): void {
    $stream = app(StaleAnalysisJobStream::class);

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [, , $queuedStale] = staleJobContext();          // created_at = 00:00
    [, , $runningStale] = staleJobContext('running'); // updated_at = 00:00

    // 閾値 30 分ちょうど = 超過 (<=) → 両方が候補で、回収も成立する
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:30:00'));
    $sweptAt = CarbonImmutable::now();
    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$queuedStale->id, $runningStale->id]);
    expect($stream->recover($queuedStale->id, $sweptAt))->toBe(RecoveryOutcome::Recovered);
    expect($stream->recover($runningStale->id, $sweptAt))->toBe(RecoveryOutcome::Recovered);

    // 1 分手前 = 未超過 → 候補にも入らず、名指しで回収しても Skipped になる
    $this->travelTo(CarbonImmutable::parse('2026-07-11 01:00:00'));
    [, , $queuedFresh] = staleJobContext();
    [, , $runningFresh] = staleJobContext('running');
    $this->travelTo(CarbonImmutable::parse('2026-07-11 01:29:00'));
    $freshSweptAt = CarbonImmutable::now();
    expect($stream->candidateIds($freshSweptAt, null, 10))->toBe([]);
    expect($stream->recover($queuedFresh->id, $freshSweptAt))->toBe(RecoveryOutcome::Skipped);
    expect($stream->recover($runningFresh->id, $freshSweptAt))->toBe(RecoveryOutcome::Skipped);
});
