<?php

declare(strict_types=1);

namespace App\Prompts;

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\TextPrompt;
use Kent013\PrismPrompt\Values\UserInput;

/**
 * SOP 抽出プロンプト (AI 解析 1 段目)。抽出テキスト → 統一 JSON。
 * 出力は ExtractedSopData::fromLlmText() で検証する。
 */
final class SopExtractPrompt
{
    public static function make(string $untrustedSopText): TextPrompt
    {
        return Prompt::load('sop-extract', [
            'text' => UserInput::from($untrustedSopText), // 不変条件 4: untrusted は UserInput
        ]);
    }
}
