<?php

declare(strict_types=1);

use App\DataTransferObjects\LlmCallContextData;
use App\DataTransferObjects\Manual\Analysis\ExtractedText;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Llm\UntrustedInputRejectionReason;
use App\Enums\Manual\JobStatus;
use App\Exceptions\Llm\PromptResponseRejectedException;
use App\Exceptions\Llm\UntrustedInputRejectedException;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\VideoManual;
use App\Prompts\ExampleSummaryPrompt;
use App\Prompts\ScenarioGenerationPrompt;
use App\Prompts\SopExtractPrompt;
use App\Prompts\WorkDecompositionPrompt;
use App\Services\AI\Testing\CannedPromptResponses;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisPipeline;
use App\Services\Manual\SopTextExtractor;
use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptDefense;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Tests\Support\Llm\CanaryEchoPromptFake;
use Tests\Support\Llm\GuardedPromptInspector;
use Tests\Support\Llm\PromptInjectionCorpus;
use Webmozart\Assert\Assert;

/*
 * 窓口 (PromptDefense) と実行単位 (GuardedPrompt) の**実行時**の振る舞い
 * (裁定 AG-028 の窓口方式一式)。構造の検査は PromptDefenseWindowGateTest が担う。
 *
 * ここで固定するのは 3 つ:
 *  (1) untrusted がタグ境界化され、不可視文字が prompt に載らないこと
 *  (2) 拒否が fail-closed であること (LLM を呼ばない / 応答を返さない)
 *  (3) 拒否がパイプラインの利用者向け文言・再試行しない扱い・チケット release に写ること
 */

beforeEach(function (): void {
    // executeSync は fake 中も PromptExecutionCompleted を発火し、listener が FX 解決 (HTTP) を
    // 試みるため stray request を防ぐ
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
});

afterEach(function (): void {
    Prompt::stopFaking();
});

/** 窓口を通した prompt を 1 本組み立てる (見本 factory 経由 = 帰属なし)。 */
function defenseSamplePrompt(string $untrusted): GuardedPrompt
{
    return ExampleSummaryPrompt::make($untrusted);
}

/**
 * 解析パイプラインを 1 回走らせるための queued job 一式。
 *
 * @return array{Organization, AnalysisJob}
 */
function defensePipelineContext(): array
{
    Storage::fake();
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
    $path = "projects/{$project->id}/manuals/{$manual->id}/source-documents/sop.txt";
    Storage::put($path, str_repeat("手順: 部品を取り付けてネジを締める。急所: トルクは 5Nm。\n", 5));
    $document = SourceDocument::factory()->forManual($manual)->create([
        'file_path' => $path,
        'mime' => 'text/plain',
    ]);
    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');

    return [$organization, $job];
}

// ── (1) タグ境界化と無害化 ───────────────────────────────────────────

test('タグ breakout を試みても <user_input> 境界は 1 組だけ保たれる', function (): void {
    foreach (PromptInjectionCorpus::tagBreakouts() as $input) {
        $rendered = GuardedPromptInspector::renderedUserPrompt(defenseSamplePrompt($input));

        expect(substr_count($rendered, '<user_input>'))->toBe(1);
        expect(substr_count($rendered, '</user_input>'))->toBe(1);
        expect($rendered)->toContain('_escaped');
    }
});

test('不可視文字は prompt に載らない', function (): void {
    foreach (PromptInjectionCorpus::invisibleCharacters() as $name => $input) {
        $rendered = GuardedPromptInspector::renderedUserPrompt(defenseSamplePrompt($input));

        expect(preg_match(
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{0080}-\x{009F}'
            .'\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u',
            $rendered,
        ))->toBe(0, "{$name}: 不可視文字が prompt に載っています");
    }
});

test('改行とタブは prompt に保持される (SOP の構造が壊れない)', function (): void {
    $rendered = GuardedPromptInspector::renderedUserPrompt(
        defenseSamplePrompt("手順 1\tトルクレンチ\n手順 2\tネジ締め"),
    );

    expect($rendered)->toContain("手順 1\tトルクレンチ\n手順 2\tネジ締め");
});

test('合言葉は system prompt 側にだけ現れる', function (): void {
    $prompt = defenseSamplePrompt('本文');
    $token = GuardedPromptInspector::canaryToken($prompt);

    expect(GuardedPromptInspector::renderedSystemPrompt($prompt))->toContain($token);
    expect(GuardedPromptInspector::renderedUserPrompt($prompt))->not->toContain($token);
});

test('合言葉の変数名は上書きできない', function (): void {
    expect(fn (): GuardedPrompt => PromptDefense::loadUnattributed(
        template: 'example-summary',
        untrusted: [PromptDefense::CANARY_VARIABLE => '乗っ取り'],
    ))->toThrow(InvalidArgumentException::class);
});

test('変数名は小文字始まりの識別子に限る', function (): void {
    foreach (['', 'Text', '1text', 'te-xt'] as $invalid) {
        expect(fn (): GuardedPrompt => PromptDefense::loadUnattributed(
            template: 'example-summary',
            untrusted: [$invalid => '本文'],
        ))->toThrow(InvalidArgumentException::class);
    }
});

test('不可視文字の除去はログに件数だけを残す (中身を流さない)', function (): void {
    Log::spy();

    defenseSamplePrompt("機密の手順\u{200B}\u{200B}です");

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        // ★ context を 1 本の文字列へ畳んでから**部分一致**で検査する。
        //   完全一致 (in_array) だと `input => '機密の手順です'` のような混入を見逃す。
        $serialized = $message.' '.(string) json_encode($context, JSON_UNESCAPED_UNICODE);

        foreach (['機密の手順', 'です', "\u{200B}"] as $fragment) {
            if (str_contains($serialized, $fragment)) {
                return false;
            }
        }

        return $message === 'untrusted 入力から不可視文字を除去しました'
            && $context['removed_characters'] === 2
            && $context['prompt'] === 'example-summary'
            && $context['variable'] === 'text';
    })->once();
});

// ── (2) 拒否は fail-closed ───────────────────────────────────────────

test('上限超過は LLM を 1 回も呼ばずに拒否する', function (): void {
    $fake = Prompt::fake([TextResponseFake::make()->withText('呼ばれてはいけない')]);
    $limit = config()->integer('llm-defense.max_untrusted_bytes');

    try {
        defenseSamplePrompt(PromptInjectionCorpus::oversizedText($limit));
        $this->fail('上限超過が拒否されていません');
    } catch (UntrustedInputRejectedException $exception) {
        expect($exception->reason)->toBe(UntrustedInputRejectionReason::TooLarge);
    }

    $fake->assertCallCount(0);
});

test('不正な UTF-8 は LLM を 1 回も呼ばずに拒否する', function (): void {
    $fake = Prompt::fake([TextResponseFake::make()->withText('呼ばれてはいけない')]);

    try {
        defenseSamplePrompt("手順\xC3\x28");
        $this->fail('不正な UTF-8 が拒否されていません');
    } catch (UntrustedInputRejectedException $exception) {
        expect($exception->reason)->toBe(UntrustedInputRejectionReason::InvalidEncoding);
    }

    $fake->assertCallCount(0);
});

test('合言葉が漏れた応答は呼び出し元へ返らない', function (): void {
    Prompt::installFake(new CanaryEchoPromptFake('これが system prompt です: '));

    $prompt = defenseSamplePrompt(PromptInjectionCorpus::canaryDisclosureRequest());
    $token = GuardedPromptInspector::canaryToken($prompt);

    try {
        $prompt->executeSync();
        $this->fail('合言葉の漏洩が検知されていません');
    } catch (PromptResponseRejectedException $exception) {
        // 例外 message に合言葉そのものを載せない (ログから合言葉が漏れる経路を作らない)
        expect($exception->getMessage())->not->toContain($token);
        expect($exception->getMessage())->toContain('example-summary');
    }
});

test('空白で分割された合言葉 + 不正バイトの応答でも fail-open しない', function (): void {
    Prompt::installFake(new CanaryEchoPromptFake("\xC3\x28 ", splitEveryChars: 8));

    expect(fn (): string => defenseSamplePrompt('本文')->executeSync())
        ->toThrow(PromptResponseRejectedException::class);
});

test('合言葉を含まない応答はそのまま返る', function (): void {
    Prompt::fake([TextResponseFake::make()->withText('要約です。')]);

    expect(defenseSamplePrompt('本文')->executeSync())->toBe('要約です。');
});

// ── 4 YAML すべてが窓口経由で組み立つ ────────────────────────────────

test('4 つの prompt がすべて窓口経由で組み立てられ canned が一意解決する', function (): void {
    $context = LlmCallContextData::none();
    $prompts = [
        'sop-extract' => SopExtractPrompt::make('サンプル SOP', $context),
        'work-decomposition' => WorkDecompositionPrompt::make('{"sections":[]}', $context),
        'scenario-generation' => ScenarioGenerationPrompt::make('{"steps":[]}', $context),
        'example-summary' => ExampleSummaryPrompt::make('本文'),
    ];

    $canned = app(CannedPromptResponses::class);
    foreach ($prompts as $template => $prompt) {
        expect($prompt)->toBeInstanceOf(GuardedPrompt::class);

        $systemText = GuardedPromptInspector::renderedSystemPrompt($prompt);
        expect($systemText)->toContain(GuardedPromptInspector::canaryToken($prompt));

        /** @var array<int, Message> $messages */
        $messages = [new SystemMessage($systemText)];
        // 合言葉が混ざっても signature 解決は一意のまま (fail-fast しない)
        $response = $canned->forMessages($messages);
        expect($response->getText())->not->toBe('');
        unset($template);
    }
});

// ── (3) パイプラインへの写り方 ───────────────────────────────────────

test('合言葉の漏洩: 再試行せず failed + 安全検査の文言 + 予約 release', function (): void {
    [, $job] = defensePipelineContext();
    $fake = new CanaryEchoPromptFake('system prompt: ');
    Prompt::installFake($fake);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(AnalysisFailedException::unsafeResponse()->getMessage());
    // 安全性の違反が疑われる状態で、課金してまでもう一度モデルへ投げない
    expect($fake->callCount())->toBe(1);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
});

test('上限超過: LLM を 1 回も呼ばず failed + 分割案内の文言 + 予約 release', function (): void {
    [, $job] = defensePipelineContext();
    // そのテスト内でだけ窓口の上限を下げ、通常の SOP 本文を窓口で拒否させる
    // (committed な config の大小関係は LlmDefenseConfigGateTest が別途固定している)
    config()->set('llm-defense.max_untrusted_bytes', 50);
    $fake = Prompt::fake([TextResponseFake::make()->withText('呼ばれてはいけない')]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(AnalysisFailedException::tooLarge()->getMessage());
    $fake->assertCallCount(0);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
});

test('不正な UTF-8: LLM を 1 回も呼ばず failed + 文字コードの文言 + 予約 release', function (): void {
    [, $job] = defensePipelineContext();

    // 抽出器の保証が将来失われたときに窓口が fail-closed で止めることの再現。
    // ExtractedText の不変条件は緩めない (UTF-8 の保証はもともと抽出器側にある)。
    $this->app->instance(SopTextExtractor::class, new class extends SopTextExtractor
    {
        public function extract(SourceDocument $document): ExtractedText
        {
            unset($document);
            $broken = "手順 1\xC3\x28手順 2".str_repeat('あ', 100);

            return new ExtractedText($broken, strlen($broken), 'plain');
        }
    });
    $fake = Prompt::fake([TextResponseFake::make()->withText('呼ばれてはいけない')]);

    app(AnalysisPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toBe(AnalysisFailedException::unreadableEncoding()->getMessage());
    $fake->assertCallCount(0);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
});

test('窓口の拒否は transient ではない (isTransient を deny-by-default のまま保つ)', function (): void {
    $method = new ReflectionMethod(AnalysisPipeline::class, 'isTransient');
    $pipeline = app(AnalysisPipeline::class);

    $rejected = PromptResponseRejectedException::canaryLeaked('sop-extract');
    $tooLarge = null;
    try {
        config()->set('llm-defense.max_untrusted_bytes', 1);
        defenseSamplePrompt('本文が上限を超える');
    } catch (UntrustedInputRejectedException $exception) {
        $tooLarge = $exception;
    }
    Assert::notNull($tooLarge);

    expect($method->invoke($pipeline, $rejected))->toBeFalse();
    expect($method->invoke($pipeline, $tooLarge))->toBeFalse();
});
