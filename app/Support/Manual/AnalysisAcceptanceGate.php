<?php

declare(strict_types=1);

namespace App\Support\Manual;

use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
use App\Exceptions\Manual\AnalysisFailedException;

/**
 * OCR 経路の成功条件 (画像・スキャン SOP の OCR 対応)。
 *
 * LLM は「読めなければ空を返す」とは限らない。もっともらしい嘘を返すこともある。
 * そこで 1 段目の成功条件を、テキスト経路の既存ゲートと同じ基準で抽出後の JSON にかけ直す。
 * 手順が 1 件以上あることは `ExtractedSopData::fromLlmText()` が既に検証済み
 * (validateStep() で schemaViolation)。ここでは**日本語比率**に加え、
 * テキスト経路の `tooShort` 相当 (`manual.analysis_min_text_bytes` 未満の実質空判定) を
 * 同じ基準でかける (impl-review Round 2 Warning 対応。日本語比率だけだと
 * `work_process: "あ"` のような 1 文字の手順でも比率 1.0 で通過してしまい、
 * テキスト経路の `tooShort` を PDF の OCR フォールバックで実質迂回できる欠陥があった)。
 *
 * [UNREADABLE] マーカー (`sop-extract-media.yaml` が判読不能箇所に使わせる ASCII 文字列) は
 * 日本語文字を 1 つも含まないため、比率計算の分子には寄与しない。判読不能な箇所が多いほど
 * 比率は自然に下がり、ゲートで正しく弾かれる (マーカーを ASCII にしたことで比率計算だけで済む)。
 *
 * ★ 保証しないもの (誇張しない): 「日本語らしい捏造」(資料に無い内容を自然な日本語で書いた
 *   OCR 結果) はこのゲートを通過する。誤読・捏造の是正は既存の「編集する」機能が担う。
 */
final class AnalysisAcceptanceGate
{
    private const string UNREADABLE_MARKER = '[UNREADABLE]';

    /**
     * @throws AnalysisFailedException 日本語比率が不足する場合
     */
    public static function validateOcrResult(ExtractedSopData $data): ExtractedSopData
    {
        $text = $data->textForJapaneseRatioCheck();

        // 構造的な下限: マーカー除去後に文字が 1 つも残らない (= 全手順が判読不能マーカーだけで
        // 構成されている) 場合は、比率計算を待たず無条件で拒否する。
        // ratio は「[UNREADABLE] だけ」でも 0.0 になり既に閾値未満で弾かれるが、
        // この構造的な下限は「なぜ 0.0 なのか」を意味的に固定し、将来 ratio の計算式が
        // 変わっても壊れない安全網として残す。
        if (trim(str_replace(self::UNREADABLE_MARKER, '', $text)) === '') {
            throw AnalysisFailedException::ocrEmptyOrInvalid();
        }

        // テキスト経路の tooShort 相当 (実質空判定)。マーカー除去後のバイト数で判定することで
        // 「[UNREADABLE] を大量に混ぜて分母を稼ぐ」形にも引きずられない。
        $assessableBytes = strlen(str_replace(self::UNREADABLE_MARKER, '', $text));
        if ($assessableBytes < config()->integer('manual.analysis_min_text_bytes')) {
            throw AnalysisFailedException::ocrEmptyOrInvalid();
        }

        $ratio = JapaneseTextRatio::of($text);
        if ($ratio < config()->float('manual.analysis_min_japanese_ratio')) {
            throw AnalysisFailedException::ocrEmptyOrInvalid();
        }

        return $data;
    }
}
