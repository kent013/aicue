<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\BillingNotificationStatus;
use App\Enums\Billing\BillingNotificationType;
use App\Models\Organization;
use Database\Factories\Billing\BillingNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 請求通知の delivery record (通知台帳)。送信の冪等と配送状態 (queued/sent/failed) を保持する。
 *
 * 自然キーは 2 経路:
 *  - 支払い通知: (type, invoice_id) 複合 UNIQUE
 *  - 予告 (reminder): (type, dedup_key) 複合 UNIQUE
 *
 * @property int $id
 * @property int $organization_id
 * @property BillingNotificationType $type
 * @property string|null $invoice_id
 * @property string|null $dedup_key
 * @property BillingNotificationStatus $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BillingNotification extends Model
{
    /** @use HasFactory<BillingNotificationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'invoice_id',
        'dedup_key',
        'status',
        'sent_at',
        'failed_at',
        'error',
    ];

    /**
     * 組織境界キーは mass assignment から除外し associate / 明示代入経由で設定する。
     *
     * @var list<string>
     */
    protected $guarded = [
        'id',
        'organization_id',
    ];

    protected static function newFactory(): BillingNotificationFactory
    {
        return BillingNotificationFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BillingNotificationType::class,
            'status' => BillingNotificationStatus::class,
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
