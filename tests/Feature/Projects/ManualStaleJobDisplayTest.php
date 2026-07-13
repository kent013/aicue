<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\ScenarioSaveInput;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\VideoManualStatus;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\ScenarioService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * T032 施策3/4 (bug-hunt F-1-1): GET projects.manuals.show の Inertia props で
 * 「失敗確定後に scenario 保存が成立して version が進んだ」失敗 job を stale として抑制する。
 * 判定 = failed && snapshot!==null && manual.scenario_version > snapshot。
 */

/**
 * owner + project + manual のセットアップ。
 *
 * @return array{User, Project, VideoManual}
 */
function staleDisplayContext(int $scenarioVersion = 1, VideoManualStatus $status = VideoManualStatus::Ready): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => $status->value,
        'scenario_version' => $scenarioVersion,
    ]);

    return [$owner, $project, $manual];
}

test('HIGH: 解析失敗後に version が進むと analysis.job は null (stale 抑制)', function (): void {
    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 2);
    // 失敗確定は version=1 のとき → その後 save で version=2 まで進んだ
    AnalysisJob::factory()->forManual($manual)->failed()->create([
        'scenario_version_at_terminal' => 1,
    ]);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manuals/Show')
            ->where('analysis.job', null));
});

test('not stale: version が進んでいない失敗 job は表示する', function (): void {
    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 1);
    AnalysisJob::factory()->forManual($manual)->failed()->create([
        'scenario_version_at_terminal' => 1,
    ]);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.job.status', JobStatus::Failed->value));
});

test('legacy: snapshot=null の失敗 job は null 化されない (保守的に表示)', function (): void {
    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 5);
    AnalysisJob::factory()->forManual($manual)->failed()->create([
        'scenario_version_at_terminal' => null,
    ]);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.job.status', JobStatus::Failed->value));
});

test('render 失敗後に version が進むと render.job は null', function (): void {
    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 3);
    RenderJob::factory()->forManual($manual)->failed()->create([
        'scenario_version' => 2,
        'scenario_version_at_terminal' => 2,
    ]);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('render.job', null));
});

test('scenario_version_changed CTA: snapshot が現行 version と一致なら render.job を保持', function (): void {
    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 4);
    RenderJob::factory()->forManual($manual)
        ->failed(RenderErrorCode::ScenarioVersionChanged)
        ->create([
            'scenario_version' => 4,
            'scenario_version_at_terminal' => 4,
        ]);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('render.job.status', JobStatus::Failed->value)
            ->where('render.job.error_code', RenderErrorCode::ScenarioVersionChanged->value));
});

test('succeeded は version が進んでも抑制されない', function (): void {
    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 9);
    AnalysisJob::factory()->forManual($manual)->succeeded()->create([
        'scenario_version_at_terminal' => 1,
    ]);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.job.status', JobStatus::Succeeded->value));
});

test('preview 独立: preview 失敗が stale でも playbackJobId は succeeded preview を維持', function (): void {
    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 2);
    // 古い succeeded preview (再生可能) と、その後の失敗 preview (stale)
    $playable = RenderJob::factory()->forManual($manual)->preview()
        ->succeeded('previews/out.mp4')->create(['scenario_version' => 1]);
    RenderJob::factory()->forManual($manual)->preview()->failed()->create([
        'scenario_version' => 1,
        'scenario_version_at_terminal' => 1,
    ]);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('render.previewJob', null)
            ->where('render.playbackJobId', $playable->id));
});

test('統合: ScenarioService::save の実経路 (no-op 保存) で version++ すると解析失敗が stale 化', function (): void {
    // 保存世代基準の契約 (no-op でも version++ で stale) を実経路で固定する
    [$owner, $project, $manual] = staleDisplayContext(scenarioVersion: 0);
    AnalysisJob::factory()->forManual($manual)->failed()->create([
        'scenario_version_at_terminal' => 0,
    ]);

    // cuts 無しの no-op 保存 (内容無変更でも version=1 へ)
    app(ScenarioService::class)->save($project, $manual, new ScenarioSaveInput(0, []));
    expect($manual->refresh()->scenario_version)->toBe(1);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.job', null));
});
