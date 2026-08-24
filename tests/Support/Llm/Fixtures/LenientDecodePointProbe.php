<?php

declare(strict_types=1);

namespace Tests\Support\Llm\Fixtures;

use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Support\Manual\LlmJson;

/**
 * 検査 7 (復号点の公開面の pin) の**負例**。
 *
 * 「緩い入口を後から足した」状態を再現する見本で、`DecodePointPublicSurface::violations()`
 * という**本番 gate と同一の判定経路**へ渡して赤くなることを確かめる
 * (負例が別ロジックで数える形だと、負例が本番 gate の検出力を証明しない)。
 *
 * ★実行経路は持たない (どこからも呼ばれない)。中身は公開面の形だけが意味を持つ。
 */
final class LenientDecodePointProbe
{
    /**
     * @return array<array-key, mixed>
     */
    public static function decode(string $text): array
    {
        return LlmJson::decode($text);
    }

    public static function schemaViolation(string $detail, ?string $path = null): LlmOutputInvalidException
    {
        return LlmJson::schemaViolation($detail, $path);
    }

    /**
     * 緩い入口 (これが増えたら赤くなる、が検査 7 の主張)。
     *
     * @return array<array-key, mixed>
     */
    public static function decodeLenient(string $text): array
    {
        return LlmJson::decode($text);
    }
}
