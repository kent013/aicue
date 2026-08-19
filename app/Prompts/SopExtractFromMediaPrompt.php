<?php

declare(strict_types=1);

namespace App\Prompts;

use App\DataTransferObjects\LlmCallContextData;
use App\DataTransferObjects\Manual\Analysis\ImageAnalysisMediaData;
use App\DataTransferObjects\Manual\Analysis\PdfAnalysisMediaData;
use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptDefense;

/**
 * SOP 抽出プロンプト (AI 解析 1 段目・OCR 経路。画像・スキャン SOP の OCR 対応)。
 * 検証済み媒体 (画像 / テキスト層の無い PDF) そのもの → 統一 JSON。
 * 出力は既存の text 版と同じ `ExtractedSopData::fromLlmText()` で検証する。
 *
 * untrusted な自由記述テキスト変数は持たない (媒体そのものが入力であるため)。
 * 帰属 (`LlmCallContextData`) は他の解析 3 段と同じく必須のまま。
 */
final class SopExtractFromMediaPrompt
{
    public static function make(
        ImageAnalysisMediaData|PdfAnalysisMediaData $media,
        LlmCallContextData $context,
    ): GuardedPrompt {
        return PromptDefense::loadWithMedia(
            template: 'sop-extract-media',
            untrusted: [],
            media: $media,
            context: $context,
        );
    }
}
