<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * プロジェクトロール (project_members pivot)。Q2 決定の正規名。
 * ドメイン的な名前 (tutor/trainee 等) が必要なアプリは label() を差し替える。
 */
enum ProjectRole: string
{
    case Admin = 'project_admin';
    case Member = 'project_member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'プロジェクト管理者',
            self::Member => 'プロジェクトメンバー',
        };
    }
}
