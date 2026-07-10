<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

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
 *
 * @return list<string>
 */
function analysisPromptNames(): array
{
    return ['sop-extract', 'work-decomposition', 'scenario-generation'];
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

test('解析プロンプト YAML の client timeout は時間 budget の前提値 (120 秒) と一致する', function (): void {
    // AnalysisTimeBudgetInvariantTest の worst-case 計算 (3 段 × 試行 × 120s) と対
    foreach (analysisPromptNames() as $name) {
        $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
        expect($yaml)->toBeArray();
        expect($yaml['client_options']['timeout'] ?? null)
            ->toBe(120, "{$name}.yaml の client_options.timeout が 120 と不一致");
    }
});

test('最小テキスト閾値 < 最大バイト上限 (validation の縮退防止)', function (): void {
    expect(config()->integer('manual.analysis_min_text_bytes'))
        ->toBeLessThan(config()->integer('manual.analysis_max_text_bytes'));
});
