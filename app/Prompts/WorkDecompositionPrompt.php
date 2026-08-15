<?php

declare(strict_types=1);

namespace App\Prompts;

use App\DataTransferObjects\LlmCallContextData;
use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptDefense;

/**
 * 作業分解プロンプト (AI 解析 2 段目)。統一 JSON → 作業分解表。
 * 入力 JSON は untrusted な SOP 由来なので窓口 (PromptDefense) を通す。
 * 出力は WorkDecompositionData::fromLlmText() で検証する。
 */
final class WorkDecompositionPrompt
{
    public static function make(string $untrustedExtractedJson, LlmCallContextData $context): GuardedPrompt
    {
        return PromptDefense::load(
            template: 'work-decomposition',
            untrusted: ['extracted' => $untrustedExtractedJson],
            context: $context,
        );
    }
}
