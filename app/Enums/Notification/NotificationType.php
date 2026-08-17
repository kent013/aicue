<?php

declare(strict_types=1);

namespace App\Enums\Notification;

/**
 * アプリ内通知の type (単一の正)。
 *
 * - DB (notifications.type) には本 enum の value を格納する (クラス名を DB に置かない。
 *   AppNotification::databaseType() 経由。InAppNotificationTypeInvariantTest が強制)
 * - TS 側 resources/js/types/notification.ts の literal union と値集合を一致させる
 *   (tests/js/architecture/enum-ts-sync.test.ts の目録が固定)
 */
enum NotificationType: string
{
    case ManualAnalyzed = 'manual_analyzed';
    case ManualRendered = 'manual_rendered';
    case InvitationReceived = 'invitation_received';
    case TicketBalanceLow = 'ticket_balance_low';
    // 退会 (猶予期間つき削除) の予約。凍結中でも通知センターは読めるため、
    // 「いつ消えるか / どこで取り消せるか」を本人が確認できる rescue surface になる
    case AccountDeletionRequested = 'account_deletion_requested';
}
