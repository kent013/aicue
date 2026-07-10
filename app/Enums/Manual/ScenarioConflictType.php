<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * シナリオ保存が 409 になる理由の判別子 (doc/10 §10.8-2 / §10.8-6)。
 * TS 側 types/manual.ts の ScenarioConflictType union と対で保守する。
 */
enum ScenarioConflictType: string
{
    case VersionMismatch = 'version_mismatch';
    case Rendering = 'rendering';
    case Analyzing = 'analyzing';

    /** UI 向け説明文 (サーバ側で確定しクライアントの文言分岐を減らす) */
    public function message(): string
    {
        return match ($this) {
            self::VersionMismatch => '他の編集と競合しました。最新のシナリオを取得してから再度保存してください。',
            self::Rendering => '動画の書き出し中のため保存できません。完了後に再度お試しください。',
            self::Analyzing => 'AI 解析中のため保存できません。完了後に再度お試しください。',
        };
    }
}
