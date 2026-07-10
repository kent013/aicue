<?php

declare(strict_types=1);

namespace App\Prompts;

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\TextPrompt;
use Kent013\PrismPrompt\Values\UserInput;

/**
 * サンプルプロンプト (テンプレートの見本)。
 *
 * テンプレート規約 (07 ガイド §6):
 * - LLM 呼び出しは app/Prompts/ の factory 経由のみ
 *   (Prism 直呼びは PromptGuardrailTest が検出する)
 * - prompt 文字列はコードに直書きせず resources/prompts/*.yaml に置く
 * - end-user 由来の自由テキストは UserInput 型で渡す (タグ区切りで prompt-injection を防御)
 * - 実行は PromptExecutionCompleted/Failed イベント経由で llm_call_logs に記録される
 *
 * 使い方: ExampleSummaryPrompt::make($untrustedText)->executeSync()
 */
final class ExampleSummaryPrompt
{
    public static function make(string $untrustedText): TextPrompt
    {
        return Prompt::load('example-summary', [
            'text' => UserInput::from($untrustedText),
        ]);
    }
}
