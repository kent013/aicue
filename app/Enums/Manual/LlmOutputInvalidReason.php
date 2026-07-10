<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * LLM 出力 JSON の検証失敗分類 (report ログで機械集計する。文字列 drift を型で防止)。
 */
enum LlmOutputInvalidReason: string
{
    /** JSON としてパースできない (切り詰め・コードフェンス外の説明文混入等) */
    case InvalidJson = 'invalid_json';

    /** JSON だがスキーマ違反 (必須キー欠落・型不一致・有界性違反・parent_no 不整合) */
    case SchemaViolation = 'schema_violation';
}
