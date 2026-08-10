<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use App\Enums\Billing\WebhookEventStatus;
use App\Models\Billing\StripeWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 既定は受信済み・未処理 (processed_at が null) の webhook 記録。
 *
 * `processed()` で処理完了 (= 保持期間の起算済み) の行を作る。
 *
 * @extends Factory<StripeWebhookEvent>
 */
class StripeWebhookEventFactory extends Factory
{
    protected $model = StripeWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => 'evt_test_'.Str::random(24),
            'type' => 'customer.subscription.updated',
            'status' => WebhookEventStatus::Received,
            'payload' => ['id' => 'evt_test', 'type' => 'customer.subscription.updated'],
            'attempts' => 0,
            'failure_reason' => null,
            'processed_at' => null,
        ];
    }

    /** 処理完了 (保持期間の起算済み) の行。 */
    public function processed(?CarbonImmutable $processedAt = null): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookEventStatus::Processed,
            'processed_at' => $processedAt ?? CarbonImmutable::now(),
        ]);
    }

    /** 処理失敗のまま滞留している行 (起算されない)。 */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookEventStatus::Failed,
            'attempts' => 1,
            'failure_reason' => 'test failure',
            'processed_at' => null,
        ]);
    }
}
