/**
 * 管理メニュー (ユーザー管理 / カテゴリ管理) の Inertia props 型。
 * PHP 側 DataTransferObjects\Admin\{MemberRowData,InvitationRowData} と対で保守する。
 */

/** ロール遷移コマンド (App\Enums\AdminConsoleRole と対) */
export type ConsoleRole = "admin" | "editor" | "shooter";

/** 表示状態 5 値 (App\Enums\MemberRoleState と対。導出値のためコマンドより広い) */
export type MemberRoleState = ConsoleRole | "owner" | "unassigned";

export interface MemberRow {
    id: number;
    name: string;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    twoFactorStatus: "disabled" | "pending" | "enabled";
    isSelf: boolean;
}

export interface InvitationRow {
    id: number;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    expiresAt: string;
}
