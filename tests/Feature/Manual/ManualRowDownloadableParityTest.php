<?php

declare(strict_types=1);

use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * T182: 一覧の候補行 relation (VideoManual::latestSucceededRender) と
 * 受け取り対象の選択式 (CurrentRenderArtifact::currentSucceeded) の**世代定義が一致**すること、
 * および一覧の downloadable と download endpoint (302 / 404) の判断が一致すること。
 *
 * 両者の違いは 1 点だけである: relation は output_path を見ない (候補行を返す) ので、
 * 「受け取れるか」は呼び出し側が output_path を足して判断する。
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

test('succeeded が 2 世代あるとき両者とも最新の行を指す', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();

    $manual->refresh();

    expect($manual->latestSucceededRender?->id)->toBe($newest->id);
    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($newest->id);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', true));
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertRedirect();
});

test('最新 succeeded の output_path が null なら旧世代へフォールバックしない (一覧 false / endpoint 404)', function (): void {
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
        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', false));
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
        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', false));
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
        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', false));
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertNotFound();
});

test('published でない行は succeeded render があっても一覧 false / endpoint 404 (公開状態の一致)', function (): void {
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
            ->where('manuals.data.0.downloadable', false)
            ->where('manuals.data.0.duration_ms', null));
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertNotFound();
});
