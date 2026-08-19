<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

/**
 * OCR 経路 (画像・スキャン SOP の OCR 対応) の画像媒体データ。
 * 検証済みの値だけを持つ (MIME sniff・容量・画素数の検証をすべて通った後だけ生成できる)。
 *
 * 生成できるのは `AnalysisMediaValidator` からだけである。
 * 呼び出し箇所は `PromptWindowScanner` の `MediaDataNamedConstructorCall` ルールで
 * deny-by-default 走査・pin する (施策 8)。private constructor は「窓口の外から
 * `new` できない」ことだけを保証し、「渡された値が実際に検証済みか」は
 * `AnalysisMediaValidator` への呼び出し集中 + 静的 gate の組合せで保証する。
 */
final readonly class ImageAnalysisMediaData
{
    private function __construct(
        public string $mime,       // 'image/jpeg' | 'image/png' (検証済み sniff 結果)
        public string $bytes,      // ファイルの生バイト列 (1 度だけ読んだもの)
        public int $sizeBytes,
        public int $width,
        public int $height,
        public int $pixelCount,    // width * height (fromValidated() 内で 1 度だけ計算)
    ) {}

    public static function fromValidated(
        string $mime,
        string $bytes,
        int $sizeBytes,
        int $width,
        int $height,
    ): self {
        return new self($mime, $bytes, $sizeBytes, $width, $height, $width * $height);
    }
}
