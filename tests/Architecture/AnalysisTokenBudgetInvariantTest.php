<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Support\AnalysisBudget;

/**
 * AI 解析の LLM 入力上限 (config manual.analysis_max_text_bytes) の token budget 算術を
 * CI で固定する (値を弄って budget を壊せない)。
 *
 * 上界の根拠 (数学的・言語非依存): tokenizer は入力バイト列を「空でない区間」に分割する
 * (partition) ため、いかなる入力でも token 数 <= バイト数。従って
 * 「入力バイト数 <= 入力 token budget」なら context 超過は起きない。
 * budget = context - 出力予約 - 固定プロンプト余裕 = 200,000 - 16,000 - 4,000 = 180,000。
 * config 既定値 150,000 bytes は budget 180,000 に対するマージン込みの値。
 *
 * 運用条件: 「token 数 <= UTF-8 バイト数」は byte-fallback BPE 系 tokenizer の前提。
 * 対象モデル・tokenizer 系を変更する際は本上限設計 (config 値 + 本テストの定数) を必ず再確認する。
 */
const MODEL_CONTEXT_TOKENS = 200_000;   // claude-sonnet-4-5 (prompts YAML の model と対)

const OUTPUT_RESERVE_TOKENS = 16_000;   // 解析 3 YAML の max_tokens と一致させる

const PROMPT_OVERHEAD_TOKENS = 4_000;   // 固定 system/prompt + UserInput タグの余裕

const INPUT_BUDGET_TOKENS = MODEL_CONTEXT_TOKENS - OUTPUT_RESERVE_TOKENS - PROMPT_OVERHEAD_TOKENS; // 180,000

/**
 * 解析パイプラインの 3 プロンプト (施策 8)。
 * 正本は Tests\Support\AnalysisBudget::PROMPT_NAMES (時間 budget 側と二重管理しない)。
 *
 * @return list<string>
 */
function analysisPromptNames(): array
{
    return AnalysisBudget::PROMPT_NAMES;
}

test('LLM 入力バイト上限が入力 token budget を超えない (分割上界: token数<=バイト数)', function (): void {
    expect(config()->integer('manual.analysis_max_text_bytes'))
        ->toBeLessThanOrEqual(INPUT_BUDGET_TOKENS);
});

test('解析プロンプト YAML の max_tokens は出力予約と一致する', function (): void {
    foreach (analysisPromptNames() as $name) {
        $path = resource_path("prompts/{$name}.yaml");
        expect(file_exists($path))->toBeTrue("解析プロンプト {$name}.yaml が存在しません");
        $yaml = Yaml::parseFile($path);
        expect($yaml)->toBeArray();
        expect($yaml['max_tokens'] ?? null)
            ->toBe(OUTPUT_RESERVE_TOKENS, "{$name}.yaml の max_tokens が出力予約 (OUTPUT_RESERVE_TOKENS) と不一致");
    }
});

test('解析プロンプト YAML の client timeout は時間 budget の仕様値 C と一致する', function (): void {
    // AnalysisTimeBudgetInvariantTest の worst-case 計算 (D = 3C / T >= D + C + M1 + S) と対
    foreach (AnalysisBudget::clientTimeoutSecondsFromYaml() as $name => $timeout) {
        expect($timeout)->toBe(
            AnalysisBudget::CLIENT_TIMEOUT_SECONDS,
            "{$name}.yaml の client_options.timeout が時間 budget の C と不一致",
        );
    }
});

test('最小テキスト閾値 < 最大バイト上限 (validation の縮退防止)', function (): void {
    expect(config()->integer('manual.analysis_min_text_bytes'))
        ->toBeLessThan(config()->integer('manual.analysis_max_text_bytes'));
});

/*
 * OCR 経路 (画像・スキャン SOP の OCR 対応) の token budget 不変条件。
 *
 * 見積り式の一次情報: platform.claude.com/docs/en/build-with-claude/vision
 * (「Resolution and token cost」節。画像 token = ⌈width/28⌉×⌈height/28⌉。
 * claude-sonnet-4-5-20250929 は Standard tier = 最大 1,568 visual token/枚) と
 * platform.claude.com/docs/en/build-with-claude/pdf-support
 * (「Estimate your costs」節。1 ページ = 1,500〜3,000 text token + 同じ image token 計算)。
 * いずれも 2026-08-19 参照。
 *
 * この検査が保証するのは**設定値どうしの整合** (見積り式 × 上限値が budget を超えない) で
 * あって、実 token の hard limit ではない (media の入力コストは中身・provider の変換仕様・
 * モデルによって変わる)。実際の上限を担保するのは provider 側の拒否
 * (PrismRequestTooLargeException) であり、これは最後の砦として位置づける。
 * provider の hard limit (辺長 8000px・PDF ページ数 100 (< 1M context モデル)) は
 * 一次情報として確認できたため、AnalysisMediaValidator (config manual.analysis_ocr_max_dimension /
 * analysis_ocr_max_pages) の送信前上限へ直接反映してある。
 */
const OCR_ESTIMATED_TOKENS_PER_PAGE = 4_600;   // 3,000 (text 上限) + 1,568 (image 上限。丸め)

const OCR_ESTIMATED_TOKENS_PER_MEGAPIXEL = 1_600; // ⌈px/28⌉² ≈ px/784 ≈ 1,276 token/MP (丸め)

const OCR_ESTIMATE_PINNED_PROVIDER = 'anthropic';

const OCR_ESTIMATE_PINNED_MODEL = 'claude-sonnet-4-5-20250929';

test('OCR ページ数上限 × ページあたり token 見積り <= 入力 token budget', function (): void {
    $estimated = config()->integer('manual.analysis_ocr_max_pages') * OCR_ESTIMATED_TOKENS_PER_PAGE;
    expect($estimated)->toBeLessThanOrEqual(INPUT_BUDGET_TOKENS);
});

test('OCR 画素数上限 → token 見積り <= 入力 token budget', function (): void {
    $megapixels = config()->integer('manual.analysis_ocr_max_pixels') / 1_000_000;
    expect($megapixels * OCR_ESTIMATED_TOKENS_PER_MEGAPIXEL)->toBeLessThanOrEqual(INPUT_BUDGET_TOKENS);
});

test('OCR token 見積りが前提にする provider/model が sop-extract-media.yaml の値と一致する', function (): void {
    // provider/model は config ではなく YAML 自身を見る。kent013/laravel-prism-prompt の
    // 解決順序 (クラスプロパティ > YAML > config の既定値) により、YAML に明示された
    // provider/model が config より常に優先されるため、見積りが前提にする対象は YAML である。
    $yaml = Yaml::parseFile(resource_path('prompts/sop-extract-media.yaml'));
    expect($yaml)->toBeArray();
    expect($yaml['provider'] ?? null)->toBe(OCR_ESTIMATE_PINNED_PROVIDER,
        'OCR の token 見積り式は provider を前提にしている。sop-extract-media.yaml の'
        .'provider を変えたら見積り式を新しい制約に照らして見直し、この定数を更新すること。');
    expect($yaml['model'] ?? null)->toBe(OCR_ESTIMATE_PINNED_MODEL, '同上 (model 版)。');
});

test('負例: provider/model が食い違う YAML は pin と一致しない (テスト内の一時文字列で検証)', function (): void {
    $mismatched = Yaml::parse(<<<'YAML'
    name: sop-extract-media
    provider: openai
    model: gpt-5.6-example
    YAML);

    expect($mismatched['provider'])->not->toBe(OCR_ESTIMATE_PINNED_PROVIDER);
    expect($mismatched['model'])->not->toBe(OCR_ESTIMATE_PINNED_MODEL);
});

test('sop-extract-media.yaml の max_tokens / client timeout も解析 3 段と同じ仕様値に揃っている', function (): void {
    $yaml = Yaml::parseFile(resource_path('prompts/sop-extract-media.yaml'));
    expect($yaml)->toBeArray();
    expect($yaml['max_tokens'] ?? null)->toBe(OUTPUT_RESERVE_TOKENS);
    expect($yaml['client_options']['timeout'] ?? null)->toBe(AnalysisBudget::CLIENT_TIMEOUT_SECONDS);
});
