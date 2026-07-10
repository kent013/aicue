<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * チケット予約の状態。reserved だけが残高を拘束する。
 * 遷移は reserved → committed / released の一方向のみ (TicketLedgerService が強制)。
 */
enum TicketReservationStatus: string
{
    case Reserved = 'reserved';
    case Committed = 'committed';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => '予約中',
            self::Committed => '確定',
            self::Released => '解放',
        };
    }

    /** 残高を拘束している (= active な) 予約か */
    public function holdsBalance(): bool
    {
        return $this === self::Reserved;
    }
}
