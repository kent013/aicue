<?php

declare(strict_types=1);

use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * T182 + T189: 一覧の候補行 relation (VideoManual::latestSucceededRender) と
 * 受け取り対象の選択式 (CurrentRenderArtifact::currentSucceeded) の**世代定義が一致**すること、
 * および一覧の current_finished_render_job_id と受け取り口 2 本
 * (download の 302/404 / playback の 302/404/403) の判断が一致すること。
 *
 * 両者の違いは 1 点だけである: relation は output_path を見ない (候補行を返す) ので、
 * 「受け取れるか」の決定は選択式 (CurrentRenderArtifact) が持つ。
 */

/**
 * 署名 URL を stub した上で組織・所有者・プロジェクトを用意する
 * (fake local disk は temporaryUrl を標準サポートしないため)。
 *
 * @return array{Organization, User, Project}
 */
function parityFixture(): array
{
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path): string => "https://signed.example/{$path}",
    );
    [$organization, $owner] = createOrganizationWithOwner();

    return [$organization, $owner, Project::factory()->forOrganization($organization)->create()];
}

test('succeeded が 2 世代あるとき両者とも最新の行を指し、一覧の id で再生できる', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();

    $manual->refresh();

    expect($manual->latestSucceededRender?->id)->toBe($newest->id);
    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($newest->id);

    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];
    // 一覧が返す id = 受け取り口が受け付ける id (props と endpoint の非対称を作らない)
    expect($rows[0]['current_finished_render_job_id'])->toBe($newest->id);

    $this->actingAs($owner)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$newest->id}/playback")
        ->assertRedirect();
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertRedirect();
});

test('旧世代の render job id を直叩きすると playback は 404 (一覧はその id を返さない)', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    $old = RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();

    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];
    expect($rows[0]['current_finished_render_job_id'])->toBe($newest->id);

    $this->actingAs($owner)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$old->id}/playback")
        ->assertNotFound();
});

test('最新 succeeded の output_path が null なら旧世代へフォールバックしない (一覧 null / endpoint 404)', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
    $stale = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')
        ->state(fn (): array => ['output_path' => null])->create();

    $manual->refresh();

    // relation は候補行 (output_path を見ない) を返し、選択式は「受け取れない」と答える
    expect($manual->latestSucceededRender?->id)->toBe($stale->id);
    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('manuals.data.0.current_finished_render_job_id', null));
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertNotFound();
});

test('preview の succeeded しか無いときは両者とも「無し」', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->preview()->succeeded('renders/preview.mp4')->create();

    $manual->refresh();

    expect($manual->latestSucceededRender)->toBeNull();
    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('manuals.data.0.current_finished_render_job_id', null));
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertNotFound();
});

test('failed / running しか無いときは両者とも「無し」', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->failed()->create();
    RenderJob::factory()->forManual($manual)->running()->create();

    $manual->refresh();

    expect($manual->latestSucceededRender)->toBeNull();
    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('manuals.data.0.current_finished_render_job_id', null));
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertNotFound();
});

test('published でない行は succeeded render があっても一覧 null / endpoint 404 (公開状態の一致)', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
        'total_length_ms' => 60_000,
    ]);
    $job = RenderJob::factory()->forManual($manual)->succeeded('renders/ready.mp4')->create();

    $manual->refresh();

    // 選択式は「どの行か」だけを答える (published 判定は持たない) ので行を返す。
    // 受け取れるかの判断は一覧 props と endpoint が同じ条件で行い、両者とも「不可」になる。
    expect($manual->latestSucceededRender?->id)->toBe($job->id);
    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($job->id);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('manuals.data.0.current_finished_render_job_id', null)
            ->where('manuals.data.0.duration_ms', null));
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertNotFound();
});

test('撮影者は一覧 id が null で playback も 403 (props と endpoint が同じ結論を出す)', function (): void {
    // **権限だけで結論が決まるデータ**にする。published + 現行世代 succeeded + output_path あり =
    // 編集者なら 302 になる状態を用意し、撮影者だと 403 になることを見る
    // (404 と混ざらない = 層 2 の 404 ではないことが確定する)。
    [$organization, $owner, $project] = parityFixture();
    $shooter = attachOrganizationMember($organization);
    $shooter->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $shooter, ProjectRole::Member);
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    $job = RenderJob::factory()->forManual($manual)->succeeded('renders/ok.mp4')->create();

    // 編集者は 302 (データ側は「受け取れる」状態であることの対照)
    $this->actingAs($owner)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback")
        ->assertRedirect();

    $rows = $this->actingAs($shooter)->get("/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];
    expect($rows[0]['current_finished_render_job_id'])->toBeNull();

    $this->actingAs($shooter)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback")
        ->assertForbidden();
});
