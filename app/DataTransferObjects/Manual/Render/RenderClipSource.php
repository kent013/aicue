<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Render;

/**
 * クリップ素材の種別 (compose 段の分岐を型で固定。概念設計 §5)。
 */
enum RenderClipSource: string
{
    /** 採用テイク動画 (video cut) */
    case TakeVideo = 'take_video';

    /** 採用テイク先頭フレームの静止画化 (still cut) */
    case TakeStill = 'take_still';

    /** preview 専用: 黒背景 + 字幕 (採用テイク欠落 cut) */
    case Placeholder = 'placeholder';
}
