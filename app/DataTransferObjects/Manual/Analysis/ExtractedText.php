<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

/**
 * SOP からの抽出テキスト (SopTextExtractor の出力値オブジェクト)。
 * byteLength は strlen (UTF-8 bytes) = token budget 判定値 (config manual.analysis_max_text_bytes)。
 */
final readonly class ExtractedText
{
    public function __construct(
        public string $text,
        public int $byteLength,
        public string $sourceKind, // pdf | spreadsheet | plain (診断用)
    ) {}
}
