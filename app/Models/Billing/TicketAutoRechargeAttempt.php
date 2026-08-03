<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Models\Organization;
use Database\Factories\Billing\TicketAutoRechargeAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * P8a: オートリチャージ試行の状態機械 (課金プロセス管理専用)。
 *
 * - quantity は attempt 作成時 (org 行ロック TX 内) に一度だけ確定し、以降の真実源
 * - unit_amount は webhook amount cross-check の pin
 * - attempt_ulid は Stripe idempotency key / invoice metadata に載せる外部識別子
 * - 「org に pending は 1 つまで」は partial unique index (tar_attempts_org_pending_unique) が保証
 * - failed / canceled への遷移は invoice の終端 (void/delete) 成功後のみ
 *
 * @property int $id
 * @property string $attempt_ulid
 * @property int $organization_id
 * @property AutoRechargeAttemptStatus $status
 * @property int $quantity
 * @property int $unit_amount
 * @property string $stripe_price_id
 * @property string|null $stripe_invoice_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $failure_code
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TicketAutoRechargeAttempt extends Model
{
    /** @use HasFactory<TicketAutoRechargeAttemptFactory> */
    use HasFactory;

    /**
     * tenant キー (organization_id) は移植元と異なり $fillable に載せない
     * (MassAssignmentProtectedKeys の不変条件。relation 経由のみ)。
     *
     * @var list<string>
     */
    protected $fillable = [
        'attempt_ulid',
        'status',
        'quantity',
        'unit_amount',
        'stripe_price_id',
        'stripe_invoice_id',
        'stripe_payment_intent_id',
        'failure_code',
        'resolved_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => AutoRechargeAttemptStatus::class,
        'quantity' => 'integer',
        'unit_amount' => 'integer',
        'resolved_at' => 'datetime',
        'organization_id' => 'integer',
    ];

    protected static function newFactory(): TicketAutoRechargeAttemptFactory
    {
        return TicketAutoRechargeAttemptFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
