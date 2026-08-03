<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\TicketCheckoutSessionStatus;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Billing\TicketCheckoutSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * チケットスポット購入の Stripe Checkout Session 追跡行 (冪等マシンの真実源)。
 *
 * - 二重課金防止: UNIQUE(organization_id, attempt_token) + UNIQUE(stripe_session_id) +
 *   live pending dedup (TicketCheckoutService)
 * - webhook (checkout.session.completed) は本行の count / unit_amount / currency /
 *   organization を真実源として金額照合・付与する (payload の metadata は照合専用)
 * - 全列 Service の明示代入のみ ($fillable = [])
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $initiated_by_user_id
 * @property int $ticket_count
 * @property int $unit_amount
 * @property string $currency
 * @property string $stripe_session_id
 * @property string $attempt_token
 * @property string $checkout_url
 * @property TicketCheckoutSessionStatus $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TicketCheckoutSession extends Model
{
    /** @use HasFactory<TicketCheckoutSessionFactory> */
    use HasFactory;

    /** @var list<string> 全列を Service が明示代入する (状態キー・FK は relation / 直接代入のみ) */
    protected $fillable = [];

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
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** live pending (= replay 可能な決済待ち) か。期限切れ pending は live ではない。 */
    public function isLivePending(CarbonImmutable $now): bool
    {
        return $this->status === TicketCheckoutSessionStatus::Pending
            && $this->expires_at->greaterThan($now);
    }

    /**
     * Scope: live pending (isLivePending と同一定義) に絞る。
     *
     * 行判定 (isLivePending) と SQL 判定の定義が乖離しないよう、live pending の意味論は
     * 本 model の 2 メソッドに閉じる (Service 側で status / expires_at を直書きしない)。
     * 等価性は TicketCheckoutSessionLivePendingTest が固定する。
     *
     * @param  Builder<TicketCheckoutSession>  $query
     */
    public function scopeLivePending(Builder $query, CarbonImmutable $now): void
    {
        $query->where('status', TicketCheckoutSessionStatus::Pending)
            ->where('expires_at', '>', $now);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_count' => 'integer',
            'unit_amount' => 'integer',
            'status' => TicketCheckoutSessionStatus::class,
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
