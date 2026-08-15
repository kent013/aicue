<?php

declare(strict_types=1);

namespace App\Exceptions\Llm;

use RuntimeException;

/**
 * 応答が防御検査で拒否された (system prompt にだけ載せた合言葉が応答に現れた)。
 *
 * ★ 例外 message に合言葉そのものを載せない (ログから合言葉が漏れる経路を作らない)。
 *   載せるのは prompt の雛形名だけである。
 */
final class PromptResponseRejectedException extends RuntimeException
{
    public static function canaryLeaked(string $template): self
    {
        return new self("LLM 応答に system prompt の合言葉が含まれていました (prompt: {$template})");
    }
}
