<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\Enums\Billing\BillingRetentionTarget;
use App\Models\Billing\StripeWebhookEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * 決済事業者の webhook 記録の purge (起算点 = processed_at)。
 *
 * 未処理 (processed_at IS NULL) のまま保持期限を超えた行は**異常**として計上するだけで
 * 消さない (取引が決着していない記録を、決着したことにして捨てない)。
 *
 * @extends AbstractBillingRetentionPurger<StripeWebhookEvent>
 */
final class StripeWebhookEventPurger extends AbstractBillingRetentionPurger
{
    public function target(): BillingRetentionTarget
    {
        return BillingRetentionTarget::StripeWebhookEvent;
    }

    /** @return Builder<StripeWebhookEvent> */
    protected function baseQuery(): Builder
    {
        return StripeWebhookEvent::query();
    }
}
