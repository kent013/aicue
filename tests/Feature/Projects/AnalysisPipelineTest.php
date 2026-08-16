<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\CutType;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\ShotType;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\Security\ExternalCallKind;
use App\Models\AnalysisJob;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Billing\TicketReservation;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\User;
use App\Models\VideoManual;
use App\Notifications\InApp\ManualAnalyzedNotification;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\AnalysisPipeline;
use App\Services\Manual\ScenarioService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Support\PrismHttpExceptionFactory;
use Tests\Support\ThrowingPromptFake;

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

/**
 * work-decomposition 応答 ({steps, validation})。上書きしたいキーだけ差し替える
 * (validation を欠落・破損させたケースを組み立てるため)。
 *
 * @param  array<string, mixed>  $overrides
 */
function decompositionFixture(array $overrides = []): string
{
    return json_encode([...[
        'steps' => [
            ['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']],
        ],
        'validation' => [
            'verdict' => 'needs_review',
            'reason' => 'トルク値は読み取れましたが工具の指定が曖昧です。',
            'works' => ['ネジ締め作業'],
            'split_recommended' => false,
        ],
    ], ...$overrides], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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

/**
 * 成功 3 段の応答 script (ThrowingPromptFake に例外と混ぜて渡す)。
 *
 * @return list<TextResponseFake>
 */
function successfulLlmScript(): array
{
    return [
        TextResponseFake::make()->withText(extractFixture()),
        TextResponseFake::make()->withText(decompositionFixture()),
        TextResponseFake::make()->withText(scenarioFixture()),
    ];
}

/** 成功 3 段の Prompt fake を張る */
function fakeSuccessfulLlm(): void
{
    Prompt::fake(successfulLlmScript());
}

/**
 * 例外を混ぜた script で ThrowingPromptFake を install する (installFake = 公開注入点)。
 *
 * @param  list<TextResponseFake|Throwable>  $script
 * @param  ?Closure(int):void  $onAttempt
 */
function installThrowingLlm(array $script, ?Closure $onAttempt = null): ThrowingPromptFake
{
    $fake = new ThrowingPromptFake($script, $onAttempt);
    Prompt::installFake($fake);

    return $fake;
}

/** 施策 4 のユーザー向け文言 (analysis_jobs.error の回帰固定) */
const ANALYSIS_TIMED_OUT_MESSAGE = '解析が時間内に終わりませんでした。手順書を分割して短くするか、しばらく時間をおいて再実行してください。';

const ANALYSIS_PROVIDER_BUSY_MESSAGE = 'AI が混み合っています。しばらく時間をおいて再実行してください。';

const ANALYSIS_GENERIC_FAILURE_MESSAGE = '解析に失敗しました。時間をおいて再実行してください。';

/** T131 / S2: stale 回復 cron が先着で書く文言 (preflight 経路が上書きしないことの固定に使う) */
const STALE_CRON_ERROR_MESSAGE = 'stale 回復 cron が失敗確定しました。';

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
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    expect(TicketReservation::query()->count())->toBe(1);

    // 監査スナップショット
    expect($document->refresh()->extracted_json)->toHaveKey('sections');
    expect($job->result_json)->toHaveKey('steps');

    // 手順書への所見 (表示契約)。result_json とは別カラムで、互いに混ざらない
    expect($job->validation_json)->toBe([
        'verdict' => 'needs_review',
        'reason' => 'トルク値は読み取れましたが工具の指定が曖昧です。',
        'works' => ['ネジ締め作業'],
        'split_recommended' => false,
    ]);
    expect($job->result_json)->not->toHaveKey('validation');
});

test('3 段目へ渡す入力 JSON に validation は含まれない (所見を次段に混ぜない)', function (): void {
    [, , , , , $job] = pipelineContext();
    fakeSuccessfulLlm();

    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
    // 3 段目 (scenario-generation) のプロンプトへ実際に載った本文を読む
    $fake = Prompt::getFake();
    expect($fake)->not->toBeNull();
    $recorded = $fake->recorded();
    expect($recorded)->toHaveCount(3);

    $generateText = '';
    foreach ($recorded[2]['messages'] as $message) {
        if ($message instanceof UserMessage) {
            $generateText .= $message->text()."\n";
        }
    }

    expect($generateText)->toContain('ネジを締める');       // 作業分解表は渡る
    expect($generateText)->not->toContain('needs_review');  // 所見は渡らない
    expect($generateText)->not->toContain('split_recommended');
});

test('validation 不正は有界リトライののち failed (validation_json は NULL のまま)', function (): void {
    [, , , , , $job] = pipelineContext();
    $brokenDecomposition = decompositionFixture(['validation' => ['verdict' => 'unknown']]);
    Prompt::fake([
        TextResponseFake::make()->withText(extractFixture()),
        TextResponseFake::make()->withText($brokenDecomposition),
        TextResponseFake::make()->withText($brokenDecomposition),
        TextResponseFake::make()->withText($brokenDecomposition),
    ]);
    Log::spy();

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->validation_json)->toBeNull();
    expect($job->result_json)->toBeNull(); // 所見が通らない限り作業分解表も保存しない (1 応答 1 保存)

    // 再試行ログに違反位置が載る (validation 起因かを集計で分けられる)
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'AI 解析の LLM 呼び出しを再試行します'
            && $context['failure_category'] === 'schema_violation'
            && is_string($context['failure_path'])
            && str_starts_with($context['failure_path'], 'validation.'),
    );
    // 最終失敗にも同じ観測キーが残る (再試行ログとは別の 1 行)
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'AI 解析が LLM 応答のスキーマ違反で失敗しました'
            && $context['analysis_job_id'] === $job->id
            && $context['failure_category'] === 'schema_violation'
            && $context['failure_path'] === 'validation.verdict',
    )->once();
});

test('validation キーの欠落そのものも failed になる (failure_path=validation)', function (): void {
    [, , , , , $job] = pipelineContext();
    // 旧プロンプト時代の応答形 ({steps} だけ) が返ってきた状況
    $withoutValidation = json_encode([
        'steps' => [['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    Prompt::fake([
        TextResponseFake::make()->withText(extractFixture()),
        TextResponseFake::make()->withText($withoutValidation),
        TextResponseFake::make()->withText($withoutValidation),
        TextResponseFake::make()->withText($withoutValidation),
    ]);
    Log::spy();

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->validation_json)->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'AI 解析が LLM 応答のスキーマ違反で失敗しました'
            && $context['failure_path'] === 'validation',
    )->once();
});

test('steps 側の違反は failure_path が steps. で始まる (validation 側と識別できる)', function (): void {
    [, , , , , $job] = pipelineContext();
    $brokenSteps = decompositionFixture(['steps' => [['no' => 1, 'action' => '', 'points' => []]]]);
    Prompt::fake([
        TextResponseFake::make()->withText(extractFixture()),
        TextResponseFake::make()->withText($brokenSteps),
        TextResponseFake::make()->withText($brokenSteps),
        TextResponseFake::make()->withText($brokenSteps),
    ]);
    Log::spy();

    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'AI 解析が LLM 応答のスキーマ違反で失敗しました'
            && $context['failure_path'] === 'steps.0.action',
    )->once();
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

test('インターリーブ (d): stale releaser 先着でも finalize は完走し課金される (commit-wins)', function (): void {
    // P5 commit-wins: 守るべき不変条件は「succeeded ∧ released の非共存」ではなく
    // 「succeeded ∧ 無課金 (= 成果物を渡してタダ乗り) の非共存」。予約 status が Released でも
    // 台帳に消費行が立てば課金は成立する (課金の真実源は台帳。status は一方向遷移を壊さない)
    [$organization, , , $manual, , $job] = pipelineContext();
    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
    $job->ticketReservation()->associate($reservation);
    $job->status = JobStatus::Running;
    $job->save();
    // finalize 直前に予約が Released になる競合を細工 (滞留予約の解放と同じ状況)
    app(TicketLedgerService::class)->release($reservation);

    $generated = GeneratedScenarioData::fromLlmText(scenarioFixture());
    $finalize = new ReflectionMethod(AnalysisPipeline::class, 'finalize');
    $finalize->invoke(app(AnalysisPipeline::class), $job, $generated);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    expect($manual->refresh()->cuts()->count())->toBeGreaterThan(0);
    // 非共存: succeeded なのに無課金、にはならない (消費行が立っている)
    expect(TicketLedgerEntry::query()
        ->where('reservation_id', $reservation->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->count())->toBe(1);
    // 一方向遷移は壊さない (Released → Committed へは戻さない)
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
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

/*
 * ─────────────────────────────────────────────────────────────────────
 * T091: transient な provider/connection 例外の有界リトライ + 実時間 deadline
 * (概念設計 devnotes/20260804-0021-analysis-provider-retry)
 * ─────────────────────────────────────────────────────────────────────
 */

test('(A) transient: ConnectionException ×1 → 2 回目成功で succeeded (予約は 1 件 Committed)', function (): void {
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm([
        new ConnectionException('cURL error 28: Operation timed out'),
        ...successfulLlmScript(),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
    expect($fake->attemptCount())->toBe(4); // extract ×2 (1 失敗 + 1 成功) + decompose + generate
    expect(TicketReservation::query()->count())->toBe(1);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Committed);
});

test('(A) transient: 529 overloaded ×1 → 2 回目成功で succeeded', function (): void {
    [, , , , , $job] = pipelineContext();
    installThrowingLlm([
        PrismProviderOverloadedException::make('anthropic'),
        ...successfulLlmScript(),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
});

test('(A) transient: generic PrismException (previous 503) ×1 → 2 回目成功で succeeded', function (): void {
    [, , , , , $job] = pipelineContext();
    installThrowingLlm([
        PrismHttpExceptionFactory::withStatus(503),
        ...successfulLlmScript(),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
});

test('(A) transient: ConnectionException ×3 (試行上限) → failed + timeout 文言 + 予約 Released', function (): void {
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm([
        new ConnectionException('x'),
        new ConnectionException('x'),
        new ConnectionException('x'),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(ANALYSIS_TIMED_OUT_MESSAGE);
    expect($fake->attemptCount())->toBe(3); // 1 + analysis_llm_max_retries
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
    expect(TicketReservation::query()->where('status', TicketReservationStatus::Committed)->count())->toBe(0);
});

test('(A) transient: 503 ×3 (試行上限) → failed + providerBusy 文言 + 予約 Released', function (): void {
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm([
        PrismHttpExceptionFactory::withStatus(503),
        PrismHttpExceptionFactory::withStatus(503),
        PrismHttpExceptionFactory::withStatus(503),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(ANALYSIS_PROVIDER_BUSY_MESSAGE);
    expect($fake->attemptCount())->toBe(3);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
});

test('(A) transient: generic PrismException (previous 408) ×3 → failed + timeout 文言', function (): void {
    // 408 は retryable かつ「時間切れ」系 (混雑系 500/502/503/504 とは文言が分かれる)
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm([
        PrismHttpExceptionFactory::withStatus(408),
        PrismHttpExceptionFactory::withStatus(408),
        PrismHttpExceptionFactory::withStatus(408),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(ANALYSIS_TIMED_OUT_MESSAGE);
    expect($fake->attemptCount())->toBe(3); // 再試行された
});

test('(B) 非 retryable: 429 rate limited は 1 回で failed (providerBusy 文言)', function (): void {
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm([PrismRateLimitedException::make()]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(ANALYSIS_PROVIDER_BUSY_MESSAGE);
    expect($fake->attemptCount())->toBe(1); // リトライしない
});

test('(B) 非 retryable: 413 request too large は 1 回で failed (分割を促す文言)', function (): void {
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm([PrismRequestTooLargeException::make('anthropic')]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe('手順書が大きすぎます。分割してアップロードしてください。');
    expect($fake->attemptCount())->toBe(1);
});

test('(B) 非 retryable: generic PrismException (previous 400) は 1 回で failed (汎用文言)', function (): void {
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm([PrismHttpExceptionFactory::withStatus(400)]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(ANALYSIS_GENERIC_FAILURE_MESSAGE);
    expect($fake->attemptCount())->toBe(1);
});

test('(B) 非 retryable: previous 無しの PrismException は 1 回で failed (status 判定不能 = fail-fast)', function (): void {
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm([PrismHttpExceptionFactory::withoutPrevious()]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(ANALYSIS_GENERIC_FAILURE_MESSAGE);
    expect($fake->attemptCount())->toBe(1);
});

test('(C) deadline 0 なら LLM を 1 回も呼ばずに failed + timeout 文言 + 予約 Released', function (): void {
    config()->set('manual.analysis_deadline_seconds', 0);
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm(successfulLlmScript());

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(ANALYSIS_TIMED_OUT_MESSAGE);
    expect($fake->attemptCount())->toBe(0); // 試行を開始しない
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
});

test('(C) deadline 直前でもフル試行が許される (残り時間を client timeout に反映しない)', function (): void {
    // D + C モデルの固定: 残り 1 秒でも試行を「開始」する (開始可否だけを deadline が決める)
    $this->travelTo(CarbonImmutable::parse('2026-08-04 00:00:00'));
    config()->set('manual.analysis_deadline_seconds', 1);
    [, , , , , $job] = pipelineContext();
    installThrowingLlm(successfulLlmScript()); // 時計を進めない = deadline 前のまま

    app(AnalysisPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
});

test('(C) リトライ中に deadline を超えたら試行上限より手前で打ち切る', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-04 00:00:00'));
    config()->set('manual.analysis_deadline_seconds', 100);
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm(
        script: [
            new ConnectionException('x'),
            new ConnectionException('x'),
            new ConnectionException('x'),
        ],
        onAttempt: function (int $attempt): void {
            $this->travel(60)->seconds();
        },
    );

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(ANALYSIS_TIMED_OUT_MESSAGE);
    // 2 回目の試行後に仮想時計が deadline (100s) を超えるため 3 回目は開始されない
    expect($fake->attemptCount())->toBe(2);
});

test('(D) 会計: リトライして succeeded でも予約 1 件・commit 1 件のみ (二重課金なし)', function (): void {
    [$organization, , , , , $job] = pipelineContext();
    installThrowingLlm([
        new ConnectionException('x'),
        PrismHttpExceptionFactory::withStatus(502),
        ...successfulLlmScript(),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    // 予約の冪等キー (analysis_jobs.ticket_reservation_id) はリトライで増えない
    expect(TicketReservation::query()->count())->toBe(1);
    $reservation = $job->ticketReservation;
    expect($reservation?->status)->toBe(TicketReservationStatus::Committed);
    expect(TicketLedgerEntry::query()
        ->where('reservation_id', $reservation?->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->count())->toBe(1);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
});

test('(D) 会計: リトライして failed なら予約は Released で commit は 0 件 (課金済み failed なし)', function (): void {
    [$organization, , , , , $job] = pipelineContext();
    installThrowingLlm([
        new ConnectionException('x'),
        new ConnectionException('x'),
        new ConnectionException('x'),
    ]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect(TicketReservation::query()->count())->toBe(1);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::ReserveCommit)->count())->toBe(0);
    // 解放されているので残高は戻る
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(1);
});

test('(D) 強制終了 (commit 前): failed() を呼べなくても滞留回収が予約を Released へ収束させる', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-04 00:00:00'));
    [$organization, , , , , $job] = pipelineContext();
    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
    $job->ticketReservation()->associate($reservation);
    $job->status = JobStatus::Running;
    $job->save();

    // SIGALRM で failed() 自体が失敗したケース = 定期実行だけが収束させる
    $this->travelTo(CarbonImmutable::parse('2026-08-04 00:31:00'));
    recoverStaleAnalysisJobs();

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
});

test('(D) 強制終了 (commit 後): failJob は terminal guard で no-op、予約は Committed のまま', function (): void {
    [, , , , , $job] = pipelineContext();
    fakeSuccessfulLlm();
    app(AnalysisPipeline::class)->run($job->id);
    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);

    // RunManualAnalysis::failed() 相当 (SIGALRM kill 後の最終防衛線)
    expect(app(AnalysisJobService::class)->failJob($job, '解析が中断されました。再実行してください。'))->toBeFalse();

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Committed);
});

test('(D) 強制終了: 失敗確定 → ジョブ回収 → 予約解放 の順でも最終会計状態は Released', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-04 00:00:00'));
    [$organization, , , , , $job] = pipelineContext();
    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
    $job->ticketReservation()->associate($reservation);
    $job->status = JobStatus::Running;
    $job->save();
    $this->travelTo(CarbonImmutable::parse('2026-08-04 00:31:00'));

    app(AnalysisJobService::class)->failJob($job, '解析が中断されました。再実行してください。');
    recoverStaleAnalysisJobs();
    releaseStaleTicketReservations();

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::ReserveCommit)->count())->toBe(0);
});

test('(D) 強制終了: 予約解放 → ジョブ回収 → 失敗確定 の逆順でも最終会計状態は同じ', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-04 00:00:00'));
    [$organization, , , , , $job] = pipelineContext();
    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
    $job->ticketReservation()->associate($reservation);
    $job->status = JobStatus::Running;
    $job->save();
    $this->travelTo(CarbonImmutable::parse('2026-08-04 00:31:00'));

    releaseStaleTicketReservations();
    recoverStaleAnalysisJobs();
    app(AnalysisJobService::class)->failJob($job, '解析が中断されました。再実行してください。');

    expect($job->refresh()->status)->toBe(JobStatus::Failed);
    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::ReserveCommit)->count())->toBe(0);
});

/*
 * ─────────────────────────────────────────────────────────────────────
 * T131 / S2: 所有権再検証 (preflight suppression) + 終端後の進捗書き戻し禁止
 * (裁定 AG-082。詳細設計 devnotes/20260807-1235-job-execution-dedup)
 * ─────────────────────────────────────────────────────────────────────
 */

test('preflight: extract の 1 回目直後に cron が failed 化 → 以降の LLM を 1 回も呼ばない', function (): void {
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm(
        script: successfulLlmScript(),
        onAttempt: function (int $attempt) use ($job): void {
            if ($attempt === 1) {
                // stale 回復 cron 相当が extract の 1 試行目の最中に失敗確定する
                app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
            }
        },
    );

    app(AnalysisPipeline::class)->run($job->id);

    // decompose / generate の LLM は 1 回も呼ばれない (extract の 1 回だけ)
    expect($fake->attemptCount())->toBe(1);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    // preflight 経路は failJob を呼ばない = cron の文言を上書きしない
    expect($job->error)->toBe(STALE_CRON_ERROR_MESSAGE);
});

test('preflight: cron failed 後に step / progress が旧ワーカーから書き戻されない', function (): void {
    [, , , , , $job] = pipelineContext();
    /** @var array{step: mixed, progress: ?int, updated_at: ?string} $snapshot */
    $snapshot = ['step' => null, 'progress' => null, 'updated_at' => null];

    installThrowingLlm(
        script: successfulLlmScript(),
        onAttempt: function (int $attempt) use ($job, &$snapshot): void {
            if ($attempt !== 1) {
                return;
            }
            app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
            $after = AnalysisJob::query()->findOrFail($job->id);
            $snapshot = [
                'step' => $after->step,
                'progress' => $after->progress,
                'updated_at' => (string) $after->updated_at?->toJSON(),
            ];
        },
    );

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    // 条件付き UPDATE (where status=running) により terminal 行へは 1 バイトも書かない
    expect($job->step)->toBe($snapshot['step']);
    expect($job->progress)->toBe($snapshot['progress']);
    expect((string) $job->updated_at?->toJSON())->toBe($snapshot['updated_at']);
    expect($job->result_json)->toBeNull(); // decompose の result_json も書かれない
});

test('preflight: 所有権喪失時に完了通知が二重に飛ばない (先着の cron 分だけ)', function (): void {
    Notification::fake();
    [, $owner, , , , $job] = pipelineContext();
    // 通知宛先を実在させる (宛先ゼロだと「飛んでいない」ことの検査が空振りする)
    $job->triggeredBy()->associate($owner);
    $job->save();
    installThrowingLlm(
        script: successfulLlmScript(),
        onAttempt: function (int $attempt) use ($job): void {
            if ($attempt === 1) {
                app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
            }
        },
    );

    app(AnalysisPipeline::class)->run($job->id);

    // 先着 (cron) の failJob が 1 通送る。preflight 経路は failJob も通知も呼ばない
    Notification::assertSentTimes(ManualAnalyzedNotification::class, 1);
});

test('preflight: 所有権喪失時に予約が二重 release されない', function (): void {
    [, , , , , $job] = pipelineContext();
    installThrowingLlm(
        script: successfulLlmScript(),
        onAttempt: function (int $attempt) use ($job): void {
            if ($attempt === 1) {
                app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
            }
        },
    );

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    $reservation = $job->ticketReservation;
    expect($reservation)->not->toBeNull();
    expect($reservation?->status)->toBe(TicketReservationStatus::Released);
    // 解放の監査痕跡は 1 件のみ (二重 release していない)
    expect(TicketLedgerEntry::query()
        ->where('reservation_id', $reservation?->getKey())
        ->where('kind', TicketLedgerKind::Release)
        ->count())->toBe(1);
    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::ReserveCommit)->count())->toBe(0);
});

test('preflight: 所有権喪失は固定 event 名で warning ログに出る', function (): void {
    Log::spy();
    [, , , , , $job] = pipelineContext();
    installThrowingLlm(
        script: successfulLlmScript(),
        onAttempt: function (int $attempt) use ($job): void {
            if ($attempt === 1) {
                app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
            }
        },
    );

    app(AnalysisPipeline::class)->run($job->id);

    // メッセージ文字列には依存せず context の event キーで判定する
    // (既存の「LLM 呼び出しを再試行します」warning と混ざらない)
    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use ($job): bool {
            return ($context['event'] ?? null) === ExternalCallKind::LOG_EVENT
                && ($context['job_type'] ?? null) === AnalysisJob::class
                && ($context['job_id'] ?? null) === $job->id
                && ($context['expected_status'] ?? null) === 'running'
                && ($context['actual_status'] ?? null) === 'failed'
                && ($context['external_call'] ?? null) === ExternalCallKind::LlmCompletion->value;
        })
        ->once();
});

test('preflight: 行が消えていても所有権喪失として扱う (deny-by-default)', function (): void {
    [, , , , , $job] = pipelineContext();
    $fake = installThrowingLlm(
        script: successfulLlmScript(),
        onAttempt: function (int $attempt) use ($job): void {
            if ($attempt === 1) {
                AnalysisJob::query()->whereKey($job->id)->delete();
            }
        },
    );

    app(AnalysisPipeline::class)->run($job->id);

    expect($fake->attemptCount())->toBe(1);
    expect(AnalysisJob::query()->whereKey($job->id)->exists())->toBeFalse();
});
