<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\TicketReservationStatus;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * チケット予約 (reserve → commit / release の 2 フェーズ消費の前半)。
 *
 * organization_id / status / amount / expires_at はすべて TicketLedgerService が
 * 管理する状態のため $fillable は持たない (明示代入のみ)。
 * 状態遷移も同 Service 経由のみ (直接 update を書かない)。
 *
 * @property int $id
 * @property int $organization_id
 * @property int $amount
 * @property TicketReservationStatus $status
 * @property CarbonImmutable $expires_at
 */
class TicketReservation extends Model
{
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
            'amount' => 'integer',
            'status' => TicketReservationStatus::class,
            'expires_at' => 'immutable_datetime',
        ];
    }
}
