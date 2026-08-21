<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisPipeline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use Tests\Support\Manual\MinimalImageFixture;
use Tests\Support\Manual\MinimalPdfFixture;

/*
 * AI 解析パイプラインの OCR 経路 (画像・スキャン SOP の OCR 対応。常時有効。
 * 旧 `manual.ocr_analysis_enabled` フラグはオーナー決定により撤去済み):
 * - 画像アップロード → OCR 経路 → 成功
 * - テキスト層の無い PDF → OCR フォールバック → 成功
 * - OCR 対象外の失敗 (tooLarge) はそのまま失敗する (回帰)
 * - extract 段の終端ログがジョブにつきちょうど 1 回だけ出ること・route/failure_category/
 *   media メタデータが正しいこと
 */

beforeEach(function (): void {
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
});

/** @return array{Organization, Project, VideoManual} */
function ocrPipelineOrg(): array
{
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);

    return [$organization, $project, $manual];
}

function imageSourceDocument(VideoManual $manual, string $bytes, string $mime = 'image/jpeg', string $ext = 'jpg'): SourceDocument
{
    $path = "projects/{$manual->project_id}/manuals/{$manual->id}/source-documents/sop.{$ext}";
    Storage::put($path, $bytes);

    return SourceDocument::factory()->forManual($manual)->create(['file_path' => $path, 'mime' => $mime]);
}

function unreadablePdfSourceDocument(VideoManual $manual): SourceDocument
{
    // テキスト層の無い PDF (SopTextExtractor が unextractable を投げる = 0 バイト抽出)
    $path = "projects/{$manual->project_id}/manuals/{$manual->id}/source-documents/scan.pdf";
    Storage::put($path, MinimalPdfFixture::withPages(2));

    return SourceDocument::factory()->forManual($manual)->create(['file_path' => $path, 'mime' => 'application/pdf']);
}

function ocrExtractFixture(): string
{
    // AnalysisAcceptanceGate の実質空判定 (manual.analysis_min_text_bytes) を
    // 既定値のまま安全に上回るよう、実際の SOP らしい分量の本文にする。
    return json_encode([
        'header' => ['title' => 'OCR サンプル手順書', 'department' => null, 'revision' => null],
        'sections' => [[
            'title' => null,
            'steps' => [[
                'no' => 1,
                'work_process' => 'バルブを閉じる作業を丁寧に実施し、確実に閉止したことを確認する。',
                'work_points' => ['ハンドルを時計回りにゆっくりと回し、止まるまで確実に締め付ける。'],
                'safety_points' => ['作業前に周囲の安全確認を必ず行う。'],
                'quality_points' => [],
                'pm_points' => [],
            ]],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function decompositionFixtureOcr(): string
{
    return json_encode([
        'steps' => [['no' => 1, 'action' => 'バルブを閉じる', 'points' => ['ハンドルが止まるまで回す']]],
        'validation' => [
            'verdict' => 'valid', 'reason' => '妥当です。', 'works' => ['バルブ閉止'], 'split_recommended' => false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function scenarioFixtureOcr(): string
{
    return json_encode([
        'cuts' => [
            ['no' => 1, 'type' => 'step', 'parent_no' => null, 'scene' => '全体', 'shot_type' => 'hiki',
                'shooting_point' => null, 'narration' => 'バルブを閉じます', 'subtitle_primary' => null, 'subtitle_secondary' => 'バルブ閉'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function fakeSuccessfulOcrScript(): void
{
    Prompt::fake([
        TextResponseFake::make()->withText(ocrExtractFixture()),
        TextResponseFake::make()->withText(decompositionFixtureOcr()),
        TextResponseFake::make()->withText(scenarioFixtureOcr()),
    ]);
}

test('画像アップロードは OCR 経路で成功する', function (): void {
    Storage::fake();
    [$organization, , $manual] = ocrPipelineOrg();
    $document = imageSourceDocument($manual, MinimalImageFixture::jpeg(10, 10));
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');
    fakeSuccessfulOcrScript();

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    $manual->refresh();
    expect($manual->status)->toBe(VideoManualStatus::Ready);
});

test('テキスト層の無い PDF は OCR フォールバックで成功する', function (): void {
    Storage::fake();
    [$organization, , $manual] = ocrPipelineOrg();
    $document = unreadablePdfSourceDocument($manual);
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');
    fakeSuccessfulOcrScript();

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
});

test('OCR 対象外の失敗 (tooLarge) はそのまま失敗する (回帰)', function (): void {
    Storage::fake();
    config()->set('manual.analysis_max_text_bytes', 10);
    [$organization, , $manual] = ocrPipelineOrg();
    $path = "projects/{$manual->project_id}/manuals/{$manual->id}/source-documents/sop.txt";
    Storage::put($path, str_repeat('長い手順書テキスト。', 100));
    $document = SourceDocument::factory()->forManual($manual)->create(['file_path' => $path, 'mime' => 'text/plain']);
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');

    Log::spy();
    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toContain('大きすぎます');

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        return $message === 'AI 解析の抽出段 (終端)'
            && $context['route'] === 'text'
            && $context['outcome'] === 'failed'
            && $context['failure_category'] === 'too_large';
    })->once();
});

test('画像の media 検証失敗 (画素数上限超過) は route=ocr で 1 回だけログされ LLM は呼ばれない', function (): void {
    Storage::fake();
    config()->set('manual.analysis_ocr_max_pixels', 1);
    [$organization, , $manual] = ocrPipelineOrg();
    $document = imageSourceDocument($manual, MinimalImageFixture::jpeg(10, 10));
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');

    Log::spy();
    Prompt::fake([]); // LLM は 1 度も呼ばれないはず (呼ばれたら script 切れで例外)
    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);

    // media 検証自体が失敗した場合、検証済み DTO は 1 つも得られていないため
    // media_pixels 等のメタデータは残らない (null)。これは「media 検証は成功したが
    // LLM 呼び出しで失敗した」ケース (別テスト) とは区別される。
    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        return $message === 'AI 解析の抽出段 (終端)'
            && $context['route'] === 'ocr'
            && $context['outcome'] === 'failed'
            && $context['failure_category'] === 'media_too_large'
            && $context['media_pixels'] === null;
    })->once();
});

test('PDF の OCR フォールバックで media 検証は成功したが LLM 応答が壊れて最終失敗する', function (): void {
    Storage::fake();
    config()->set('manual.analysis_llm_max_retries', 0); // リトライなしで即座に終端させる
    [$organization, , $manual] = ocrPipelineOrg();
    $document = unreadablePdfSourceDocument($manual);
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');

    Log::spy();
    Prompt::fake([TextResponseFake::make()->withText('これは JSON ではありません')]);
    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        return $message === 'AI 解析の抽出段 (終端)'
            && $context['route'] === 'ocr'
            && $context['outcome'] === 'failed'
            && $context['media_pages'] === 2
            && str_starts_with((string) $context['failure_category'], 'llm_output_invalid_');
    })->once();
});
