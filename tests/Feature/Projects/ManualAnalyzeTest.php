<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Jobs\Manual\RunManualAnalysis;
use App\Models\AnalysisJob;
use App\Models\Billing\TicketReservation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Support\Facades\Queue;

/*
 * AI 解析トリガー (POST .../manuals/{manual}/analyze。doc/10 §10.8-8):
 * - draft/ready のみ実行可 (analyzing/rendering/published は 409)
 * - in-flight (queued/running) は 1 つ → 409 (code=analysis_conflict)
 * - document 無し 422 / 残高不足 402 (job を作らない・status 不変)
 * - 撮影者 403 / cross-org・cross-project 404 / 保護キー直送 422 / 未ログイン 401
 */

/**
 * 編集者 (owner) + document 付き manual + チケット残高 1 のセットアップ。
 *
 * @return array{Organization, User, Project, VideoManual}
 */
function analyzeTestContext(bool $withDocument = true, int $tickets = 1): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    if ($withDocument) {
        SourceDocument::factory()->forManual($manual)->create();
    }
    if ($tickets > 0) {
        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
    }

    return [$organization, $owner, $project, $manual];
}

test('draft + document + 残高あり → 201 (job=queued / manual=analyzing / dispatch)', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = analyzeTestContext();

    $response = $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    );

    $response->assertCreated()->assertJson([
        'status' => 'queued',
        'step' => null,
        'error' => null,
        'manual_status' => 'analyzing',
    ]);

    $job = AnalysisJob::query()->firstOrFail();
    expect($job->status)->toBe(JobStatus::Queued);
    expect($job->video_manual_id)->toBe($manual->id);
    expect($job->source_document_id)->not->toBeNull();
    expect($job->ticket_reservation_id)->toBeNull(); // 予約はジョブ開始時 (§10.5)
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Analyzing);

    Queue::assertPushed(RunManualAnalysis::class, fn (RunManualAnalysis $pushed): bool => $pushed->analysisJobId === $job->id);
});

test('ready からの再解析も 201 (正式な遷移)', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = analyzeTestContext();
    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    )->assertCreated();

    expect($manual->refresh()->status)->toBe(VideoManualStatus::Analyzing);
});

test('in-flight (queued) があると 409 (type=in_flight・DB 不変)', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = analyzeTestContext();
    // 直前のトリガー済み状態を再現 (manual は analyzing + queued job)
    AnalysisJob::factory()->forManual($manual)->create();
    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();

    $response = $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    );

    $response->assertConflict()->assertJson([
        'code' => 'analysis_conflict',
        'conflict_type' => 'in_flight',
    ]);
    expect(AnalysisJob::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('analyzing 中の manual は 409 (type=status_not_analyzable)', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = analyzeTestContext();
    $manual->forceFill(['status' => VideoManualStatus::Analyzing])->save();

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    )->assertConflict()->assertJson([
        'code' => 'analysis_conflict',
        'conflict_type' => 'status_not_analyzable',
    ]);
    Queue::assertNothingPushed();
});

test('rendering / published も 409 (type=status_not_analyzable)', function (VideoManualStatus $status): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = analyzeTestContext();
    $manual->forceFill(['status' => $status])->save();

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    )->assertConflict()->assertJson(['conflict_type' => 'status_not_analyzable']);
})->with([
    'rendering' => VideoManualStatus::Rendering,
    'published' => VideoManualStatus::Published,
]);

test('直前 job が failed なら再トリガー 201 (冪等ルール: in-flight 不在)', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = analyzeTestContext();
    AnalysisJob::factory()->forManual($manual)->failed()->create();

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    )->assertCreated();

    expect(AnalysisJob::query()->count())->toBe(2);
});

test('document 無しは 422 (job を作らない・status 不変)', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = analyzeTestContext(withDocument: false);

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    )->assertUnprocessable()->assertJsonValidationErrors(['document']);

    expect(AnalysisJob::query()->count())->toBe(0);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Draft);
    Queue::assertNothingPushed();
});

test('残高 0 は 402 (code=insufficient_tickets。job も予約も作らない・status 不変)', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = analyzeTestContext(tickets: 0);

    $response = $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    );

    $response->assertPaymentRequired()->assertJson(['code' => 'insufficient_tickets']);
    expect(AnalysisJob::query()->count())->toBe(0);
    expect(TicketReservation::query()->count())->toBe(0);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Draft);
    Queue::assertNothingPushed();
});

test('撮影者 (project_member) は 403 / ポーリング GET は 200', function (): void {
    Queue::fake();
    [$organization, , $project, $manual] = analyzeTestContext();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($member)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    )->assertForbidden();

    $job = AnalysisJob::factory()->forManual($manual)->create();
    $this->actingAs($member)->getJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/jobs/{$job->id}",
    )->assertOk();
});

test('cross-org の manual への POST は 404 (存在オラクル封じ)', function (): void {
    [$organization, $stranger] = createOrganizationWithOwner('別組織');
    [, , $project, $manual] = analyzeTestContext();

    $this->actingAs($stranger)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    )->assertNotFound();
});

test('cross-project の manual への POST は 404 (scopeBindings)', function (): void {
    [$organization, $owner, , $manual] = analyzeTestContext();
    $otherProject = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$otherProject->id}/manuals/{$manual->id}/analyze",
    )->assertNotFound();
});

test('保護キー (ticket_reservation_id 等) の直送は 422', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = analyzeTestContext();

    $this->actingAs($owner)->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
        ['ticket_reservation_id' => 999, 'video_manual_id' => 999],
    )->assertUnprocessable()->assertJsonValidationErrors(['ticket_reservation_id', 'video_manual_id']);

    expect(AnalysisJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('未ログインは 401 (JSON)', function (): void {
    [$organization, , $project, $manual] = analyzeTestContext();

    $this->postJson(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/analyze",
    )->assertUnauthorized();
});
