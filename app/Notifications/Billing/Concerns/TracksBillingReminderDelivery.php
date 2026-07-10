<?php

declare(strict_types=1);

namespace App\Notifications\Billing\Concerns;

use App\Enums\Billing\BillingNotificationType;

/**
 * 予告 (reminder) 通知が billing_notifications の delivery record と結びつくための自然キー公開。
 *
 * 支払い通知の `TracksBillingDelivery` (invoice_id 経路) とは別に、予告は `dedup_key`
 * (`{stripe_id}:{該当日時 epoch}`) で (type, dedup_key) を冪等管理する。
 */
interface TracksBillingReminderDelivery
{
    public function deliveryType(): BillingNotificationType;

    public function deliveryDedupKey(): string;
}
