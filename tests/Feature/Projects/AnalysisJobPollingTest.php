<?php

declare(strict_types=1);

use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\VideoManual;

/*
 * job 状態ポーリング (GET .../manuals/{manual}/jobs/{analysisJob}):
 * - AnalysisJobResource の shape (id/status/step/progress/error/manual_status)
 * - {analysisJob} ∈ {manual} は scopeBindings (cross-manual は 404)
 * - 閲覧権限 (view) のみ (撮影者 200 は ManualAnalyzeTest で検証済み)
 */

test('200: AnalysisJobResource の shape を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
    $job = AnalysisJob::factory()->forManual($manual)->running()->create();

    $this->actingAs($owner)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/jobs/{$job->id}",
    )->assertOk()->assertExactJson([
        'id' => $job->id,
        'status' => 'running',
        'step' => 'extract',
        'progress' => 10,
        'error' => null,
        'manual_status' => 'analyzing',
    ]);
});

test('failed job は error を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $job = AnalysisJob::factory()->forManual($manual)->failed('解析に失敗しました')->create();

    $this->actingAs($owner)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/jobs/{$job->id}",
    )->assertOk()->assertJson([
        'status' => 'failed',
        'error' => '解析に失敗しました',
        'manual_status' => 'draft',
    ]);
});

test('他 manual の job id は 404 (scopeBindings。存在を漏らさない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $otherManual = VideoManual::factory()->forProject($project)->create();
    $otherJob = AnalysisJob::factory()->forManual($otherManual)->create();

    $this->actingAs($owner)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/jobs/{$otherJob->id}",
    )->assertNotFound();
});

test('cross-org の job への GET は 404', function (): void {
    [, $stranger] = createOrganizationWithOwner('別組織');
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $job = AnalysisJob::factory()->forManual($manual)->create();

    $this->actingAs($stranger)->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/jobs/{$job->id}",
    )->assertNotFound();
});

test('未ログインは 401 (JSON)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $job = AnalysisJob::factory()->forManual($manual)->create();

    $this->getJson(
        "/projects/{$project->id}/manuals/{$manual->id}/jobs/{$job->id}",
    )->assertUnauthorized();
});
