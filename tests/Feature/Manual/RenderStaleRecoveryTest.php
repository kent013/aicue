<?php

declare(strict_types=1);

use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\VideoManualStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\RenderJobService;
use Carbon\CarbonImmutable;

/*
 * stale 回復 (render:recover-stale-jobs。概念設計 §5):
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
    $recovered = app(RenderJobService::class)->recoverStale();

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
    $recovered = app(RenderJobService::class)->recoverStale();

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
    app(RenderJobService::class)->recoverStale();

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
    app(RenderJobService::class)->recoverStale();

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($readyManual->refresh()->status)->toBe(VideoManualStatus::Ready);
});

test('terminal (succeeded/failed) は回収対象外 (冪等)', function (): void {
    [, , , $manual] = staleRecoveryContext();
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    $succeeded = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1.mp4')->create();
    $failed = RenderJob::factory()->forManual($manual)->failed()->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 01:00:00'));
    $recovered = app(RenderJobService::class)->recoverStale();

    expect($recovered)->toBe(0);
    expect($succeeded->refresh()->status)->toBe(JobStatus::Succeeded);
    expect($failed->refresh()->status)->toBe(JobStatus::Failed);
});

test('render:recover-stale-jobs command smoke (回収件数を出力する)', function (): void {
    [, , , $manual] = staleRecoveryContext();
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    RenderJob::factory()->forManual($manual)->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:11:00'));
    $this->artisan('render:recover-stale-jobs')
        ->expectsOutputToContain('recovered 1 stale render job(s)')
        ->assertSuccessful();
});
