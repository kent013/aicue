<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\TakeStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Jobs\Manual\RunManualRender;
use App\Models\Billing\TicketReservation;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Support\Facades\Queue;

/*
 * レンダ/プレビュートリガー (POST .../render, .../preview。doc/10 §10.8-8 / 概念設計 §4):
 * - render: ready のみ・in-flight 冪等・採用テイク欠落 422・尺上限 422・残高 402
 * - preview: analyzing/rendering 409・cuts なし 422・org 上限 409・チケット非消費
 * - 撮影者 403 / cross-org・cross-project 404 / 保護キー直送 422 / throttle 429
 */

/**
 * 編集者 (owner) + ready manual (採用済みテイク付き cuts) + チケット残高のセットアップ。
 *
 * @return array{Organization, User, Project, VideoManual, Cut}
 */
function renderTriggerContext(int $tickets = 3, int $scenarioVersion = 2): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => $scenarioVersion,
    ]);
    $cut = Cut::factory()->forManual($manual)->create();
    adoptReadyTake($cut);
    if ($tickets > 0) {
        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
    }

    return [$organization, $owner, $project, $manual, $cut];
}

/** cut に ready テイクを作成して採用する */
function adoptReadyTake(Cut $cut, int $durationMs = 5_000): Take
{
    $take = Take::factory()->forCut($cut)->create(['duration_ms' => $durationMs]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    return $take;
}

test('ready + 採用テイク + 残高あり → 201 (job=queued / manual=rendering / version スナップショット / dispatch)', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();

    $response = $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    );

    $response->assertCreated()->assertJson([
        'kind' => 'render',
        'status' => 'queued',
        'step' => null,
        'error' => null,
        'error_code' => null,
        'manual_status' => 'rendering',
    ]);

    $job = RenderJob::query()->firstOrFail();
    expect($job->kind)->toBe(RenderKind::Render);
    expect($job->status)->toBe(JobStatus::Queued);
    expect($job->video_manual_id)->toBe($manual->id);
    expect($job->scenario_version)->toBe(2); // §10.8-6 スナップショット
    expect($job->ticket_reservation_id)->toBeNull(); // 予約はジョブ開始時 (§10.5)
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Rendering);

    Queue::assertPushed(RunManualRender::class, fn (RunManualRender $pushed): bool => $pushed->renderJobId === $job->id);
});

test('ready 以外は 409 (type=status_not_renderable。published 直接再レンダも不可)', function (VideoManualStatus $status): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();
    $manual->forceFill(['status' => $status])->save();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertConflict()->assertJson([
        'code' => 'render_conflict',
        'conflict_type' => 'status_not_renderable',
    ]);
    expect(RenderJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'draft' => VideoManualStatus::Draft,
    'analyzing' => VideoManualStatus::Analyzing,
    'rendering' => VideoManualStatus::Rendering,
    'published' => VideoManualStatus::Published,
]);

test('in-flight render があると 409 (type=in_flight・DB 不変)', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();
    RenderJob::factory()->forManual($manual)->create();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertConflict()->assertJson([
        'code' => 'render_conflict',
        'conflict_type' => 'in_flight',
    ]);
    expect(RenderJob::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('preview の in-flight は render を妨げない (別操作種別 = §10.8-8)', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();
    RenderJob::factory()->forManual($manual)->preview()->create();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertCreated();

    expect(RenderJob::query()->where('kind', RenderKind::Render->value)->count())->toBe(1);
});

test('直前 render job が failed なら再トリガー 201 (in-flight 不在)', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();
    RenderJob::factory()->forManual($manual)->failed()->create();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertCreated();

    expect(RenderJob::query()->count())->toBe(2);
});

test('採用テイク欠落は 422 (欠落カットの表示ラベルが message に含まれる・status 不変)', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();
    // 2 本目の step は未採用
    Cut::factory()->forManual($manual)->withSortOrder(1)->create();

    $response = $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    );

    $response->assertUnprocessable()->assertJsonValidationErrors(['takes']);
    expect($response->json('errors.takes.0'))->toContain('手順2');
    expect(RenderJob::query()->count())->toBe(0);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
    Queue::assertNothingPushed();
});

test('採用テイクが ready でない (processing) 場合も 422', function (): void {
    Queue::fake();
    [, $owner, $project, $manual, $cut] = renderTriggerContext();
    $take = $cut->adoptedTake;
    expect($take)->not->toBeNull();
    $take?->forceFill(['status' => TakeStatus::Processing->value])->save();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertUnprocessable()->assertJsonValidationErrors(['takes']);
});

test('尺上限超過は 422 (duration_ms 合計がソフトゲート超過)', function (): void {
    Queue::fake();
    config()->set('manual.render_max_total_source_ms', 4_000);
    [, $owner, $project, $manual] = renderTriggerContext(); // 採用テイクは 5,000ms

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertUnprocessable()->assertJsonValidationErrors(['takes']);
    expect(RenderJob::query()->count())->toBe(0);
});

test('尺上限: Still カットは static_display_seconds×1000 で数える (テイク実尺 5,000ms は無視 → 201)', function (): void {
    Queue::fake();
    config()->set('manual.render_max_total_source_ms', 4_000);
    [, $owner, $project, $manual, $cut] = renderTriggerContext(); // 採用テイクは 5,000ms
    $cut->forceFill([
        'material_type' => MaterialType::Still->value,
        'static_display_seconds' => 3, // → 3,000ms として加算 (上限 4,000ms 内)
    ])->save();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertCreated();
});

test('尺上限: Still カットの static_display_seconds 合計が上限超過なら 422 (テイク実尺が小さくても)', function (): void {
    Queue::fake();
    config()->set('manual.render_max_total_source_ms', 4_000);
    [, $owner, $project, $manual, $cut] = renderTriggerContext();
    $cut->adoptedTake?->forceFill(['duration_ms' => 1_000])->save(); // 実尺は上限内
    $cut->forceFill([
        'material_type' => MaterialType::Still->value,
        'static_display_seconds' => 5, // → 5,000ms として加算 (上限 4,000ms 超過)
    ])->save();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertUnprocessable()->assertJsonValidationErrors(['takes']);
    expect(RenderJob::query()->count())->toBe(0);
});

test('尺上限: 合計がちょうど上限なら 201 (境界値。DeterminedCutDuration への切り出しで挙動が動かないことの固定)', function (): void {
    Queue::fake();
    config()->set('manual.render_max_total_source_ms', 5_000);
    [, $owner, $project, $manual] = renderTriggerContext(); // 採用テイクは 5,000ms ちょうど

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertCreated();
});

test('尺上限: 合計が上限 +1ms なら 422 (境界値)', function (): void {
    Queue::fake();
    config()->set('manual.render_max_total_source_ms', 4_999);
    [, $owner, $project, $manual] = renderTriggerContext(); // 採用テイクは 5,000ms

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertUnprocessable()->assertJsonValidationErrors(['takes']);
    expect(RenderJob::query()->count())->toBe(0);
});

test('尺上限: duration_ms NULL の採用テイクは render_default_take_duration_ms で数えられる', function (): void {
    Queue::fake();
    config()->set('manual.render_default_take_duration_ms', 60_000);
    config()->set('manual.render_max_total_source_ms', 59_999);
    [, $owner, $project, $manual, $cut] = renderTriggerContext();
    $cut->adoptedTake?->forceFill(['duration_ms' => null])->save();

    // 60,000ms (既定値) で数えられ、59,999ms の上限を超えるので 422
    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertUnprocessable()->assertJsonValidationErrors(['takes']);
    expect(RenderJob::query()->count())->toBe(0);
});

test('残高不足は 402 (code=insufficient_tickets。job も予約も作らない・status 不変)', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext(tickets: 2); // cost=3 に不足

    $response = $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    );

    $response->assertPaymentRequired()->assertJson(['code' => 'insufficient_tickets']);
    expect(RenderJob::query()->count())->toBe(0);
    expect(TicketReservation::query()->count())->toBe(0);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
    Queue::assertNothingPushed();
});

test('preview: ready で 201 (manual status 不変・チケット非消費・version スナップショット)', function (): void {
    Queue::fake();
    [$organization, $owner, $project, $manual] = renderTriggerContext(tickets: 0);

    $response = $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    );

    $response->assertCreated()->assertJson([
        'kind' => 'preview',
        'status' => 'queued',
        'manual_status' => 'ready', // 遷移しない
    ]);

    $job = RenderJob::query()->firstOrFail();
    expect($job->kind)->toBe(RenderKind::Preview);
    expect($job->scenario_version)->toBe(2);
    expect($job->ticket_reservation_id)->toBeNull();
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
    // チケット非消費: 台帳・予約とも無変化 (残高 0 でも通る)
    expect(TicketReservation::query()->count())->toBe(0);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    Queue::assertPushed(RunManualRender::class, fn (RunManualRender $pushed): bool => $pushed->renderJobId === $job->id);
});

test('preview: 採用テイク欠落は許容される (プレースホルダ合成 = 201)', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();
    Cut::factory()->forManual($manual)->withSortOrder(1)->create(); // 未採用 cut

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertCreated();
});

test('preview: analyzing / rendering は 409 (type=status_not_previewable)', function (VideoManualStatus $status): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();
    $manual->forceFill(['status' => $status])->save();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertConflict()->assertJson([
        'code' => 'render_conflict',
        'conflict_type' => 'status_not_previewable',
    ]);
    Queue::assertNothingPushed();
})->with([
    'analyzing' => VideoManualStatus::Analyzing,
    'rendering' => VideoManualStatus::Rendering,
]);

test('preview: cuts なし (draft) は 422「シナリオがありません」', function (): void {
    Queue::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(); // draft・cuts なし

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertUnprocessable()->assertJsonValidationErrors(['scenario']);
    Queue::assertNothingPushed();
});

test('preview: 同一 manual の in-flight preview は 409 (in_flight)', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();
    RenderJob::factory()->forManual($manual)->preview()->running()->create();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertConflict()->assertJson(['conflict_type' => 'in_flight']);
});

test('preview: org 同時 preview 上限で 409 (org_preview_limit。逐次境界)', function (): void {
    Queue::fake();
    config()->set('manual.render_max_inflight_previews_per_org', 2);
    [, $owner, $project, $manual] = renderTriggerContext();

    // 同一 org の別 manual に in-flight preview を上限まで積む
    foreach (range(1, 2) as $i) {
        $other = VideoManual::factory()->forProject($project)->create([
            'status' => VideoManualStatus::Ready->value,
        ]);
        Cut::factory()->forManual($other)->create();
        RenderJob::factory()->forManual($other)->preview()->running()->create();
    }

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertConflict()->assertJson(['conflict_type' => 'org_preview_limit']);
    expect(RenderJob::query()->where('video_manual_id', $manual->id)->count())->toBe(0);
});

test('preview: 別 org の in-flight preview は上限に数えない (cross-org 不干渉)', function (): void {
    Queue::fake();
    config()->set('manual.render_max_inflight_previews_per_org', 1);
    // 別 org に in-flight preview
    createOrganizationWithOwner('別組織');
    $otherOrg = Organization::query()->orderByDesc('id')->firstOrFail();
    $otherProject = Project::factory()->forOrganization($otherOrg)->create();
    $otherManual = VideoManual::factory()->forProject($otherProject)->create();
    RenderJob::factory()->forManual($otherManual)->preview()->running()->create();

    [, $owner, $project, $manual] = renderTriggerContext();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertCreated();
});

test('撮影者 (project_member) は render/preview とも 403', function (): void {
    Queue::fake();
    [$organization, , $project, $manual] = renderTriggerContext();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($member)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertForbidden();
    $this->actingAs($member)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertForbidden();
    Queue::assertNothingPushed();
});

test('cross-org の manual への POST は 404 (存在オラクル封じ)', function (): void {
    [, $stranger] = createOrganizationWithOwner('別組織');
    [, , $project, $manual] = renderTriggerContext();

    $this->actingAs($stranger)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
    )->assertNotFound();
    $this->actingAs($stranger)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertNotFound();
});

test('cross-project の manual への POST は 404 (scopeBindings)', function (): void {
    [$organization, $owner, , $manual] = renderTriggerContext();
    $otherProject = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->postJson(
        "/projects/{$otherProject->id}/manuals/{$manual->id}/render",
    )->assertNotFound();
});

test('保護キー (ticket_reservation_id 等) の直送は 422', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render",
        ['ticket_reservation_id' => 999, 'video_manual_id' => 999],
    )->assertUnprocessable()->assertJsonValidationErrors(['ticket_reservation_id', 'video_manual_id']);

    expect(RenderJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('throttle: render-trigger は 6 回/分 (7 回目は 429)', function (): void {
    Queue::fake();
    [, $owner, $project, $manual] = renderTriggerContext();

    foreach (range(1, 6) as $i) {
        $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/manuals/{$manual->id}/preview",
        ); // 1 回目 201・以降 409 (throttle は応答種別に関係なく数える)
    }

    $this->actingAs($owner)->postJson(
        "/projects/{$project->id}/manuals/{$manual->id}/preview",
    )->assertTooManyRequests();
});

test('未ログインは 401 (JSON)', function (): void {
    [, , $project, $manual] = renderTriggerContext();

    $this->postJson("/projects/{$project->id}/manuals/{$manual->id}/render")->assertUnauthorized();
    $this->postJson("/projects/{$project->id}/manuals/{$manual->id}/preview")->assertUnauthorized();
});
