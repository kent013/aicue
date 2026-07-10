<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 組織ロール (Laratrust team スコープ)。Q2/Q10 決定の正規名。
 * アプリ固有のロール体系が必要な場合もこの 3 値の構造 (owner/admin/member) は維持し、
 * label() とシーダーで表現を差し替える。
 */
enum OrganizationRole: string
{
    case Owner = 'organization_owner';
    case Admin = 'organization_admin';
    case Member = 'organization_member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'オーナー',
            self::Admin => '管理者',
            self::Member => 'メンバー',
        };
    }

    /** 管理権限 (メンバー管理・設定変更) を持つか */
    public function canManage(): bool
    {
        return $this !== self::Member;
    }
}
