<?php

declare(strict_types=1);

use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\Recovery\RecoveryOutcome;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Recovery\Streams\StaleRenderJobStream;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * stale 回復 (work:recover-stuck --stream=render_job --apply。概念設計 §5):
 * - queued: created_at が 10 分 (render_queued_stale_after_minutes) 超過 → failJob (短 SLA)
 * - running: updated_at が 30 分 (render_stale_after_minutes) 超過 → failJob
 * - error_code=timeout / kind=render は rendering→ready 復帰 / preview は status 不変 /
 *   予約 Reserved は release / terminal 済みは no-op (冪等)
 */

/**
 * @return array{Organization, User, Project, VideoManual}
 */
function staleRecoveryContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Rendering->value,
    ]);

    return [$organization, $owner, $project, $manual];
}

test('queued は 10 分超過で回収・10 分未満は回収しない (短 SLA)', function (): void {
    [, , , $manual] = staleRecoveryContext();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    $staleQueued = RenderJob::factory()->forManual($manual)->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:05:00'));
    $freshQueued = RenderJob::factory()->forManual($manual)->preview()->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:11:00'));
    $recovered = recoverStaleRenderJobs();

    expect($recovered)->toBe(1);
    expect($staleQueued->refresh()->status)->toBe(JobStatus::Failed);
    expect($staleQueued->error_code)->toBe(RenderErrorCode::Timeout);
    expect($staleQueued->error)->toContain('タイムアウト');
    expect($freshQueued->refresh()->status)->toBe(JobStatus::Queued);
});

test('running は 30 分超過で回収・30 分未満 (15 分) は回収しない', function (): void {
    [, , , $manual] = staleRecoveryContext();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    $staleRunning = RenderJob::factory()->forManual($manual)->running()->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:16:00'));
    $freshRunning = RenderJob::factory()->forManual($manual)->preview()->running()->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $recovered = recoverStaleRenderJobs();

    expect($recovered)->toBe(1);
    expect($staleRunning->refresh()->status)->toBe(JobStatus::Failed);
    expect($staleRunning->error_code)->toBe(RenderErrorCode::Timeout);
    expect($freshRunning->refresh()->status)->toBe(JobStatus::Running);
});

test('kind=render の回収は manual を rendering→ready へ復帰・予約 Reserved は release する', function (): void {
    [$organization, , , $manual] = staleRecoveryContext();
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    app(TicketLedgerService::class)->grant($organization, 3, 'テスト残高');
    $reservation = app(TicketLedgerService::class)->reserve($organization, 3);
    $job = RenderJob::factory()->forManual($manual)->running()->create([
        'ticket_reservation_id' => $reservation->id,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    recoverStaleRenderJobs();

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(3); // 拘束解放
});

test('kind=preview の回収は manual status を触らない', function (): void {
    [, , $project] = staleRecoveryContext();
    $readyManual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    $job = RenderJob::factory()->forManual($readyManual)->preview()->running()->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    recoverStaleRenderJobs();

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($readyManual->refresh()->status)->toBe(VideoManualStatus::Ready);
});

test('terminal (succeeded/failed) は回収対象外 (冪等)', function (): void {
    [, , , $manual] = staleRecoveryContext();
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    $succeeded = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1.mp4')->create();
    $failed = RenderJob::factory()->forManual($manual)->failed()->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 01:00:00'));
    $recovered = recoverStaleRenderJobs();

    expect($recovered)->toBe(0);
    expect($succeeded->refresh()->status)->toBe(JobStatus::Succeeded);
    expect($failed->refresh()->status)->toBe(JobStatus::Failed);
});

test('work:recover-stuck --stream=render_job command smoke (回収件数を出力する)', function (): void {
    [, , , $manual] = staleRecoveryContext();
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    RenderJob::factory()->forManual($manual)->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:11:00'));
    $this->artisan('work:recover-stuck --stream=render_job --apply')
        ->expectsOutputToContain('render_job: mode=apply candidates=1 recovered=1')
        ->assertSuccessful();
});

/*
 * 誤回収の防止 (T171): 候補を列挙してから行ロックを取るまでの間に worker が進捗を書いた
 * running ジョブは失敗にしない。レンダは manual を rendering→ready へ戻す副作用があるため、
 * 誤回収を止めることは**編集ロックの誤解除を止める**ことでもある。
 */

test('候補列挙後に進捗が進んだ running レンダジョブは Skipped で failed にならない', function (): void {
    [, , , $manual] = staleRecoveryContext();
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    $job = RenderJob::factory()->forManual($manual)->running()->create();
    $stream = app(StaleRenderJobStream::class);

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    $sweptAt = CarbonImmutable::now();
    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$job->id]);

    DB::table('render_jobs')->where('id', $job->id)->update(['updated_at' => $sweptAt]);

    expect($stream->recover($job->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
    expect($job->refresh()->status)->toBe(JobStatus::Running);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Rendering); // 編集ロックも解けない
});

test('列挙とロック取得は同じ述語を使う (kind × 状態 × 閾値境界の直積で一致する)', function (): void {
    [, , $project] = staleRecoveryContext();
    $stream = app(StaleRenderJobStream::class);

    // queued は 10 分 / running は 30 分。境界ちょうどは超過扱い (<=)
    $cases = [
        ['preview' => false, 'running' => false, 'boundary' => 10],
        ['preview' => true, 'running' => false, 'boundary' => 10],
        ['preview' => false, 'running' => true, 'boundary' => 30],
        ['preview' => true, 'running' => true, 'boundary' => 30],
    ];

    foreach ($cases as $index => $case) {
        $manual = VideoManual::factory()->forProject($project)->create([
            'status' => VideoManualStatus::Ready->value,
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
        $factory = RenderJob::factory()->forManual($manual);
        if ($case['preview']) {
            $factory = $factory->preview();
        }
        if ($case['running']) {
            $factory = $factory->running();
        }
        $job = $factory->create();

        // 1 分手前 = 未超過 → 候補にも入らず、名指しの回収も Skipped
        $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00')->addMinutes($case['boundary'] - 1));
        $freshSweptAt = CarbonImmutable::now();
        expect($stream->candidateIds($freshSweptAt, null, 50))->not->toContain($job->id);
        expect($stream->recover($job->id, $freshSweptAt))->toBe(RecoveryOutcome::Skipped, "case {$index} (未超過)");

        // 境界ちょうど = 超過 → 候補に入り、回収も成立する
        $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00')->addMinutes($case['boundary']));
        $staleSweptAt = CarbonImmutable::now();
        expect($stream->candidateIds($staleSweptAt, null, 50))->toContain($job->id);
        expect($stream->recover($job->id, $staleSweptAt))->toBe(RecoveryOutcome::Recovered, "case {$index} (超過)");
    }
});

test('candidateIds は pageSize を超える件数を返さない', function (): void {
    [, , , $manual] = staleRecoveryContext();
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    RenderJob::factory()->count(3)->forManual($manual)->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:11:00'));
    expect(app(StaleRenderJobStream::class)->candidateIds(CarbonImmutable::now(), null, 2))->toHaveCount(2);
});
