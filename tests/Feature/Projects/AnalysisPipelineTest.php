<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\CutType;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\ShotType;
use App\Enums\Manual\VideoManualStatus;
use App\Models\AnalysisJob;
use App\Models\Billing\TicketReservation;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\AnalysisPipeline;
use App\Services\Manual\ScenarioService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;

/*
 * AI 解析パイプライン (AnalysisPipeline::run の直接呼び出し。§10.8-1 / 概念設計 §4-5):
 * - 成功パス: materialize + commit + succeeded の原子化 (terminal tx)
 * - 予約の冪等 (再利用 / TTL 切れ付け替え) と失敗時 release
 * - 有界リトライ (JSON 検証失敗のみ・最大 2 回)
 * - stale 回復 cron とのインターリーブ (「failed ∧ committed」「succeeded ∧ released」の非共存)
 */

beforeEach(function (): void {
    // executeSync は fake 中も PromptExecutionCompleted を発火し、listener が FX 解決 (HTTP) を
    // 試みるため stray request を防ぐ
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
});

/**
 * queued job 一式 (analyzing manual + 保存済み txt SOP + チケット残高)。
 *
 * @return array{Organization, User, Project, VideoManual, SourceDocument, AnalysisJob}
 */
function pipelineContext(int $tickets = 1): array
{
    Storage::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
    $path = "projects/{$project->id}/manuals/{$manual->id}/source-documents/sop.txt";
    Storage::put($path, str_repeat("手順: 部品を取り付けてネジを締める。急所: トルクは 5Nm。\n", 5));
    $document = SourceDocument::factory()->forManual($manual)->create([
        'file_path' => $path,
        'mime' => 'text/plain',
    ]);
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    if ($tickets > 0) {
        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
    }

    return [$organization, $owner, $project, $manual, $document, $job];
}

function extractFixture(): string
{
    return json_encode([
        'header' => ['title' => 'ネジ締め SOP', 'department' => null, 'revision' => null],
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

function decompositionFixture(): string
{
    return json_encode([
        'steps' => [
            ['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function scenarioFixture(): string
{
    return json_encode([
        'cuts' => [
            [
                'no' => 1, 'type' => 'step', 'parent_no' => null,
                'scene' => 'ネジ締めの全体', 'shot_type' => 'hiki', 'shooting_point' => null,
                'narration' => 'ネジを締めます', 'subtitle_primary' => null, 'subtitle_secondary' => 'ネジ締め',
            ],
            [
                'no' => 2, 'type' => 'point', 'parent_no' => 1,
                'scene' => 'トルクレンチの目盛り', 'shot_type' => 'yori', 'shooting_point' => '手元を大きく写す',
                'narration' => 'トルクは 5Nm です', 'subtitle_primary' => '5Nm', 'subtitle_secondary' => '締め付けトルク',
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** 成功 3 段の Prompt fake を張る */
function fakeSuccessfulLlm(): void
{
    Prompt::fake([
        TextResponseFake::make()->withText(extractFixture()),
        TextResponseFake::make()->withText(decompositionFixture()),
        TextResponseFake::make()->withText(scenarioFixture()),
    ]);
}

test('成功パス: cuts materialize / ready / version+1 / succeeded / committed / スナップショット保存', function (): void {
    [$organization, , , $manual, $document, $job] = pipelineContext();
    fakeSuccessfulLlm();

    app(AnalysisPipeline::class)->run($job->id);

    // job: succeeded + progress 100
    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    expect($job->progress)->toBe(100);
    expect($job->error)->toBeNull();

    // manual: cuts ツリー (step + point) / ready / version+1
    $manual->refresh();
    expect($manual->status)->toBe(VideoManualStatus::Ready);
    expect($manual->scenario_version)->toBe(1);
    // 導入 + 生成 step + 生成 point + 総括 = 4 (top-level は 導入/生成 step/総括 の 3)
    $cuts = $manual->cuts()->orderBy('sort_order')->get();
    expect($cuts)->toHaveCount(4);
    $topLevel = $cuts->where('parent_cut_id', null)->values();
    expect($topLevel)->toHaveCount(3);
    // 先頭=導入 / 末尾=総括 (位置・型・shot_type・親子を退行検出)
    $intro = $topLevel->first();
    expect($intro->parent_cut_id)->toBeNull();
    expect($intro->shot_type)->toBe(ShotType::Hiki);
    expect($intro->narration)->toContain($manual->title);
    $summary = $topLevel->last();
    expect($summary->parent_cut_id)->toBeNull();
    expect($summary->shot_type)->toBe(ShotType::Hiki);
    // 生成 step は導入と総括の間 / 生成 point はその step 配下 (fixture 由来の本文は不変)
    $step = $topLevel->get(1);
    $point = $cuts->firstWhere('type', CutType::Point);
    expect($step->type)->toBe(CutType::Step);
    expect($point)->not->toBeNull();
    expect($point->parent_cut_id)->toBe($step->id);
    expect($step->scene)->toBe('ネジ締めの全体');
    expect($point->subtitle_primary)->toBe('5Nm');

    // 課金: 予約 committed / 残高 0 (1 ジョブ 1 予約)
    $reservation = $job->ticketReservation;
    expect($reservation)->not->toBeNull();
    expect($reservation->status)->toBe(TicketReservationStatus::Committed);
    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
    expect(TicketReservation::query()->count())->toBe(1);

    // 監査スナップショット
    expect($document->refresh()->extracted_json)->toHaveKey('sections');
    expect($job->result_json)->toHaveKey('steps');
});

test('再試行で二重予約しない (有効な Reserved は再利用) + queued guard の no-op', function (): void {
    [$organization, , , , , $job] = pipelineContext();
    // 事前に予約を付けた queued job (前回試行が dispatch 直後に死んだ状況)
    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
    $job->ticketReservation()->associate($reservation);
    $job->save();

    fakeSuccessfulLlm();
    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
    expect(TicketReservation::query()->count())->toBe(1); // reserve が増えない
    expect($job->ticket_reservation_id)->toBe($reservation->id);

    // succeeded 済み job の再配送は no-op (queued guard)
    app(AnalysisPipeline::class)->run($job->id);
    expect(TicketReservation::query()->count())->toBe(1);
});

test('TTL 切れ Reserved は release して付け替え、新予約で完走する', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [$organization, , , , , $job] = pipelineContext();
    $stale = app(TicketLedgerService::class)->reserve($organization, 1);
    $job->ticketReservation()->associate($stale);
    $job->save();

    // TTL (30 分) を超過させる (cron 未回収の失効 Reserved)
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    fakeSuccessfulLlm();
    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    expect($job->ticket_reservation_id)->not->toBe($stale->id);
    expect($stale->refresh()->status)->toBe(TicketReservationStatus::Released);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Committed);
});

test('LLM が 3 回不正 JSON → failed + manual 復帰 (cuts 無し = draft) + 予約 released', function (): void {
    [, , , $manual, , $job] = pipelineContext();
    Prompt::fake([
        TextResponseFake::make()->withText('これは JSON ではありません'),
        TextResponseFake::make()->withText('{"broken": '),
        TextResponseFake::make()->withText('まだ不正'),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe('AI の応答を解釈できませんでした。再実行してください。');
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Draft);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
    // 非共存: failed ∧ committed を作らない
    expect(TicketReservation::query()->where('status', TicketReservationStatus::Committed)->count())->toBe(0);
});

test('有界リトライ: 不正 JSON ×2 → 3 回目成功で succeeded', function (): void {
    [, , , , , $job] = pipelineContext();
    Prompt::fake([
        TextResponseFake::make()->withText('不正 1'),
        TextResponseFake::make()->withText('不正 2'),
        TextResponseFake::make()->withText(extractFixture()),   // 3 回目で成功
        TextResponseFake::make()->withText(decompositionFixture()),
        TextResponseFake::make()->withText(scenarioFixture()),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
});

test('コードフェンス付き JSON も受理する (LlmJson::decode)', function (): void {
    [, , , , , $job] = pipelineContext();
    Prompt::fake([
        TextResponseFake::make()->withText("```json\n".extractFixture()."\n```"),
        TextResponseFake::make()->withText(decompositionFixture()),
        TextResponseFake::make()->withText(scenarioFixture()),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
});

test('残高不足で startJob が失敗 → failed (予約なし・LLM 呼び出しなし)', function (): void {
    [, , , $manual, , $job] = pipelineContext(tickets: 0);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toContain('チケット残高が不足しています');
    expect($job->ticket_reservation_id)->toBeNull();
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Draft);
});

test('再解析の失敗は既存 cuts があれば ready へ復帰する', function (): void {
    [, , , $manual, , $job] = pipelineContext();
    Cut::factory()->forManual($manual)->create(); // 前回解析の cuts が残っている

    Prompt::fake([
        TextResponseFake::make()->withText('不正'),
        TextResponseFake::make()->withText('不正'),
        TextResponseFake::make()->withText('不正'),
    ]);
    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
    expect($manual->cuts()->count())->toBe(1); // 既存 cuts は不変 (materialize 前に失敗)
});

test('インターリーブ (a): cron 先勝ち (failed) 後の finalize は no-op (無課金 succeeded 排除)', function (): void {
    [$organization, , , $manual, , $job] = pipelineContext();
    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
    $job->ticketReservation()->associate($reservation);
    $job->status = JobStatus::Running;
    $job->save();

    // stale 回復 cron が先勝ち: failed + released + manual 復帰
    app(AnalysisJobService::class)->failJob($job, '解析がタイムアウトしました。再実行してください。');
    expect($job->refresh()->status)->toBe(JobStatus::Failed);

    // 遅れて完走した pipeline の terminal tx 相当を実行 → status guard で全て no-op
    $generated = GeneratedScenarioData::fromLlmText(scenarioFixture());
    $finalize = new ReflectionMethod(AnalysisPipeline::class, 'finalize');
    $finalize->invoke(app(AnalysisPipeline::class), $job, $generated);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);              // succeeded に上書きされない
    expect($manual->refresh()->cuts()->count())->toBe(0);        // materialize されない
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released); // commit されない
});

test('インターリーブ (b): pipeline 先勝ち (succeeded) 後の failJob は no-op', function (): void {
    [, , , $manual, , $job] = pipelineContext();
    fakeSuccessfulLlm();
    app(AnalysisPipeline::class)->run($job->id);
    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);

    // 後追いの cron / failed() フック相当
    app(AnalysisJobService::class)->failJob($job, '解析がタイムアウトしました。再実行してください。');

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
    // 非共存: succeeded ∧ released を作らない
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Committed);
});

test('インターリーブ (d): commit は Reserved のみ → terminal tx 全体 rollback (cuts 不変)', function (): void {
    [$organization, , , $manual, , $job] = pipelineContext();
    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
    $job->ticketReservation()->associate($reservation);
    $job->status = JobStatus::Running;
    $job->save();
    // finalize 直前に予約が Released になる競合を細工 (releaseStale cron 相当)
    app(TicketLedgerService::class)->release($reservation);

    $generated = GeneratedScenarioData::fromLlmText(scenarioFixture());
    $finalize = new ReflectionMethod(AnalysisPipeline::class, 'finalize');
    expect(fn () => $finalize->invoke(app(AnalysisPipeline::class), $job, $generated))
        ->toThrow(LogicException::class);

    // terminal tx 全体が rollback: materialize も succeeded も残らない
    $job->refresh();
    expect($job->status)->toBe(JobStatus::Running);
    expect($manual->refresh()->cuts()->count())->toBe(0);
    expect($manual->status)->toBe(VideoManualStatus::Analyzing);
    // 非共存: 課金 (committed) は発生していない
    expect(TicketReservation::query()->where('status', TicketReservationStatus::Committed)->count())->toBe(0);
});

test('materialize は analyzing 以外で呼ぶと LogicException (defensive 二層目)', function (): void {
    // 注: tx 外呼び出しの guard (transactionLevel === 0) は RefreshDatabase が
    // テスト全体を tx で包むためテストでは踏めない (静的走査 = ScenarioWritePathInventoryTest が
    // 呼び出し経路を AnalysisPipeline::finalize に限定することで担保)
    [, , , $manual] = pipelineContext();
    $steps = GeneratedScenarioData::fromLlmText(scenarioFixture())->toScenarioSteps();
    $scenarios = app(ScenarioService::class);

    $manual->forceFill(['status' => VideoManualStatus::Draft])->save();
    expect(fn () => DB::transaction(
        fn () => $scenarios->materializeIntoLockedManual($manual, $steps),
    ))->toThrow(LogicException::class, 'analyzing 中のみ');
});

test('再解析の materialize は既存 cuts を全置換する (旧 cut id が消える)', function (): void {
    [, , , $manual, , $job] = pipelineContext();
    $oldCut = Cut::factory()->forManual($manual)->create();

    fakeSuccessfulLlm();
    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
    $manual->refresh();
    // 導入 + 生成 step + 生成 point + 総括 = 4 (旧 cut は全置換で消える)
    expect($manual->cuts()->count())->toBe(4);
    expect($manual->cuts()->whereKey($oldCut->id)->exists())->toBeFalse();
});

test('実質空の SOP は failed + tooShort 文言 (画像未対応と弁別)', function (): void {
    [, , , , $document, $job] = pipelineContext();
    Storage::put($document->file_path, '短すぎ'); // min_text_bytes (100) 未満

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    // 抽出はできたが本文が短いケース → 画像/スキャン (unextractable) とは別文言 (T032 F-1-2)
    expect($job->error)->toBe('手順書の本文が短すぎます。もう少し詳しい手順書をアップロードしてください。');
});

test('バイト上限超過の SOP は failed (分割を促す文言)', function (): void {
    config()->set('manual.analysis_max_text_bytes', 200);
    [, , , , $document, $job] = pipelineContext();

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe('手順書が大きすぎます。分割してアップロードしてください。');
});
