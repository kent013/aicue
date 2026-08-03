<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\AutoRechargeDisabledReason;
use App\Models\Organization;
use App\Models\User;
use Database\Factories\Billing\TicketAutoRechargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * P8a: 組織のオートリチャージ設定 (1 org 1 行)。
 *
 * 無料パーソナル (Stripe サブスクなし) でも持てるため subscription には依存しない。
 * 残高の真実源は ledger (TicketLedgerEntry)、課金プロセスの状態は TicketAutoRechargeAttempt、
 * 本モデルは「設定 + 同意スナップショット + 連続失敗状態」のみを持つ。
 *
 * @property int $id
 * @property int $organization_id
 * @property bool $enabled
 * @property int $threshold_count
 * @property int $max_count
 * @property string|null $stripe_payment_method_id
 * @property int $failure_count
 * @property AutoRechargeDisabledReason|null $disabled_reason
 * @property Carbon|null $consented_at
 * @property string|null $consent_version
 * @property int|null $consented_max_count
 * @property int|null $consented_max_amount
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TicketAutoRecharge extends Model
{
    /** @use HasFactory<TicketAutoRechargeFactory> */
    use HasFactory;

    /**
     * tenant / actor キー (organization_id / created_by_user_id) は移植元と異なり
     * $fillable に載せない (MassAssignmentProtectedKeys の不変条件。relation 経由のみ)。
     *
     * @var list<string>
     */
    protected $fillable = [
        'enabled',
        'threshold_count',
        'max_count',
        'stripe_payment_method_id',
        'failure_count',
        'disabled_reason',
        'consented_at',
        'consent_version',
        'consented_max_count',
        'consented_max_amount',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
        'threshold_count' => 'integer',
        'max_count' => 'integer',
        'failure_count' => 'integer',
        'disabled_reason' => AutoRechargeDisabledReason::class,
        'consented_at' => 'datetime',
        'consented_max_count' => 'integer',
        'consented_max_amount' => 'integer',
        'organization_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    protected static function newFactory(): TicketAutoRechargeFactory
    {
        return TicketAutoRechargeFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
