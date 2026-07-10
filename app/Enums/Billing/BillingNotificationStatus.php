<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 請求通知 delivery record の配送状態。
 * queued (記録作成) → sent (送信成功) → failed (送信失敗、retry 可)。
 */
enum BillingNotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
