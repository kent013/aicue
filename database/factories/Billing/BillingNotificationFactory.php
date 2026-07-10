<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\BillingNotificationStatus;
use App\Enums\Billing\BillingNotificationType;
use App\Models\Billing\BillingNotification;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 既定は支払い失敗通知 (invoice_id 経路) の queued 行。
 * 予告 (dedup_key 経路) は reminder() state を使う。
 *
 * @extends Factory<BillingNotification>
 */
class BillingNotificationFactory extends Factory
{
    protected $model = BillingNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => BillingNotificationType::PaymentFailed,
            'invoice_id' => 'in_test_'.Str::random(14),
            'dedup_key' => null,
            'status' => BillingNotificationStatus::Queued,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
    }

    /** 予告 (reminder) 行: dedup_key 経路 (invoice_id は null)。 */
    public function reminder(?string $dedupKey = null): static
    {
        return $this->state(fn (): array => [
            'type' => BillingNotificationType::RenewalReminder,
            'invoice_id' => null,
            'dedup_key' => $dedupKey ?? 'sub_test_'.Str::random(14).':'.now()->getTimestamp(),
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => BillingNotificationStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function failed(string $error = 'RuntimeException: test'): static
    {
        return $this->state(fn (): array => [
            'status' => BillingNotificationStatus::Failed,
            'failed_at' => now(),
            'error' => $error,
        ]);
    }
}
