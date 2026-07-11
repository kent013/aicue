<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ユーザー管理画面の表示状態 (毎リクエスト導出。DB に保存しない = backfill 不要)。
 * org ロール × Default Project pivot の全組合せを漏れなく 5 値に分類する
 * (概念設計 D2 の canonical mapping)。
 */
enum MemberRoleState: string
{
    case Owner = 'owner';           // 管理者 (オーナー)。変更不可 (transferOwnership のみ)
    case Admin = 'admin';           // 管理者。stale pivot があっても org ロール優先で無視
    case Editor = 'editor';         // 編集者 (org Member + project_admin)
    case Shooter = 'shooter';       // 撮影者 (org Member + project_member)
    case Unassigned = 'unassigned'; // 未割当 (org Member + pivot なし)。割当を促す表示

    /**
     * org ロール null (organization_user attach 済みだが Laratrust ロール未付与の異常行) も
     * Unassigned へ丸める: 異常行を非表示にせず「未割当」として可視化し、管理画面から
     * ロール割当コマンドで修復できるようにする (applyConsoleRole の修復経路と対)。
     * null 判定は project pivot 判定より**必ず先**に評価する (org ロールなし + stale pivot が
     * Editor/Shooter と誤表示され修復契約と食い違うのを防ぐ)。
     */
    public static function derive(?OrganizationRole $orgRole, ?ProjectRole $projectRole): self
    {
        return match (true) {
            $orgRole === null => self::Unassigned,
            $orgRole === OrganizationRole::Owner => self::Owner,
            $orgRole === OrganizationRole::Admin => self::Admin,
            $projectRole === ProjectRole::Admin => self::Editor,
            $projectRole === ProjectRole::Member => self::Shooter,
            default => self::Unassigned,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => '管理者（オーナー）',
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Shooter => '撮影者',
            self::Unassigned => '未割当',
        };
    }
}
