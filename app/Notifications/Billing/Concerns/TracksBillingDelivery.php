<?php

declare(strict_types=1);

namespace App\Notifications\Billing\Concerns;

use App\Enums\Billing\BillingNotificationType;

/**
 * 請求通知が billing_notifications の delivery record と結びつくための自然キー公開。
 *
 * listener (NotificationSent) と queued job の failed フックが
 * (type, invoice_id) で該当行を特定して状態遷移させる。
 */
interface TracksBillingDelivery
{
    public function deliveryType(): BillingNotificationType;

    public function deliveryInvoiceId(): string;
}
