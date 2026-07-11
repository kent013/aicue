<?php

declare(strict_types=1);

namespace App\Notifications\InApp;

use App\Enums\Notification\NotificationType;
use Illuminate\Notifications\Notification;

/**
 * アプリ内 (database channel) 通知の共通基底。
 *
 * - via() は database のみ (メール系既存 Notification とはクラス階層ごと分離)
 * - databaseType() は NotificationType enum の value を返す (クラス名を DB に漏らさない。
 *   規約は InAppNotificationTypeInvariantTest が全派生クラスに deny-by-default で強制)
 * - organizationId() は notifications.organization_id 列の値
 *   (OrganizationScopedDatabaseChannel が読む)。v1 の全通知種別は org 文脈必須のため
 *   non-nullable (DB 列は将来の org 非依存通知に備え nullable のままだが、
 *   「null を書く通知種別は現状存在しない」を NotificationSchemaTest が固定する)
 */
abstract class AppNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type()->value;
    }

    abstract public function type(): NotificationType;

    abstract public function organizationId(): int;

    /**
     * 実装は payload DTO の toArray() を返すのみ (array<string, mixed> を裸で流さない)。
     *
     * @return array<string, int|string|bool|null>
     */
    abstract public function toDatabase(object $notifiable): array;
}
