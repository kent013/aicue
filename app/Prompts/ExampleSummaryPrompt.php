<?php

declare(strict_types=1);

namespace App\Prompts;

use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptDefense;

/**
 * サンプルプロンプト (テンプレートの見本)。
 *
 * テンプレート規約 (07 ガイド §6):
 * - LLM 呼び出しは app/Prompts/ の factory → 窓口 (PromptDefense) → 実行単位 (GuardedPrompt)
 *   の 1 本道のみ (Prism 直呼びは PromptGuardrailTest が検出する)
 * - prompt 文字列はコードに直書きせず resources/prompts/*.yaml に置く
 * - end-user 由来の自由テキストは窓口の untrusted 引数へ生の string で渡す
 *   (窓口が無害化してタグ区切りする)
 * - 実行は PromptExecutionCompleted/Failed イベント経由で llm_call_logs に記録される
 *
 * ★ この 1 本だけが帰属なしの窓口 (loadUnattributed) を使う。呼び出し元を持たない見本で
 *   帰属の対象が構造的に存在しないためで、窓口 gate が**この 1 件を名指しで pin** する。
 *
 * 使い方: ExampleSummaryPrompt::make($untrustedText)->executeSync()
 */
final class ExampleSummaryPrompt
{
    public static function make(string $untrustedText): GuardedPrompt
    {
        return PromptDefense::loadUnattributed(
            template: 'example-summary',
            untrusted: ['text' => $untrustedText],
        );
    }
}
