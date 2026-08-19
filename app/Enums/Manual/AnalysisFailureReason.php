<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * AI 解析の抽出失敗理由 (画像・スキャン SOP の OCR 対応)。
 * `AnalysisFailedException` の各 named constructor に対応する。
 * 分岐は message ではなくこの enum で行う (既存の `LlmOutputInvalidException::$reason` /
 * `UntrustedInputRejectionReason` の慣行に倣う)。
 */
enum AnalysisFailureReason: string
{
    /** テキスト抽出不能 (PDF から 1 バイトも取れない = 画像・スキャンの可能性) */
    case Unextractable = 'unextractable';

    /** 抽出できたが本文が実質空 (min_text_bytes 未満) */
    case TooShort = 'too_short';

    /** 抽出はできたが日本語の本文が閾値に満たない */
    case InsufficientJapaneseText = 'insufficient_japanese_text';

    /** LLM 入力上限超過 (テキスト側) */
    case TooLarge = 'too_large';

    /** パイプラインの実時間 deadline 超過 */
    case TimedOut = 'timed_out';

    /** provider の混雑 */
    case ProviderBusy = 'provider_busy';

    /** 応答の防御検査で拒否された (合言葉が応答に現れた) */
    case UnsafeResponse = 'unsafe_response';

    /** 入力の文字コードが壊れている */
    case UnreadableEncoding = 'unreadable_encoding';

    /** OCR 経路: 媒体が破損・未対応形式で読めない (getimagesizefromstring / pdfparser が読めない) */
    case MediaUnreadable = 'media_unreadable';

    /** OCR 経路: 容量・画素数・ページ数の上限超過 */
    case MediaTooLarge = 'media_too_large';

    /**
     * OCR 経路: 読み取り結果の日本語比率不足・判読可能な本文なし。
     * 手順 0 件は `ExtractedSopData::fromLlmText()` が `LlmOutputInvalidException` として
     * 先に検出するため、この reason には到達しない (`AnalysisAcceptanceGate` 参照)。
     */
    case OcrEmptyOrInvalid = 'ocr_empty_or_invalid';

    /**
     * PDF の抽出失敗のうち、既存のテキスト品質ゲート失敗として
     * OCR 経路へ回してよい理由か (概念設計 §入り口 2)。
     */
    public function isOcrEligibleForPdf(): bool
    {
        return match ($this) {
            self::Unextractable, self::TooShort, self::InsufficientJapaneseText => true,
            default => false,
        };
    }
}
