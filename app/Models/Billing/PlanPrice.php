<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\PlanPriceKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * プランの Stripe Price (base / seat) の DB snapshot。
 *
 * invariant (DB CHECK + 生成列 partial UNIQUE で強制):
 * - `is_current=true ⇔ active_to IS NULL`
 * - current は plan×kind あたり 1 行
 *
 * 書き込みは bootstrap seeder / `billing:sync-stripe-prices` のみ。
 * plan_id は所有権キーのため $fillable 外 ($plan->prices() relation 経由で生成する)。
 *
 * @property int $id
 * @property int $plan_id
 * @property string $kind
 * @property string $stripe_price_id
 * @property string|null $lookup_key
 * @property int $amount
 * @property string $currency
 * @property bool $livemode
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable $active_from
 * @property CarbonImmutable|null $active_to
 * @property bool $is_current
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PlanPrice extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'kind',
        'stripe_price_id',
        'lookup_key',
        'amount',
        'currency',
        'livemode',
        'synced_at',
        'active_from',
        'active_to',
        'is_current',
    ];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function priceKind(): PlanPriceKind
    {
        return PlanPriceKind::from($this->kind);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'livemode' => 'boolean',
            'is_current' => 'boolean',
            'synced_at' => 'immutable_datetime',
            'active_from' => 'immutable_datetime',
            'active_to' => 'immutable_datetime',
        ];
    }
}
