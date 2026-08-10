<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\WebhookEventStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Billing\StripeWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stripe webhook の冪等記録 (event_id UNIQUE で二重処理を防ぐ冪等マシン)。
 *
 * 全カラムが StripeWebhookProcessor の内部状態のため $fillable は持たない
 * (明示代入のみ。クライアント入力は一切入らない)。
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property WebhookEventStatus $status
 * @property array<mixed> $payload
 * @property int $attempts
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $processed_at
 */
class StripeWebhookEvent extends Model
{
    /** @use HasFactory<StripeWebhookEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
