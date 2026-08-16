<?php

declare(strict_types=1);

namespace App\Support\Capture;

use App\Enums\Manual\MaterialType;
use InvalidArgumentException;

/**
 * 申告 Content-Type → 素材種別の写像 (**この写像を書いてよい唯一の場所**)。
 *
 * 許可集合の正本は config/capture.php の 2 キー
 * (`allowed_video_content_types` / `allowed_still_content_types`) である。
 * 本クラスは「許可集合のどちら側か」だけを答え、許可・不許可の判断はしない
 * (未許可の値は FormRequest の Rule::in で既に落ちている。到達したら整合性異常として fail-loud)。
 *
 * **保証しないもの**: これは**申告**の分類であって、オブジェクトストレージに置かれた
 * バイト列の実際の形式を保証しない。実体検証は行わない
 * (docs/architecture.md §撮影 PWA の「保証しないもの」)。
 */
final class TakeMaterialClassifier
{
    public static function fromContentType(string $contentType): MaterialType
    {
        if (in_array($contentType, config()->array('capture.allowed_video_content_types'), true)) {
            return MaterialType::Video;
        }
        if (in_array($contentType, config()->array('capture.allowed_still_content_types'), true)) {
            return MaterialType::Still;
        }

        // Assert::true(false, …) にしない — 静的解析が never へ絞れるかが stub 依存になり、
        // 「戻り値が無い経路」が型の上で残りうる。throw なら構造的に消える
        throw new InvalidArgumentException("未許可の Content-Type です: {$contentType}");
    }

    /** オブジェクトキーの拡張子 (許可集合と 1 対 1。TakeUploadService::extensionFor から移設) */
    public static function extensionFor(string $contentType): string
    {
        // 2 メソッドで形を揃える (default => throw で戻り値欠落を構造的に消す)
        return match ($contentType) {
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new InvalidArgumentException("未許可の Content-Type です: {$contentType}"),
        };
    }
}
