<?php

declare(strict_types=1);

namespace App\Prompts;

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\TextPrompt;
use Kent013\PrismPrompt\Values\UserInput;

/**
 * 作業分解プロンプト (AI 解析 2 段目)。統一 JSON → 作業分解表。
 * 入力 JSON は untrusted な SOP 由来のため UserInput 経由で渡す。
 * 出力は WorkDecompositionData::fromLlmText() で検証する。
 */
final class WorkDecompositionPrompt
{
    public static function make(string $untrustedExtractedJson): TextPrompt
    {
        return Prompt::load('work-decomposition', [
            'extracted' => UserInput::from($untrustedExtractedJson), // 不変条件 4: untrusted は UserInput
        ]);
    }
}
