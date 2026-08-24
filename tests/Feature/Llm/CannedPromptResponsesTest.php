<?php

declare(strict_types=1);

use App\DataTransferObjects\LlmCallContextData;
use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use App\DataTransferObjects\Manual\Analysis\ImageAnalysisMediaData;
use App\DataTransferObjects\Manual\Analysis\WorkDecompositionResponseData;
use App\Enums\Manual\ScenarioVerdict;
use App\Prompts\ExampleSummaryPrompt;
use App\Prompts\ScenarioGenerationPrompt;
use App\Prompts\SopExtractFromMediaPrompt;
use App\Prompts\SopExtractPrompt;
use App\Prompts\WorkDecompositionPrompt;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\AI\Testing\CannedPromptResponses;
use App\Support\Llm\GuardedPrompt;
use Illuminate\Support\Facades\Http;
use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Webmozart\Assert\Assert;

/*
 * CannedPromptResponses (bughunt-llm-fake-wiring 施策 1/2/5):
 * - 各実 factory の canned が該当 DTO の fromLlmText を通過する (主保証)
 * - 登録 prompt allowlist に対し signature がちょうど 1 件一致する (1:1 対応の固定)
 * - 未登録 (0 件) / 曖昧 (2 件以上) は fail-fast で例外 (silent false-positive 防止)
 */

beforeEach(function (): void {
    // executeSync の fake 分岐は PromptExecutionCompleted を発火しない想定だが、
    // 万一 listener が FX 解決 (HTTP) を試みても stray request にしないよう防御的に fake する。
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
    app(CannedPromptFakeRegistrar::class)->install();
});

afterEach(function (): void {
    Prompt::stopFaking();
});

/**
 * 登録済み prompt を 1 回だけ実行し、record された messages を capture する。
 * vendor 公開 API recorded() のみに依存する (reflection / protected には触れない)。
 *
 * @param  Closure(): mixed  $runOnce
 * @return array<int, Message>
 */
function captureMessages(Closure $runOnce): array
{
    // install() で fresh な CannedPromptFake に差し替え、recorded を空から始める。
    app(CannedPromptFakeRegistrar::class)->install();
    $runOnce();

    $fake = Prompt::getFake();
    Assert::notNull($fake);
    $recorded = $fake->recorded();
    // 1 ケース 1 実行のため record は厳密に 1 件 (順序・混入の余地なし)。
    Assert::count($recorded, 1);
    $messages = $recorded[0]['messages'];
    Assert::isArray($messages);
    Assert::allIsInstanceOf($messages, Message::class);

    return $messages;
}

/**
 * messages の SystemMessage 本文を連結する (signature 判定対象)。
 *
 * @param  array<int, Message>  $messages
 */
function systemTextOf(array $messages): string
{
    $parts = [];
    foreach ($messages as $message) {
        if ($message instanceof SystemMessage) {
            $parts[] = $message->content;
        }
    }

    return implode("\n", $parts);
}

/** 登録済み prompt allowlist (key => [factory 実体, 期待 signature]) */
function makeRegisteredPrompt(string $key): GuardedPrompt
{
    return match ($key) {
        'sop-extract' => SopExtractPrompt::make('サンプル SOP', LlmCallContextData::none()),
        'sop-extract-media' => SopExtractFromMediaPrompt::make(
            ImageAnalysisMediaData::fromValidated('image/jpeg', 'stub-jpeg-bytes', 17, 10, 10),
            LlmCallContextData::none(),
        ),
        'work-decomposition' => WorkDecompositionPrompt::make('{"header":{},"sections":[]}', LlmCallContextData::none()),
        'scenario-generation' => ScenarioGenerationPrompt::make('{"steps":[]}', LlmCallContextData::none()),
        'example-summary' => ExampleSummaryPrompt::make('本文'),
        default => throw new InvalidArgumentException("unknown prompt key: {$key}"),
    };
}

// ---- 5-1: canned DTO 通過テスト (主保証) ----

test('sop-extract の canned が ExtractedSopData::fromLlmText を通過する', function (): void {
    $text = SopExtractPrompt::make('サンプル SOP', LlmCallContextData::none())->executeSync();
    Assert::string($text);

    $dto = ExtractedSopData::fromLlmText($text);
    expect($dto->sections)->not->toBeEmpty();
    expect($dto->sections[0]['steps'])->toHaveCount(1);
});

test('sop-extract-media の canned が ExtractedSopData::fromLlmText を通過する', function (): void {
    $media = ImageAnalysisMediaData::fromValidated('image/jpeg', 'stub-jpeg-bytes', 17, 10, 10);
    $text = SopExtractFromMediaPrompt::make($media, LlmCallContextData::none())->executeSync();
    Assert::string($text);

    $dto = ExtractedSopData::fromLlmText($text);
    expect($dto->sections)->not->toBeEmpty();
    expect($dto->sections[0]['steps'])->toHaveCount(1);
});

test('work-decomposition の canned が WorkDecompositionResponseData::fromLlmText を通過する', function (): void {
    $text = WorkDecompositionPrompt::make('{"header":{},"sections":[]}', LlmCallContextData::none())->executeSync();
    Assert::string($text);

    $dto = WorkDecompositionResponseData::fromLlmText($text);
    expect($dto->decomposition->steps)->toHaveCount(1);
    // 妥当性の所見も同じ 1 応答から取り出せる (steps と validation を 1 回の decode で組む)
    expect($dto->validation->verdict)->toBe(ScenarioVerdict::Valid);
    expect($dto->validation->works)->toHaveCount(1);
});

test('scenario-generation の canned が GeneratedScenarioData::fromLlmText を通過する', function (): void {
    $text = ScenarioGenerationPrompt::make('{"steps":[]}', LlmCallContextData::none())->executeSync();
    Assert::string($text);

    $dto = GeneratedScenarioData::fromLlmText($text);
    // step 1 + それを参照する point 1 (materialize で step→points ツリーになる)
    expect($dto->steps)->toHaveCount(1);
    expect($dto->steps[0]->points)->toHaveCount(1);
});

test('構造化応答の canned は囲みちょうど 1 つで返る (素の JSON へ戻す改変を赤にする)', function (string $key): void {
    // 受理契約が「囲みちょうど 1 つ」なので、canned も**依頼文が指示する形と同じ形**で返す。
    $text = makeRegisteredPrompt($key)->executeSync();
    Assert::string($text);

    expect($text)->toStartWith("```json\n");
    expect($text)->toEndWith("\n```");
    expect(substr_count($text, '```'))->toBe(2);
})->with(['sop-extract', 'sop-extract-media', 'work-decomposition', 'scenario-generation']);

test('example-summary の canned は非空 string を返す', function (): void {
    $text = ExampleSummaryPrompt::make('本文')->executeSync();
    expect($text)->toBeString();
    expect(trim((string) $text))->not->toBe('');
});

// ---- 5-2: signature 衝突防止テスト (登録 prompt allowlist に対する 1:1) ----

test('登録 prompt はちょうど 1 signature に一致し、それが期待どおり', function (string $key, string $expected): void {
    $messages = captureMessages(fn () => makeRegisteredPrompt($key)->executeSync());
    $systemText = systemTextOf($messages);

    $signatures = app(CannedPromptResponses::class)->supportedSignatures();
    $matched = array_values(array_filter(
        $signatures,
        static fn (string $signature): bool => str_contains($systemText, $signature),
    ));

    expect($matched)->toBe([$expected]);
})->with([
    'sop-extract' => ['sop-extract', '作業手順書 (SOP) を構造化するエキスパート'],
    'sop-extract-media' => ['sop-extract-media', '手順書を画像や PDF から読み取り構造化するエキスパート'],
    'work-decomposition' => ['work-decomposition', '作業標準化エキスパート'],
    'scenario-generation' => ['scenario-generation', 'マニュアル動画の演出家'],
    'example-summary' => ['example-summary', 'テキストを 1 文に要約するアシスタント'],
]);

test('signature はペアワイズで非部分包含 (将来 signature 追加時の衝突を静的に防止)', function (): void {
    $signatures = app(CannedPromptResponses::class)->supportedSignatures();

    foreach ($signatures as $a) {
        foreach ($signatures as $b) {
            if ($a === $b) {
                continue;
            }
            expect(str_contains($a, $b))->toBeFalse(
                "signature '{$b}' が signature '{$a}' の部分文字列になっています",
            );
        }
    }
});

// ---- 5-3: 未登録 / 曖昧 prompt fail-fast テスト ----

test('未登録 (0 件一致) の SystemMessage は fail-fast する', function (): void {
    $messages = [new SystemMessage('未知の役割')];

    expect(fn () => app(CannedPromptResponses::class)->forMessages($messages))
        ->toThrow(RuntimeException::class);
});

test('曖昧 (2 件一致) の SystemMessage は fail-fast する', function (): void {
    $messages = [new SystemMessage('作業標準化エキスパート かつ マニュアル動画の演出家')];

    expect(fn () => app(CannedPromptResponses::class)->forMessages($messages))
        ->toThrow(RuntimeException::class);
});

test('fail-fast の例外メッセージに system text 先頭と一致 signature が含まれる (調査性)', function (): void {
    $systemText = '作業標準化エキスパート と マニュアル動画の演出家';

    try {
        app(CannedPromptResponses::class)->forMessages([new SystemMessage($systemText)]);
        // 到達しない (上で必ず throw する)
        expect(false)->toBeTrue();
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain(mb_substr($systemText, 0, 200));
        expect($exception->getMessage())->toContain('作業標準化エキスパート');
        expect($exception->getMessage())->toContain('マニュアル動画の演出家');
    }
});
