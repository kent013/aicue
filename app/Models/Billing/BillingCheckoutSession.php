<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Database\Factories\Billing\BillingCheckoutSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * サブスク契約 Checkout Session の追跡行 (`BillingAccess::state()` の
 * PendingCheckout / ExpiredCheckout の真実源)。
 *
 * P9 (C-1): **「pending 行が live か」の判定は本クラスの述語だけが定義する**。
 * 閾値 (now - 1day) は staleThresholdAt() の 1 箇所にしか literal として現れず、
 * `BillingAccess::state()` / `SubscriptionService::startCheckout()` の段 2/3/4 /
 * 日次 sweeper (`ReconcileSubscriptionSchedules::expireStaleCheckouts()`) の 4 経路が
 * これを共有する (判定の正しさを sweeper の実行タイミングに依存させない)。
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $initiated_by_user_id
 * @property string $intent
 * @property string|null $plan_code
 * @property string|null $funding_choice
 * @property string $stripe_session_id
 * @property string $idempotency_key
 * @property string|null $attempt_token
 * @property string|null $checkout_url
 * @property string $status
 * @property Carbon|null $completed_at
 * @property Carbon|null $pm_reuse_dispatched_at
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
     * `pm_reuse_dispatched_at` も **意図的に $fillable 外** — webhook (StripeWebhookProcessor)
     * の forceFill 専用 marker であり、クライアント入力・通常の fill 経路では立てない。
     *
     * @var list<string>
     */
    protected $fillable = [
        'intent',
        'plan_code',
        'funding_choice',
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
        'pm_reuse_dispatched_at' => 'datetime',
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
     * live/stale の境界 (**閾値 literal の単一出典**)。
     * Stripe Checkout Session の 24h 自動 expire と一致させる (移植元 aigenba: subDay)。
     *
     * 境界は排他的に統一する:
     *   live  : created_at >= staleThresholdAt($now)   (isLivePending / state() / dedup の SQL filter)
     *   stale : created_at <  staleThresholdAt($now)   (sweeper の expireStaleCheckouts)
     * 両者は補集合であり、境界時刻ちょうどの行が「live かつ Expired 化対象」になることはない。
     *
     * `now()` を内部で呼ばない純関数 (テストが時刻を注入できる)。
     */
    public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDay();
    }

    /**
     * live pending (= 決済待ちとして生きている) か。
     * created_at が null の行は live 扱い (P2 state() の else 分岐と同一)。
     */
    public function isLivePending(CarbonImmutable $now): bool
    {
        return $this->status === CheckoutSessionStatus::Pending->value
            && ($this->created_at === null
                || $this->created_at->greaterThanOrEqualTo(self::staleThresholdAt($now)));
    }

    /**
     * live pending かつ checkout_url 生存 = 復帰可能な進行中 Checkout。
     * 購入導線が resume 状態 (decision URL 再提示) を出すか判定する述語。
     */
    public function isReplayablePending(CarbonImmutable $now): bool
    {
        return $this->isLivePending($now)
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
