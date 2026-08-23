<?php

declare(strict_types=1);

use App\Enums\Manual\ScenarioVerdict;
use App\Enums\ProjectRole;
use App\Models\AnalysisJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\ScenarioReportBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * T200: 詳細画面の analysis.report props (生成結果の確認)。
 * - verdict = 最新 succeeded ジョブの validation_json (解析時点のスナップショット)
 * - counts / findings = 現在の cuts から決定的に算出 (常に最新)
 * - 壊れた保存値・旧ジョブは verdict=null で画面を落とさない
 */

/**
 * owner + project + manual (cuts 1 件つき) のセットアップ。
 *
 * @return array{User, Project, VideoManual}
 */
function scenarioReportContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $step = Cut::factory()->forManual($manual)->withSortOrder(0)->create([
        'narration' => 'バルブを閉じます。',
        'subtitle_primary' => 'バルブ閉',
        'subtitle_secondary' => '安全確認',
    ]);
    Cut::factory()->asPointOf($step)->withSortOrder(1)->create([
        'narration' => 'ハンドルが止まるまで回します。',
        'subtitle_primary' => '全閉',
        'subtitle_secondary' => '締め切り確認',
    ]);

    return [$organization, $owner, $project, $manual];
}

test('succeeded ジョブの validation_json が verdict props に出る', function (): void {
    [$organization, $owner, $project, $manual] = scenarioReportContext();
    $document = SourceDocument::factory()->forManual($manual)->create();
    AnalysisJob::factory()->forManual($manual)->forDocument($document)
        ->succeeded()->withValidation(ScenarioVerdict::NeedsReview, splitRecommended: true)->create();

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.report.verdict.verdict', 'needs_review')
            ->where('analysis.report.verdict.work_count', 1)
            ->where('analysis.report.verdict.split_recommended', true)
            ->where('analysis.report.verdict.is_current_document', true)
            ->where('analysis.report.counts.steps', 1)
            ->where('analysis.report.counts.points', 1)
            ->where('analysis.report.counts.total', 2)
            ->where('analysis.report.findings', []));
});

test('最新ジョブが failed でも前回 succeeded の所見を出す', function (): void {
    [$organization, $owner, $project, $manual] = scenarioReportContext();
    $document = SourceDocument::factory()->forManual($manual)->create();
    AnalysisJob::factory()->forManual($manual)->forDocument($document)
        ->succeeded()->withValidation()->create();
    AnalysisJob::factory()->forManual($manual)->forDocument($document)->failed()->create();

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.report.verdict.verdict', 'valid'));
});

test('validation_json が NULL の旧ジョブでは verdict=null だが counts/findings は出る', function (): void {
    [$organization, $owner, $project, $manual] = scenarioReportContext();
    AnalysisJob::factory()->forManual($manual)->succeeded()->create();

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.report.verdict', null)
            ->where('analysis.report.counts.total', 2));
});

test('壊れた validation_json でも 200 で verdict=null になり警告が 1 回残る', function (): void {
    [$organization, $owner, $project, $manual] = scenarioReportContext();
    $job = AnalysisJob::factory()->forManual($manual)->succeeded()->create();
    // 保存済みの値が壊れた状況 (cast を通さず生 JSON を書き込む)
    DB::table('analysis_jobs')->where('id', $job->id)
        ->update(['validation_json' => json_encode(['verdict' => 'broken'], JSON_THROW_ON_ERROR)]);
    Log::spy();

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('analysis.report.verdict', null));

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === '解析ジョブの妥当性所見の復元に失敗しました'
            && $context['analysis_job_id'] === $job->id
            && $context['failure_path'] === 'validation.verdict',
    )->once();
});

test('手順書を差し替えて未再解析なら is_current_document=false', function (): void {
    [$organization, $owner, $project, $manual] = scenarioReportContext();
    $analyzed = SourceDocument::factory()->forManual($manual)->create();
    AnalysisJob::factory()->forManual($manual)->forDocument($analyzed)
        ->succeeded()->withValidation()->create();
    SourceDocument::factory()->forManual($manual)->create(); // 差し替え (追記型)

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.report.verdict.is_current_document', false));
});

test('解析対象の手順書が消えている (source_document_id=null) なら is_current_document=false', function (): void {
    [$organization, $owner, $project, $manual] = scenarioReportContext();
    SourceDocument::factory()->forManual($manual)->create();
    AnalysisJob::factory()->forManual($manual)->succeeded()->withValidation()->create();

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.report.verdict.is_current_document', false));
});

test('複製直後 (解析ジョブなし・cuts あり) は verdict=null で counts は出る', function (): void {
    [$organization, $owner, $project, $manual] = scenarioReportContext();

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.report.verdict', null)
            ->where('analysis.report.counts.steps', 1));
});

test('cuts も所見も無ければ report は null (出す材料が無い)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page->where('analysis.report', null));
});

test('規約違反のある cuts では findings に件数と位置が載る', function (): void {
    [$organization, $owner, $project, $manual] = scenarioReportContext();
    Cut::factory()->forManual($manual)->withSortOrder(2)->create([
        'narration' => 'バルブを閉じてください',
        'subtitle_primary' => null,
        'subtitle_secondary' => '',
    ]);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('analysis.report.counts.steps', 2)
            ->where('analysis.report.findings', [
                ['code' => 'narration_not_polite', 'count' => 1, 'positions' => [['step' => 2, 'point' => null]]],
                ['code' => 'narration_directive', 'count' => 1, 'positions' => [['step' => 2, 'point' => null]]],
                ['code' => 'subtitle_secondary_missing', 'count' => 1, 'positions' => [['step' => 2, 'point' => null]]],
            ]));
});

test('撮影者 (canManage=false) でも report は props に載る (表示は情報提供であり操作ではない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);
    $manual = VideoManual::factory()->forProject($project)->create();
    Cut::factory()->forManual($manual)->withSortOrder(0)->create([
        'narration' => 'バルブを閉じます。',
        'subtitle_primary' => 'バルブ閉',
        'subtitle_secondary' => '安全確認',
    ]);
    AnalysisJob::factory()->forManual($manual)->succeeded()->withValidation()->create();
    expect($owner->can('update', $manual))->toBeTrue(); // 対照 (owner は編集可)

    $this->actingAs($member)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)
            ->where('analysis.report.verdict.verdict', 'valid'));
});

test('ScenarioReportBuilder のクエリ本数は cut 件数に依存しない (N+1 なし)', function (): void {
    [$organization, , $project, $manual] = scenarioReportContext();
    $document = SourceDocument::factory()->forManual($manual)->create();
    AnalysisJob::factory()->forManual($manual)->forDocument($document)
        ->succeeded()->withValidation()->create();

    $builder = app(ScenarioReportBuilder::class);
    $count = function (VideoManual $target) use ($builder): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $builder->build($target);

        return $queries;
    };

    $small = $count($manual->fresh());

    // 60 件の cut を持つ別 manual (同じ組織) で同じ本数になることを見る
    $large = VideoManual::factory()->forProject($project)->create();
    for ($i = 0; $i < 60; $i++) {
        Cut::factory()->forManual($large)->withSortOrder($i)->create([
            'narration' => 'バルブを閉じます。',
            'subtitle_primary' => 'バルブ閉',
            'subtitle_secondary' => '安全確認',
        ]);
    }
    $largeDocument = SourceDocument::factory()->forManual($large)->create();
    AnalysisJob::factory()->forManual($large)->forDocument($largeDocument)
        ->succeeded()->withValidation()->create();

    expect($count($large->fresh()))->toBe($small);
});

test('他組織の manual へは従来どおり 404 (props 追加で経路は変わらない)', function (): void {
    [, , , $manual] = scenarioReportContext();
    [$otherOrganization, $intruder] = createOrganizationWithOwner();
    $otherProject = Project::factory()->forOrganization($otherOrganization)->create();

    // 自分の組織の URL から他組織の manual は開けない (scopeBindings が認可より前に 404)
    $this->actingAs($intruder)
        ->get("/organizations/{$otherOrganization->slug}/projects/{$otherProject->id}/manuals/{$manual->id}")
        ->assertNotFound();
});
