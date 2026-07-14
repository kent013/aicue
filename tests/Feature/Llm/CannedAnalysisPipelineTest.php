<?php

declare(strict_types=1);

use App\Enums\Manual\CutType;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\ShotType;
use App\Enums\Manual\VideoManualStatus;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\VideoManual;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisPipeline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kent013\PrismPrompt\Prompt;

/*
 * AI 解析 end-to-end 統合テスト (bughunt-llm-fake-wiring 施策 5-5):
 * bughunt 実行時の配線 (CannedPromptFakeRegistrar::install) 下で AnalysisPipeline を
 * 完走させ、3 段 (sop-extract → work-decomposition → scenario-generation) がすべて
 * canned で解決されて cuts が materialize されることを検証する (実 API 未到達 = stray 0)。
 */

beforeEach(function (): void {
    // 万一の FX 解決 HTTP を stray にしない防御 (fake 分岐は event 非発火の想定)。
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
});

afterEach(function (): void {
    Prompt::stopFaking();
});

test('canned fake 配線下で AnalysisPipeline が succeeded し cuts (step+point) が materialize される', function (): void {
    Storage::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
    $path = "projects/{$project->id}/manuals/{$manual->id}/source-documents/sop.txt";
    Storage::put($path, str_repeat("手順: バルブを閉じる。急所: ハンドルが止まるまで回す。\n", 5));
    $document = SourceDocument::factory()->forManual($manual)->create([
        'file_path' => $path,
        'mime' => 'text/plain',
    ]);
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');

    // bughunt 実行時 (FakeExternalsServiceProvider::boot) と同一の install 経路。
    app(CannedPromptFakeRegistrar::class)->install();
    app(AnalysisPipeline::class)->run($job->id);

    // ジョブ succeeded (3 段すべて canned で解決 = 実 API 未到達)。
    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    expect($job->error)->toBeNull();

    // manual: cuts ツリー (導入 + 生成 step + 生成 point + 総括) / ready。
    $manual->refresh();
    expect($manual->status)->toBe(VideoManualStatus::Ready);
    $cuts = $manual->cuts()->orderBy('sort_order')->get();
    expect($cuts)->toHaveCount(4); // 導入 + step + point + 総括
    $topLevel = $cuts->where('parent_cut_id', null)->values();
    expect($topLevel)->toHaveCount(3);
    // 先頭=導入 / 末尾=総括: 位置・型・shot_type・親子を退行検出 (件数のみに頼らない)
    expect($topLevel->first()->parent_cut_id)->toBeNull();
    expect($topLevel->first()->shot_type)->toBe(ShotType::Hiki);
    expect($topLevel->first()->narration)->toContain($manual->title);
    expect($topLevel->last()->parent_cut_id)->toBeNull();
    expect($topLevel->last()->shot_type)->toBe(ShotType::Hiki);
    // 生成 step / point は従来どおり存在し、point は中間 step にぶら下がる
    $generatedStep = $topLevel->get(1); // 導入(0) と 総括(2) の間
    $point = $cuts->firstWhere('type', CutType::Point);
    expect($point)->not->toBeNull();
    expect($point->parent_cut_id)->toBe($generatedStep->id);

    // 実 LLM provider へは 1 度も到達していない (fake の recorded に 3 段が記録されている)。
    $fake = Prompt::getFake();
    expect($fake)->not->toBeNull();
    expect($fake?->recorded())->toHaveCount(3);

    // 外部 HTTP 未到達 (fake 分岐は PromptExecutionCompleted を発火しないため
    // FX 解決 HTTP も走らない = bughunt から外部へ漏れない)。
    Http::assertNothingSent();
});
