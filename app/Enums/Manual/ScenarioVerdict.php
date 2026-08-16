<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * 手順書が動画マニュアルの元資料として成立しているかの所見 (LLM 判断)。
 *
 * **制御フローには使わない** (表示のみ。保存・撮影・レンダを止めない)。
 * TS 側 resources/js/types/manual.ts の ScenarioVerdict union と値集合を一致させる
 * (ManualEnumTsSyncInvariantTest が固定)。
 */
enum ScenarioVerdict: string
{
    case Valid = 'valid';
    case NeedsReview = 'needs_review';
    case Invalid = 'invalid';
}
