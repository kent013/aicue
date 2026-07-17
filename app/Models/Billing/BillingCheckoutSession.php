<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Models\Organization;
use Database\Factories\Billing\BillingCheckoutSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * サブスク契約 Checkout Session の追跡行 (`BillingAccess::state()` の
 * PendingCheckout / ExpiredCheckout の真実源)。
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $initiated_by_user_id
 * @property string $intent
 * @property string|null $plan_code
 * @property string $stripe_session_id
 * @property string $idempotency_key
 * @property string|null $attempt_token
 * @property string|null $checkout_url
 * @property string $status
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BillingCheckoutSession extends Model
{
    /** @use HasFactory<BillingCheckoutSessionFactory> */
    use HasFactory;

    /**
     * tenant / actor キー (organization_id / initiated_by_user_id) は移植元と異なり
     * $fillable に載せない (MassAssignmentProtectedKeys の不変条件。relation / 明示代入のみ)。
     *
     * @var list<string>
     */
    protected $fillable = [
        'intent',
        'plan_code',
        'stripe_session_id',
        'idempotency_key',
        'attempt_token',
        'checkout_url',
        'status',
        'completed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'completed_at' => 'datetime',
        'initiated_by_user_id' => 'integer',
    ];

    protected static function newFactory(): BillingCheckoutSessionFactory
    {
        return BillingCheckoutSessionFactory::new();
    }

    public function intentEnum(): CheckoutIntent
    {
        return CheckoutIntent::from($this->intent);
    }

    public function statusEnum(): CheckoutSessionStatus
    {
        return CheckoutSessionStatus::from($this->status);
    }

    /**
     * Pending かつ checkout_url 生存 = 復帰可能な進行中 Checkout。
     * 購入導線が resume 状態 (decision URL 再提示) を出すか判定する述語。
     */
    public function isReplayablePending(): bool
    {
        return $this->status === CheckoutSessionStatus::Pending->value
            && $this->checkout_url !== null
            && $this->checkout_url !== '';
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
