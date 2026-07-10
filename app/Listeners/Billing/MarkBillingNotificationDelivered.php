<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Notifications\Billing\Concerns\TracksBillingDelivery;
use App\Notifications\Billing\Concerns\TracksBillingReminderDelivery;
use App\Support\Billing\BillingNotificationRecorder;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * 通知の実送信成功で billing_notifications を sent に確定する。
 *
 * handle() の typehint で event を推論できるため auto-discovery で登録される
 * (AppServiceProvider の Event::listen には登録しない = 二重発火回避)。
 */
class MarkBillingNotificationDelivered
{
    public function handle(NotificationSent $event): void
    {
        $notification = $event->notification;
        if ($notification instanceof TracksBillingDelivery) {
            BillingNotificationRecorder::markSent(
                $notification->deliveryType(),
                $notification->deliveryInvoiceId(),
            );

            return;
        }

        if ($notification instanceof TracksBillingReminderDelivery) {
            BillingNotificationRecorder::markSentByDedupKey(
                $notification->deliveryType(),
                $notification->deliveryDedupKey(),
            );
        }
    }
}
