<?php

declare(strict_types=1);

namespace App\Support;

/**
 * withMetadata() で PromptExecution* イベントに流れてくる
 * `array<string, mixed>` metadata バッグからの型安全な値取り出し。
 *
 * listener はこれを経由して mixed を writer DTO に伝播させない。
 * テンプレートで扱う汎用キーは organization_id / user_id / subject_type / subject_id のみ
 * (アプリ固有キーの抽出はアプリ側 listener が追加する)。
 */
final class LlmMetadataExtractor
{
    /**
     * int を厳格に取り出す。float / 負号 / 科学記法 / 前後空白を含む文字列は
     * ctype_digit で弾いて null にする (is_numeric だと '1.5' / '-3' / '1e3' を
     * silently miscoerce しコスト集計を誤る)。
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function extractInt(array $metadata, string $key): ?int
    {
        if (! isset($metadata[$key])) {
            return null;
        }
        $value = $metadata[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * 非空 string のみ取り出す (空文字 / 非 string は null)。
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function extractString(array $metadata, string $key): ?string
    {
        if (! isset($metadata[$key])) {
            return null;
        }
        $value = $metadata[$key];
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * int (auto-increment 主キー) と ULID/UUID string (HasUlids 系) の両方を
     * 吸収して string として返す統合 extractor (subject_id 用)。
     *
     * - int → string キャスト
     * - 数字 string ("123") → そのまま
     * - 非数字 string (ULID "01J..." 等) → そのまま
     * - 空文字 / bool / float / array / null / その他 → null
     *
     * `llm_call_logs.subject_id` カラムは string(64)。ULID 26 文字 / int の十進表記
     * いずれも収まる。
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function extractIntOrString(array $metadata, string $key): ?string
    {
        if (! isset($metadata[$key])) {
            return null;
        }
        $value = $metadata[$key];
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
