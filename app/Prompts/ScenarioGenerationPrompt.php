<?php

declare(strict_types=1);

namespace App\Prompts;

use App\DataTransferObjects\LlmCallContextData;
use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptDefense;

/**
 * シナリオ生成プロンプト (AI 解析 3 段目)。作業分解表 → カット群。
 * 入力 JSON は untrusted な SOP 由来なので窓口 (PromptDefense) を通す。
 * 出力は GeneratedScenarioData::fromLlmText() で検証する。
 */
final class ScenarioGenerationPrompt
{
    public static function make(string $untrustedDecompositionJson, LlmCallContextData $context): GuardedPrompt
    {
        return PromptDefense::load(
            template: 'scenario-generation',
            untrusted: ['decomposition' => $untrustedDecompositionJson],
            context: $context,
        );
    }
}
