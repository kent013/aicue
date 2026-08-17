<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * レンダ/プレビュートリガーが 409 になる理由の判別子 (doc/10 §10.8-8 / 概念設計 §4)。
 * TS 側 types/manual.ts の RenderConflictType union と対で保守する
 * (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)。
 */
enum RenderConflictType: string
{
    /** 同一 manual・同一 kind の in-flight (queued/running) job が既に存在 */
    case InFlight = 'in_flight';

    /** render: status が ready 以外 (published は編集で ready に戻してから) */
    case StatusNotRenderable = 'status_not_renderable';

    /** preview: analyzing / rendering 中 (cuts が動く最中) */
    case StatusNotPreviewable = 'status_not_previewable';

    /** org 同時 preview 上限超過 (config manual.render_max_inflight_previews_per_org) */
    case OrgPreviewLimit = 'org_preview_limit';

    /**
     * UI 向け説明文はサーバで確定 (render/preview で文脈が異なるため case を分け、
     * クライアントの文言分岐を不要にする)。
     */
    public function message(): string
    {
        return match ($this) {
            self::InFlight => '書き出しは既に実行中です。完了までお待ちください。',
            self::StatusNotRenderable => '現在の状態では書き出しを実行できません。シナリオを確定 (準備完了) にしてからお試しください。',
            self::StatusNotPreviewable => '解析・書き出しの実行中はプレビューを生成できません。完了後にお試しください。',
            self::OrgPreviewLimit => '組織内で同時に実行できるプレビュー数の上限に達しています。完了までお待ちください。',
        };
    }
}
