<?php

declare(strict_types=1);

namespace App\Prompts;

use App\DataTransferObjects\LlmCallContextData;
use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptDefense;

/**
 * SOP 抽出プロンプト (AI 解析 1 段目)。抽出テキスト → 統一 JSON。
 * 出力は ExtractedSopData::fromLlmText() で検証する。
 *
 * untrusted 文字列は窓口 (PromptDefense) が無害化・タグ境界化・合言葉の合流を行う。
 */
final class SopExtractPrompt
{
    public static function make(string $untrustedSopText, LlmCallContextData $context): GuardedPrompt
    {
        return PromptDefense::load(
            template: 'sop-extract',
            untrusted: ['text' => $untrustedSopText],
            context: $context,
        );
    }
}
