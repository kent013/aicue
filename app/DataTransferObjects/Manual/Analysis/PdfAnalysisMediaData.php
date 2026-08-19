<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

/**
 * OCR 経路 (画像・スキャン SOP の OCR 対応) の PDF 媒体データ
 * (既存のテキスト品質ゲートに失敗した PDF だけがここへ回る)。
 * 検証済みの値だけを持つ (MIME sniff・容量・ページ数の検証をすべて通った後だけ生成できる)。
 *
 * 生成できるのは `AnalysisMediaValidator` からだけである (`ImageAnalysisMediaData` と同じ規律)。
 */
final readonly class PdfAnalysisMediaData
{
    private function __construct(
        public string $mime,       // 'application/pdf'
        public string $bytes,
        public int $sizeBytes,
        public int $pageCount,
    ) {}

    public static function fromValidated(string $mime, string $bytes, int $sizeBytes, int $pageCount): self
    {
        return new self($mime, $bytes, $sizeBytes, $pageCount);
    }
}
