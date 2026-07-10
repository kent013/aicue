<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * プロジェクトロール (project_members pivot)。Q2 決定の正規名。
 * AI-CUE のドメイン表示名 (doc/10 §10.5): project_admin=編集者 / project_member=撮影者。
 * permission キー (value) はテンプレート既存のまま rename しない (表示名のみ差し替え)。
 */
enum ProjectRole: string
{
    case Admin = 'project_admin';
    case Member = 'project_member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => '編集者',
            self::Member => '撮影者',
        };
    }
}
