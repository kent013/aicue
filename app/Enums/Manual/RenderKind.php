<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * レンダジョブの操作種別 (§10.8-8「preview と render は別操作種別」の実体)。
 * in-flight 判定・課金有無 (render のみチケット消費)・manual status 遷移有無が異なる。
 * TS 側 types/manual.ts の RenderKind union と対で保守する
 * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
 */
enum RenderKind: string
{
    /** 完成動画レンダ (チケット消費・ready→rendering→published) */
    case Render = 'render';

    /** プレビュー生成 (チケット非消費・manual status 遷移なし) */
    case Preview = 'preview';
}
