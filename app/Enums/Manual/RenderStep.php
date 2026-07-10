<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * レンダジョブの進行段階 (doc/10 §10.1)。
 * TS 側 types/manual.ts の RenderStep union と対で保守する (ManualEnumTsSyncInvariantTest)。
 */
enum RenderStep: string
{
    /** カットごとのクリップ正規化 + 字幕焼き込み */
    case Compose = 'compose';

    /** 正規化済みクリップの連結 → 最終 mp4 */
    case Concat = 'concat';
}
