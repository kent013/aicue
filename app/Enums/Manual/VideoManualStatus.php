<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * 動画マニュアルの状態 (doc/10 §10.2)。
 * フェーズ1で実際に遷移するのは Draft (作成時既定) のみ。
 * 状態遷移メソッドは解析/レンダの後続フェーズで追加する。
 */
enum VideoManualStatus: string
{
    case Draft = 'draft';
    case Analyzing = 'analyzing';
    case Ready = 'ready';
    case Rendering = 'rendering';
    case Published = 'published';
}
