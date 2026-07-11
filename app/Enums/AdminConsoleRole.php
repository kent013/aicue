<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 管理メニュー (ユーザー管理) のロール遷移コマンド (doc/02 §2.5 + doc/10 §10.5 の合成)。
 * 保存概念ではない: org ロール + Default Project pivot という既存プリミティブへの
 * 「正規状態への遷移」を表す。表示状態は MemberRoleState (導出) が担う。
 * Owner を含まない = Owner 昇格は transferOwnership のみという不変条件の型表現。
 */
enum AdminConsoleRole: string
{
    case Admin = 'admin';     // 管理者 = org Admin (pivot は掃除)
    case Editor = 'editor';   // 編集者 = org Member + project_admin
    case Shooter = 'shooter'; // 撮影者 = org Member + project_member

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Shooter => '撮影者',
        };
    }

    /** コマンド適用後の org ロール */
    public function organizationRole(): OrganizationRole
    {
        return $this === self::Admin ? OrganizationRole::Admin : OrganizationRole::Member;
    }

    /** コマンド適用後の Default Project pivot ロール (Admin コマンドは pivot なし = null) */
    public function projectRole(): ?ProjectRole
    {
        return match ($this) {
            self::Admin => null,
            self::Editor => ProjectRole::Admin,
            self::Shooter => ProjectRole::Member,
        };
    }
}
