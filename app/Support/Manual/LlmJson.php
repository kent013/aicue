<?php

declare(strict_types=1);

namespace App\Support\Manual;

use App\Enums\Manual\LlmOutputInvalidReason;
use App\Exceptions\Manual\LlmOutputInvalidException;

/**
 * LLM 出力テキストの JSON デコード共通ヘルパ (コードフェンス除去 + json_decode + array 検証)。
 * 不正は LlmOutputInvalidException (有界リトライのトリガー)。
 */
final class LlmJson
{
    /**
     * @return array<array-key, mixed>
     */
    public static function decode(string $text): array
    {
        $trimmed = trim($text);
        // コードフェンス (```json ... ``` / ``` ... ```) を除去する
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            throw new LlmOutputInvalidException(
                LlmOutputInvalidReason::InvalidJson,
                'JSON としてパースできません: '.json_last_error_msg(),
            );
        }

        return $decoded;
    }

    /** スキーマ違反の例外を生成する (DTO 検証用の短縮形) */
    public static function schemaViolation(string $detail): LlmOutputInvalidException
    {
        return new LlmOutputInvalidException(LlmOutputInvalidReason::SchemaViolation, $detail);
    }
}
