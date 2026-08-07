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

/**
 * 招待中の 1 行。招待は org ロールだけを持つ (役割付き招待は裁定 AG-079 で撤去)。
 * メンバー行の 5 値表示状態 (MemberRoleState) とは語彙が違う
 * (招待中の行は「未割当」ではなく、まだ参加していないだけ)。
 */
export interface InvitationRow {
    id: number;
    email: string;
    /** App\Enums\OrganizationRole の value (organization_admin / organization_member) */
    role: string;
    roleLabel: string;
    expiresAt: string;
}
