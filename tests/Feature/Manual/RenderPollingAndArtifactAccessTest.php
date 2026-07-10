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

/*
 * ポーリングと成果物アクセスの権限分離 (概念設計 §2/§7 Round 1 Critical):
 * - ポーリング (view = 撮影者も可) は進捗のみ。output_path / 署名 URL を一切含めない
 * - playback (render ability) は最新 succeeded preview のみ 302
 * - download (download ability) は published + 最新 succeeded render のみ 302
 */

/**
 * @return array{Organization, User, Project, VideoManual}
 */
function artifactAccessContext(): array
{
    Storage::fake('s3');
    // fake local disk は temporaryUrl を標準サポートしないため署名 URL 生成を stub する
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path): string => "https://signed.example/{$path}",
    );
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 2,
    ]);

    return [$organization, $owner, $project, $manual];
}

/** 撮影者 (project_member) を作る */
function artifactAccessMember(Organization $organization, Project $project): User
{
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member, ProjectRole::Member);

    return $member;
}

test('ポーリング: 200 + 進捗 shape のみ (output_path / URL を含めない = 権限分離の固定)', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $job = RenderJob::factory()->forManual($manual)->running()->create();

    $response = $this->actingAs($owner)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}",
    );

    $response->assertOk()->assertExactJson([
        'id' => $job->id,
        'kind' => 'render',
        'status' => 'running',
        'step' => 'compose',
        'progress' => 5,
        'error' => null,
        'error_code' => null,
        'manual_status' => 'ready',
    ]);
});

test('ポーリング: succeeded (output_path あり) でも応答に output_path・URL が現れない', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $job = RenderJob::factory()->forManual($manual)->preview()
        ->succeeded("projects/{$project->id}/manuals/{$manual->id}/previews/v2-1.mp4")->create();

    $response = $this->actingAs($owner)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}",
    );

    $response->assertOk();
    $body = (string) $response->getContent();
    expect($body)->not->toContain('output_path');
    expect($body)->not->toContain('previews/');
    expect($body)->not->toContain('signed.example');
});

test('ポーリング: 撮影者も 200 (view 権限)', function (): void {
    [$organization, , $project, $manual] = artifactAccessContext();
    $member = artifactAccessMember($organization, $project);
    $job = RenderJob::factory()->forManual($manual)->create();

    $this->actingAs($member)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}",
    )->assertOk();
});

test('ポーリング: cross-manual の renderJob は 404 (scopeBindings + inline 再検査)', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $otherManual = VideoManual::factory()->forProject($project)->create();
    $job = RenderJob::factory()->forManual($otherManual)->create();

    $this->actingAs($owner)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}",
    )->assertNotFound();
});

test('ポーリング: cross-org は 404', function (): void {
    [, , $project, $manual] = artifactAccessContext();
    $job = RenderJob::factory()->forManual($manual)->create();
    [, $stranger] = createOrganizationWithOwner('別組織');

    $this->actingAs($stranger)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}",
    )->assertNotFound();
});

test('playback: 最新 succeeded preview は 302 (S3 署名 URL へ redirect)', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $key = "projects/{$project->id}/manuals/{$manual->id}/previews/v2-9.mp4";
    $job = RenderJob::factory()->forManual($manual)->preview()->succeeded($key)->create();

    $response = $this->actingAs($owner)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    );

    $response->assertRedirect("https://signed.example/{$key}");
});

test('playback の 404 マトリクス: kind=render / 未完了 / output_path NULL / 旧世代', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $base = "/projects/{$project->id}/manuals/{$manual->id}/render-jobs";

    // kind=render の succeeded (download route が正)
    $renderJob = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
    $this->actingAs($owner)->get("{$base}/{$renderJob->id}/playback")->assertNotFound();

    // 未完了 preview
    $runningPreview = RenderJob::factory()->forManual($manual)->preview()->running()->create();
    $this->actingAs($owner)->get("{$base}/{$runningPreview->id}/playback")->assertNotFound();

    // output_path NULL (世代交代で削除済み)
    $nullPathPreview = RenderJob::factory()->forManual($manual)->preview()->create([
        'status' => 'succeeded',
        'output_path' => null,
    ]);
    $this->actingAs($owner)->get("{$base}/{$nullPathPreview->id}/playback")->assertNotFound();

    // 旧世代 (より新しい succeeded preview が存在する)
    $old = RenderJob::factory()->forManual($manual)->preview()->succeeded('projects/x/previews/v1-1.mp4')->create();
    RenderJob::factory()->forManual($manual)->preview()->succeeded('projects/x/previews/v2-2.mp4')->create();
    $this->actingAs($owner)->get("{$base}/{$old->id}/playback")->assertNotFound();
});

test('playback: 撮影者は 403 (render ability = 編集者専用)', function (): void {
    [$organization, , $project, $manual] = artifactAccessContext();
    $member = artifactAccessMember($organization, $project);
    $job = RenderJob::factory()->forManual($manual)->preview()->succeeded('projects/x/previews/v2-1.mp4')->create();

    $this->actingAs($member)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    )->assertForbidden();
});

test('download: published + 最新 succeeded render は 302 (署名 URL へ redirect)', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    $key = "projects/{$project->id}/manuals/{$manual->id}/renders/v2-1.mp4";
    RenderJob::factory()->forManual($manual)->succeeded($key)->create();

    $response = $this->actingAs($owner)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/download",
    );

    $response->assertRedirect("https://signed.example/{$key}");
});

test('download: lang=ja は 302 / lang=en は 422 / lang 省略は 302', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
    $url = "/projects/{$project->id}/manuals/{$manual->id}/download";

    $this->actingAs($owner)->get("{$url}?lang=ja")->assertRedirect();
    $this->actingAs($owner)->getJson("{$url}?lang=en")->assertUnprocessable();
    $this->actingAs($owner)->get($url)->assertRedirect();
});

test('download: published でない / succeeded render なしは 404 (完成物が存在しない)', function (): void {
    [, $owner, $project, $manual] = artifactAccessContext();
    $url = "/projects/{$project->id}/manuals/{$manual->id}/download";

    // ready (完成物なし)
    $this->actingAs($owner)->get($url)->assertNotFound();

    // published だが succeeded render がない (異常データ防御)
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    $this->actingAs($owner)->get($url)->assertNotFound();

    // succeeded だが output_path NULL
    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);
    $this->actingAs($owner)->get($url)->assertNotFound();
});

test('download: 撮影者は 403 (download ability = 編集者専用)', function (): void {
    [$organization, , $project, $manual] = artifactAccessContext();
    $member = artifactAccessMember($organization, $project);
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();

    $this->actingAs($member)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/download",
    )->assertForbidden();
});

test('download / playback: cross-org は 404 (存在オラクル封じ)', function (): void {
    [, , $project, $manual] = artifactAccessContext();
    $manual->forceFill(['status' => VideoManualStatus::Published])->save();
    $job = RenderJob::factory()->forManual($manual)->preview()->succeeded('projects/x/previews/v2-1.mp4')->create();
    [, $stranger] = createOrganizationWithOwner('別組織');

    $this->actingAs($stranger)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/download",
    )->assertNotFound();
    $this->actingAs($stranger)->get(
        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
    )->assertNotFound();
});
