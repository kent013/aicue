<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * チケット台帳エントリ (残高の真実源)。
 *
 * **append-only 不変条件**: 一度書いた行は更新も削除もしない (課金の監査痕跡)。
 * - updated_at を持たない (UPDATED_AT = null)
 * - update / delete は Eloquent イベントで例外化
 *
 * 期限付き付与: expires_at 到達で残高計算 (balance) から外れる。冪等付与は
 * idempotency_key (UNIQUE) で二重付与を防ぐ。買い切り購入行は payment_intent_id /
 * purchase_amount を持ち、返金 (charge.refunded) の逆仕訳 (clawback) の正本になる。
 *
 * 全カラムが TicketLedgerService の内部状態のため $fillable は持たない (明示代入のみ)。
 *
 * @property int $id
 * @property int $organization_id
 * @property int $delta
 * @property TicketLedgerKind $kind
 * @property TicketSource|null $source
 * @property int|null $reservation_id
 * @property string $description
 * @property CarbonImmutable|null $granted_at
 * @property CarbonImmutable|null $expires_at
 * @property string|null $stripe_checkout_session_id
 * @property string|null $payment_intent_id
 * @property int|null $purchase_amount
 * @property string|null $idempotency_key
 * @property CarbonImmutable $created_at
 */
class TicketLedgerEntry extends Model
{
    /** append-only のため updated_at を持たない */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('ticket_ledger_entries は append-only です (update 禁止)');
        });
        static::deleting(function (): never {
            throw new LogicException('ticket_ledger_entries は append-only です (delete 禁止)');
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<TicketReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(TicketReservation::class, 'reservation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'kind' => TicketLedgerKind::class,
            'source' => TicketSource::class,
            'purchase_amount' => 'integer',
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
