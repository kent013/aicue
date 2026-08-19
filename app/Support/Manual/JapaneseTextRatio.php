<?php

declare(strict_types=1);

namespace App\Support\Manual;

/**
 * 日本語比率の判定ロジック (画像・スキャン SOP の OCR 対応)。
 * `SopTextExtractor` の文書受理ゲート判定と `AnalysisAcceptanceGate` (OCR 経路の成功条件)
 * の両方が使う共有ユーティリティ。副作用を持たない。
 *
 * SJIS 復元判定専用のロジック (`countBy()` 相当・半角カナを含む/含まないパターンの使い分け) は
 * `SopTextExtractor` に残す (OCR 経路とは無関係の別の問い)。
 */
final class JapaneseTextRatio
{
    /** 日本語文字 (かな / 漢字 / 全角句読点 / 全角英数記号 / 半角カナ) */
    private const JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
        .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}\x{FF66}-\x{FF9D}]/u';

    /** 比率の分母 = 空白を除いた文字数 */
    private const NON_SPACE_PATTERN = '/[^\s\x{3000}]/u';

    /** 空白を除いた文字数に占める日本語文字の比率 (0.0〜1.0) */
    public static function of(string $text): float
    {
        $assessable = self::countBy(self::NON_SPACE_PATTERN, $text);

        return $assessable === 0 ? 0.0 : self::countBy(self::JAPANESE_PATTERN, $text) / $assessable;
    }

    private static function countBy(string $pattern, string $text): int
    {
        $count = preg_match_all($pattern, $text);

        return is_int($count) ? $count : 0;
    }
}
