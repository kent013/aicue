<?php

declare(strict_types=1);

use App\Enums\Manual\CutType;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\ShotType;
use App\Enums\Manual\VideoManualStatus;
use App\Models\AnalysisJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisPipeline;
use App\Support\Manual\ScenarioLimits;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;

/*
 * 導入/総括カットの materialize 不変条件 (T046)。
 * - 生成シナリオの前後に導入(先頭 top-level)/総括(末尾 top-level)が決定的に付与される
 * - 再解析は全置換 (導入/総括が重複しない・再掲元は今回生成のみ)
 * - MAX_STEPS(100) 生成 → top-level 102 が切り捨てなく materialize され編集 round-trip できる
 */

beforeEach(function (): void {
    // executeSync は fake 中も PromptExecutionCompleted を発火し FX 解決 (HTTP) を試みるため stray を防ぐ
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
});

/**
 * queued job 一式 (analyzing manual + 保存済み txt SOP + チケット残高)。
 *
 * @return array{Project, VideoManual, AnalysisJob, SourceDocument, User}
 */
function bookendPipelineContext(string $title = 'ネジ締め作業'): array
{
    Storage::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => 'analyzing',
        'title' => $title,
    ]);
    $path = "projects/{$project->id}/manuals/{$manual->id}/source-documents/sop.txt";
    Storage::put($path, str_repeat("手順: 部品を取り付けてネジを締める。急所: トルクは 5Nm。\n", 5));
    $document = SourceDocument::factory()->forManual($manual)->create([
        'file_path' => $path,
        'mime' => 'text/plain',
    ]);
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    app(TicketLedgerService::class)->grant($organization, 5, 'テスト残高');

    return [$project, $manual, $job, $document, $owner];
}

function bookendExtractJson(): string
{
    return json_encode([
        'header' => ['title' => 'SOP', 'department' => null, 'revision' => null],
        'sections' => [[
            'title' => null,
            'steps' => [[
                'no' => 1,
                'work_process' => 'ネジを締める',
                'work_points' => ['トルクレンチを使う'],
                'safety_points' => [],
                'quality_points' => ['トルク 5Nm'],
                'pm_points' => [],
            ]],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function bookendDecomposeJson(): string
{
    return json_encode([
        'steps' => [['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']]],
        'validation' => [
            'verdict' => 'valid',
            'reason' => '手順と急所が読み取れています。',
            'works' => ['ネジ締め作業'],
            'split_recommended' => false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * scenario-generation 出力 JSON を組み立てる。
 *
 * @param  list<array{primary: ?string, points?: list<?string>}>  $steps
 */
function bookendScenarioJson(array $steps): string
{
    $cuts = [];
    $no = 0;
    foreach ($steps as $step) {
        $no++;
        $stepNo = $no;
        $cuts[] = [
            'no' => $stepNo, 'type' => 'step', 'parent_no' => null,
            'scene' => "手順{$stepNo}のシーン", 'shot_type' => 'hiki', 'shooting_point' => null,
            'narration' => "手順{$stepNo}の説明", 'subtitle_primary' => $step['primary'],
            'subtitle_secondary' => "手順{$stepNo}の補足",
        ];
        foreach ($step['points'] ?? [] as $pointPrimary) {
            $no++;
            $cuts[] = [
                'no' => $no, 'type' => 'point', 'parent_no' => $stepNo,
                'scene' => '急所のシーン', 'shot_type' => 'yori', 'shooting_point' => '手元を大きく',
                'narration' => '急所の説明', 'subtitle_primary' => $pointPrimary,
                'subtitle_secondary' => '急所の補足',
            ];
        }
    }

    return json_encode(['cuts' => $cuts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** 3 段 (extract / decompose / generate) の Prompt fake を張る (generate は与えた scenario JSON)。 */
function bookendFakeLlm(string $scenarioJson): void
{
    Prompt::fake([
        TextResponseFake::make()->withText(bookendExtractJson()),
        TextResponseFake::make()->withText(bookendDecomposeJson()),
        TextResponseFake::make()->withText($scenarioJson),
    ]);
}

test('初回生成: 先頭 top-level=導入 / 末尾 top-level=総括 / 間に生成 step・point', function (): void {
    [, $manual, $job] = bookendPipelineContext('ネジ締め作業');
    bookendFakeLlm(bookendScenarioJson([
        ['primary' => '5Nm で締める', 'points' => ['トルク確認']],
    ]));

    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
    $manual->refresh();
    expect($manual->status)->toBe(VideoManualStatus::Ready);

    $topLevel = $manual->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get();
    // 導入 + 生成 step + 総括
    expect($topLevel)->toHaveCount(3);

    $intro = $topLevel->first();
    expect($intro->parent_cut_id)->toBeNull();
    expect($intro->type)->toBe(CutType::Step);
    expect($intro->shot_type)->toBe(ShotType::Hiki);
    expect($intro->narration)->toContain('ネジ締め作業');
    // 導入 scene は lang 由来 (文言変更耐性のため lang キーで照合)
    expect($intro->scene)->toBe(trans('manual.bookend.intro.scene', [], 'ja'));

    $summary = $topLevel->last();
    expect($summary->parent_cut_id)->toBeNull();
    expect($summary->type)->toBe(CutType::Step);
    expect($summary->shot_type)->toBe(ShotType::Hiki);
    expect($summary->scene)->toBe(trans('manual.bookend.summary.scene', [], 'ja'));
    // 総括 subtitle_secondary は生成 point 由来の再掲を含む
    expect($summary->subtitle_secondary)->toContain('トルク確認');

    // 生成 step は導入と総括の間 / 生成 point はその step 配下
    $generatedStep = $topLevel->get(1);
    expect($generatedStep->scene)->toBe('手順1のシーン');
    $point = $manual->cuts()->where('type', CutType::Point->value)->get();
    expect($point)->toHaveCount(1);
    expect($point->first()->parent_cut_id)->toBe($generatedStep->id);
});

test('再解析は全置換: 導入/総括が重複せず先頭1件・末尾1件のみ', function (): void {
    [, $manual, $job, $document] = bookendPipelineContext();
    // 事前に無関係な cut がある状態でも全置換される
    Cut::factory()->forManual($manual)->create();

    bookendFakeLlm(bookendScenarioJson([['primary' => '要点A', 'points' => []]]));
    app(AnalysisPipeline::class)->run($job->id);
    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);

    // 2 回目の解析 (新しい queued job を発行して再実行)
    $manual->refresh();
    $manual->forceFill(['status' => VideoManualStatus::Analyzing])->save();
    $job2 = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    bookendFakeLlm(bookendScenarioJson([['primary' => '要点B', 'points' => []]]));
    app(AnalysisPipeline::class)->run($job2->id);
    expect($job2->refresh()->status)->toBe(JobStatus::Succeeded);

    $topLevel = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get();
    // 導入 + 生成 step + 総括 = 3 (重複なし)
    expect($topLevel)->toHaveCount(3);
    expect($topLevel->first()->scene)->toBe(trans('manual.bookend.intro.scene', [], 'ja'));
    expect($topLevel->last()->scene)->toBe(trans('manual.bookend.summary.scene', [], 'ja'));
});

test('再生成の総括再掲は今回生成のみを参照する (旧 cut 不参照)', function (): void {
    [, $manual, $job, $document] = bookendPipelineContext();

    bookendFakeLlm(bookendScenarioJson([['primary' => '旧要点', 'points' => ['旧急所']]]));
    app(AnalysisPipeline::class)->run($job->id);
    $summary1 = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get()->last();
    expect($summary1->subtitle_secondary)->toContain('旧急所');

    $manual->forceFill(['status' => VideoManualStatus::Analyzing])->save();
    $job2 = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    bookendFakeLlm(bookendScenarioJson([['primary' => '新要点', 'points' => ['新急所']]]));
    app(AnalysisPipeline::class)->run($job2->id);

    $summary2 = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get()->last();
    expect($summary2->subtitle_secondary)->toContain('新急所');
    expect($summary2->subtitle_secondary)->not->toContain('旧急所');
});

test('生成 point / step subtitle が全欠なら総括は定型フォールバック文面', function (): void {
    [, $manual, $job] = bookendPipelineContext('配線作業');
    bookendFakeLlm(bookendScenarioJson([['primary' => null, 'points' => [null]]]));

    app(AnalysisPipeline::class)->run($job->id);
    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);

    $summary = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get()->last();
    expect($summary->subtitle_secondary)->toBe(trans('manual.bookend.summary.subtitle_secondary_fallback', [
        'title' => '配線作業',
    ], 'ja'));
});

test('MAX_STEPS(100) 生成 → top-level 102 が切り捨てなく materialize される', function (): void {
    [, $manual, $job] = bookendPipelineContext();
    $steps = [];
    for ($i = 1; $i <= ScenarioLimits::MAX_STEPS; $i++) {
        $steps[] = ['primary' => "要点{$i}", 'points' => []];
    }
    bookendFakeLlm(bookendScenarioJson($steps));

    app(AnalysisPipeline::class)->run($job->id);
    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);

    $topLevel = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get();
    // 導入 + 100 生成 step + 総括
    expect($topLevel)->toHaveCount(ScenarioLimits::MAX_TOP_LEVEL_CUTS);
    expect($topLevel->first()->scene)->toBe(trans('manual.bookend.intro.scene', [], 'ja'));
    expect($topLevel->last()->scene)->toBe(trans('manual.bookend.summary.scene', [], 'ja'));
});

test('materialize された 102 件 top-level を編集画面から再保存できる (MAX_TOP_LEVEL_CUTS 整合)', function (): void {
    [$project, $manual, $job, , $owner] = bookendPipelineContext();
    $steps = [];
    for ($i = 1; $i <= ScenarioLimits::MAX_STEPS; $i++) {
        $steps[] = ['primary' => "要点{$i}", 'points' => []];
    }
    bookendFakeLlm(bookendScenarioJson($steps));
    app(AnalysisPipeline::class)->run($job->id);

    $manual->refresh();
    $topLevel = $manual->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get();
    expect($topLevel)->toHaveCount(ScenarioLimits::MAX_TOP_LEVEL_CUTS);

    // 全 102 top-level を payload 化 (points は各 step 配下を復元)
    $payloadSteps = $topLevel->map(function (Cut $cut) use ($manual): array {
        $points = $manual->cuts()->where('parent_cut_id', $cut->id)->orderBy('sort_order')->get()
            ->map(fn (Cut $p): array => [
                'id' => $p->id,
                'scene' => $p->scene,
                'shot_type' => $p->shot_type->value,
                'shooting_point' => $p->shooting_point,
                'narration' => $p->narration,
                'subtitle_primary' => $p->subtitle_primary,
                'subtitle_secondary' => $p->subtitle_secondary,
                'material_type' => $p->material_type?->value,
                'static_display_seconds' => $p->static_display_seconds,
            ])->all();

        return [
            'id' => $cut->id,
            'scene' => $cut->scene,
            'shot_type' => $cut->shot_type->value,
            'shooting_point' => $cut->shooting_point,
            'narration' => $cut->narration,
            'subtitle_primary' => $cut->subtitle_primary,
            'subtitle_secondary' => $cut->subtitle_secondary,
            'material_type' => $cut->material_type?->value,
            'static_display_seconds' => $cut->static_display_seconds,
            'points' => $points,
        ];
    })->all();

    $version = $manual->scenario_version;
    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        ['expected_version' => $version, 'steps' => $payloadSteps],
    )->assertOk()->assertJsonPath('scenario_version', $version + 1);

    $manual->refresh();
    expect($manual->scenario_version)->toBe($version + 1);
    expect($manual->cuts()->whereNull('parent_cut_id')->count())->toBe(ScenarioLimits::MAX_TOP_LEVEL_CUTS);
});
