<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * AI 解析トリガーが 409 になる理由の判別子 (doc/10 §10.8-8)。
 * TS 側 types/manual.ts の AnalysisConflictType union と対で保守する。
 */
enum AnalysisConflictType: string
{
    /** queued/running の job が既に存在 (in-flight は同一 manual あたり 1 つ) */
    case InFlight = 'in_flight';

    /** status が analyzing/rendering/published (draft/ready のみ解析可) */
    case StatusNotAnalyzable = 'status_not_analyzable';

    /** UI 向け説明文 (サーバ側で確定しクライアントの文言分岐を減らす) */
    public function message(): string
    {
        return match ($this) {
            self::InFlight => 'AI 解析は既に実行中です。完了までお待ちください。',
            self::StatusNotAnalyzable => '現在の状態では AI 解析を実行できません。解析・書き出しの完了後にお試しください。',
        };
    }
}
