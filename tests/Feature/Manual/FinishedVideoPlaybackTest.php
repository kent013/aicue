<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * 完成動画をアプリ内で観られるようにする (T154)。
 *
 * 固定する契約:
 * - playback は preview と完成動画の**両方**を扱う (route は増やさない。job id を含む既存の形)
 * - 完成動画の再生条件は download と**完全同一**: published + 現行世代 + download ability
 *   (認可を緩めない。層 2 のテナント境界 404 は認可より前)
 * - 詳細画面 props の finishedJob は endpoint が 302 を返す条件と 1 対 1
 *   (押すと 404 になる導線を UI に出さない = 判断はサーバで 1 回)
 * - 選択式は CurrentRenderArtifact ただ 1 つ。最新 succeeded の output_path が NULL のとき
 *   旧世代へフォールバックしない (実体が削除済みのため)
 */

/**
 * @return array{Organization, User, Project, VideoManual}
 */
function finishedVideoContext(): array
{
    Storage::fake('s3');
    // fake local disk は temporaryUrl を標準サポートしないため署名 URL 生成を stub する
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path): string => "https://signed.example/{$path}",
    );
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Published->value,
        'scenario_version' => 2,
    ]);

    return [$organization, $owner, $project, $manual];
}

/** 撮影者 (project_member) を作る */
function finishedVideoMember(Organization $organization, Project $project): User
{
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);

    return $member;
}

/** 詳細画面 props の render 配下を取り出す */
function finishedVideoRenderProps(Organization $organization, User $actor, Project $project, VideoManual $manual): array
{
    $props = [];
    test()->actingAs($actor)
        ->get("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            /** @var array<string, mixed> $render */
            $render = $page->toArray()['props']['render'];
            $props = $render;
        });

    return $props;
}

/* ---------------- playback (kind=render) ---------------- */

test('playback: published + 最新 succeeded render は 302 (S3 署名 URL へ redirect)', function (): void {
    [$organization, $owner, $project, $manual] = finishedVideoContext();
    $key = "projects/{$project->id}/manuals/{$manual->id}/renders/v2-1.mp4";
    $job = RenderJob::factory()->forManual($manual)->succeeded($key)->create();

    $this->actingAs($owner)->get(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    )->assertRedirect("https://signed.example/{$key}");
});

test('playback: published でない manual の完成動画は 404 (ready へ戻った旧完成動画も 404)', function (): void {
    [$organization, $owner, $project, $manual] = finishedVideoContext();
    $job = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
    // シナリオ編集で ready へ戻ると完成動画は受け取れなくなる (download と同一条件)
    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();

    $this->actingAs($owner)->get(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    )->assertNotFound();
});

test('playback: 旧世代 render は 404 (実体削除済みの世代へ署名 URL を出さない)', function (): void {
    [$organization, $owner, $project, $manual] = finishedVideoContext();
    $old = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();

    $this->actingAs($owner)->get(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$old->id}/playback",
    )->assertNotFound();
});

test('playback: 最新 succeeded render の output_path が NULL なら旧世代へフォールバックせず 404', function (): void {
    [$organization, $owner, $project, $manual] = finishedVideoContext();
    $old = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);

    $this->actingAs($owner)->get(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$old->id}/playback",
    )->assertNotFound();
});

test('playback: queued / running / failed の render は 404', function (): void {
    [$organization, $owner, $project, $manual] = finishedVideoContext();
    $base = "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs";

    $queued = RenderJob::factory()->forManual($manual)->create();
    $running = RenderJob::factory()->forManual($manual)->running()->create();
    $failed = RenderJob::factory()->forManual($manual)->failed()->create();

    $this->actingAs($owner)->get("{$base}/{$queued->id}/playback")->assertNotFound();
    $this->actingAs($owner)->get("{$base}/{$running->id}/playback")->assertNotFound();
    $this->actingAs($owner)->get("{$base}/{$failed->id}/playback")->assertNotFound();
});

test('playback: 撮影者は 403 (download ability = 編集者専用。層 2 の 404 より後に評価される)', function (): void {
    [$organization, , $project, $manual] = finishedVideoContext();
    $member = finishedVideoMember($organization, $project);
    $job = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();

    $this->actingAs($member)->get(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    )->assertForbidden();
});

test('playback: cross-org / cross-manual の完成動画 job は 404 (存在オラクル封じ)', function (): void {
    [$organization, $owner, $project, $manual] = finishedVideoContext();
    $job = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
    [, $stranger] = createOrganizationWithOwner('別組織');

    // cross-org (他組織の利用者からは存在ごと見えない)
    $this->actingAs($stranger)->get(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    )->assertNotFound();

    // cross-manual (同 project 内の別マニュアルの job id を差し込んでも 404)
    $otherManual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Published->value,
    ]);
    $this->actingAs($owner)->get(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$otherManual->id}/render-jobs/{$job->id}/playback",
    )->assertNotFound();
});

test('playback: kind=preview の 302 条件と ability は本変更で変わらない (回帰の明示固定)', function (): void {
    [$organization, $owner, $project, $manual] = finishedVideoContext();
    // preview は published を要求しない (ready のままでも再生できる)
    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
    $key = "projects/{$project->id}/manuals/{$manual->id}/previews/v2-1.mp4";
    $preview = RenderJob::factory()->forManual($manual)->preview()->succeeded($key)->create();
    $url = "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$preview->id}/playback";

    $this->actingAs($owner)->get($url)->assertRedirect("https://signed.example/{$key}");

    // 撮影者は 403 (render ability = 編集者専用)
    $member = finishedVideoMember($organization, $project);
    $this->actingAs($member)->get($url)->assertForbidden();
});

/* ---------------- download (選択式の載せ替え) ---------------- */

test('download: 最新 succeeded render の output_path が NULL なら旧世代へフォールバックせず 404', function (): void {
    [$organization, $owner, $project, $manual] = finishedVideoContext();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);

    $this->actingAs($owner)->get(
        "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/download",
    )->assertNotFound();
});

/* ---------------- 詳細画面 props (finishedJob) ---------------- */

test('props: published + download 権限保持者には finishedJob が最新 succeeded render を指す', function (): void {
    [, $owner, $project, $manual] = finishedVideoContext();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
    $latest = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();

    $render = finishedVideoRenderProps($organization, $owner, $project, $manual);

    expect($render['finishedJob'])->not->toBeNull();
    expect($render['finishedJob']['id'])->toBe($latest->id);
    expect($render['finishedJob']['kind'])->toBe('render');
});

test('props: ready へ戻った manual では finishedJob=null (押すと 404 になる導線を出さない)', function (): void {
    [, $owner, $project, $manual] = finishedVideoContext();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();

    expect(finishedVideoRenderProps($organization, $owner, $project, $manual)['finishedJob'])->toBeNull();
});

test('props: 詳細を閲覧できるが download 権限のない撮影者には finishedJob=null', function (): void {
    [$organization, , $project, $manual] = finishedVideoContext();
    $member = finishedVideoMember($organization, $project);
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();

    expect(finishedVideoRenderProps($organization, $member, $project, $manual)['finishedJob'])->toBeNull();
});

test('props: finishedJob のキー集合は RenderJobData::toArray() と exact 一致 (成果物 URL 系キーを持たない)', function (): void {
    [$organization, $owner, $project, $manual] = finishedVideoContext();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();

    $finished = finishedVideoRenderProps($organization, $owner, $project, $manual)['finishedJob'];

    expect(array_keys($finished))->toBe([
        'id', 'kind', 'status', 'step', 'progress', 'error', 'error_code',
        'manual_status', 'placeholder_cut_count',
    ]);
    // 本文検査は成果物キーと署名先ホストに限定する (無関係な props を拾って偽陽性にしないため)
    $body = (string) test()->actingAs($owner)
        ->get("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")->getContent();
    expect($body)->not->toContain('output_path');
    expect($body)->not->toContain('signed.example');
});

test('props: 最新 succeeded render の output_path が NULL なら finishedJob=null (route と同じ判断)', function (): void {
    [, $owner, $project, $manual] = finishedVideoContext();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);

    expect(finishedVideoRenderProps($organization, $owner, $project, $manual)['finishedJob'])->toBeNull();
});
