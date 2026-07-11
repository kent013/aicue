/**
 * アプリ内通知 (通知センター) の Inertia props 型。
 * PHP 側 App\Enums\Notification\NotificationType /
 * App\DataTransferObjects\Notification\NotificationListItemData::toArray() と対で保守する
 * (値集合の一致は tests/Architecture/NotificationTypeTsSyncInvariantTest が固定する)。
 */

/** PHP: App\Enums\Notification\NotificationType と対 (値集合を一致させる) */
export type NotificationType =
    | "manual_analyzed"
    | "manual_rendered"
    | "invitation_received"
    | "ticket_balance_low";

/** 解析/レンダ完了通知の payload (manual_analyzed / manual_rendered 共用) */
export interface ManualJobPayload {
    project_id: number;
    manual_id: number;
    manual_title: string;
    organization_name: string;
    succeeded: boolean;
    error: string | null;
}

export interface InvitationReceivedPayload {
    organization_name: string;
}

export interface TicketBalanceLowPayload {
    organization_name: string;
    balance: number;
    threshold: number;
}

/**
 * 通知一覧の 1 行。type を discriminant にした union。
 * 未知 type (enum⇔TS の一時的ドリフト) は string として受け、fallback 描画する。
 */
export interface NotificationItem {
    id: string;
    type: NotificationType | (string & {});
    organization_id: number | null;
    /** ISO8601。null = 未読 */
    read_at: string | null;
    /** ISO8601 */
    created_at: string;
    /** サーバ側の検証復元に失敗した場合は null (fallback 描画) */
    payload: ManualJobPayload | InvitationReceivedPayload | TicketBalanceLowPayload | null;
}

/** HandleInertiaRequests が共有する notifications props */
export interface NotificationSharedProps {
    unreadCount: number;
}
