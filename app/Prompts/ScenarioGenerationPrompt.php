<?php

declare(strict_types=1);

namespace App\Prompts;

use App\DataTransferObjects\LlmCallContextData;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\TextPrompt;
use Kent013\PrismPrompt\Values\UserInput;

/**
 * シナリオ生成プロンプト (AI 解析 3 段目)。作業分解表 → カット群。
 * 入力 JSON は untrusted な SOP 由来のため UserInput 経由で渡す。
 * 出力は GeneratedScenarioData::fromLlmText() で検証する。
 */
final class ScenarioGenerationPrompt
{
    public static function make(string $untrustedDecompositionJson, LlmCallContextData $context): TextPrompt
    {
        return Prompt::load('scenario-generation', [
            'decomposition' => UserInput::from($untrustedDecompositionJson), // 不変条件 4: untrusted は UserInput
        ])->withMetadata($context->toMetadata()); // 帰属: llm_call_logs の organization / subject
    }
}
