<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Billing\TicketSource;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Database\Factories\Billing\TicketReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * チケット予約 (reserve → commit / release の 2 フェーズ消費の前半)。
 *
 * organization_id / status / amount / expires_at / consume_* はすべて TicketLedgerService が
 * 管理する状態のため $fillable は持たない (明示代入のみ)。
 * 状態遷移も同 Service 経由のみ (直接 update を書かない)。
 *
 * consume_source / consume_expires_at は「消費する期間 = 予約した期間」を予約時に固定する
 * (commit は再探索しない)。両者 null は P5 デプロイ前の in-flight 予約 (legacy)。
 *
 * @property int $id
 * @property int $organization_id
 * @property int $amount
 * @property TicketReservationStatus $status
 * @property CarbonImmutable $expires_at
 * @property ?TicketSource $consume_source
 * @property ?CarbonImmutable $consume_expires_at
 */
class TicketReservation extends Model
{
    /** @use HasFactory<TicketReservationFactory> */
    use HasFactory;

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
            'consume_source' => TicketSource::class,
            'consume_expires_at' => 'immutable_datetime',
        ];
    }
}
