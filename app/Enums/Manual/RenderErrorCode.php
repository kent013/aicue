<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * レンダ失敗種別の型付き判別子 (v1 は 3 値で閉じる。概念設計 Round 2/3)。
 * フロントの CTA 分岐は自由文 error でなくこの code で行う (文言変更で壊れない)。
 * TS 側 types/manual.ts の RenderErrorCode union と対で保守する (ManualEnumTsSyncInvariantTest)。
 */
enum RenderErrorCode: string
{
    /** preview のトリガー後にシナリオが編集された (「作り直す」CTA) */
    case ScenarioVersionChanged = 'scenario_version_changed';

    /** stale 回復 / timeout kill */
    case Timeout = 'timeout';

    /** それ以外 (詳細は report() に送られる) */
    case Internal = 'internal';
}
