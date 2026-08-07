/**
 * 受信者視点の保留中招待。
 * PHP 側 App\DataTransferObjects\Invitations\PendingInvitationForUserDto::toArray() と対で保守する。
 * 管理者視点の InvitationRow (types/admin.ts) とは別契約 (統合しない)。
 */
export interface PendingInvitation {
    id: number;
    organizationName: string;
    roleLabel: string;
    /** Y-m-d */
    expiresAt: string;
}

/** HandleInertiaRequests が共有する invitations props */
export interface InvitationSharedProps {
    pendingCount: number;
}
