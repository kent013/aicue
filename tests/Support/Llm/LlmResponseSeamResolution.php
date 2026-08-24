<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

/**
 * `GuardedPrompt::executeSync()` の呼び出し点で、応答の**受け手**を解決できたか。
 *
 * ★**未解決を「目録外」と同じ値へ潰さない**。潰すと変数へ束縛する書き方
 *   (`$prompt = X::make(...); $prompt->executeSync();`) が無言で候補から外れる
 *   (`AGENTS.md` の共通規約 (b) が禁じる形)。
 */
enum LlmResponseSeamResolution
{
    /** 直前が `X::make(...)` で、`X` が目録の鍵に解決できた。 */
    case ResolvedPromptFactory;

    /** 直前が `X::make(...)` だが、`X` が目録の鍵ではない。 */
    case ResolvedOther;

    /** それ以外の書き方 (変数への束縛 / container 解決 / 式)。**gate は失敗させる**。 */
    case Unresolved;
}
