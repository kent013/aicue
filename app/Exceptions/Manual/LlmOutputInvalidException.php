<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use App\Enums\Manual\LlmOutputInvalidReason;
use RuntimeException;

/**
 * LLM 出力 JSON の検証失敗 (有界リトライのトリガー。§10.7-2)。
 * AnalysisPipeline::withBoundedRetry の retryable 集合に含まれ (transient な
 * provider/connection 例外と同じ扱い)、試行上限または実時間 deadline の到達で
 * failJob (ユーザー向け文言) へ落とす。
 */
final class LlmOutputInvalidException extends RuntimeException
{
    public function __construct(
        public readonly LlmOutputInvalidReason $reason,
        string $detail,
        /** 違反位置 (例: validation.works.2)。観測専用で制御フローには使わない */
        public readonly ?string $path = null,
    ) {
        parent::__construct("AI の応答を解釈できませんでした。再実行してください。({$reason->value}: {$detail})");
    }

    /** ユーザー向け要約 (内部 detail を error 列へ漏らさない) */
    public function userMessage(): string
    {
        return 'AI の応答を解釈できませんでした。再実行してください。';
    }
}
