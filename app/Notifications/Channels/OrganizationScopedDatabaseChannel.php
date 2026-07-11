<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Notifications\InApp\AppNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * 標準 DatabaseChannel の公式拡張点 (buildPayload) に organization_id 列をマージする薄い層。
 * AppServiceProvider で DatabaseChannel::class に container binding して差し替える
 * (ChannelManager::createDatabaseDriver は container 経由で解決するため binding が効く)。
 * AppNotification 以外の通知は素通し (後方互換)。
 */
class OrganizationScopedDatabaseChannel extends DatabaseChannel
{
    /**
     * @param  mixed  $notifiable
     * @return array<mixed> (親シグネチャ互換。実体は DatabaseNotification の列名 => 値)
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof AppNotification) {
            $payload['organization_id'] = $notification->organizationId();
        }

        return $payload;
    }
}
