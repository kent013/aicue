<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 付与系チケット台帳エントリの出所。
 *
 * - monthly: サブスク由来の期限付き付与 (月次付与・初回 signup grant)
 * - purchased: 買い切り購入 (無期限。返金時は clawback で逆仕訳される)
 *
 * 消費 (reserve_commit)・解放 (release) 行は出所を持たない (source = null)。
 */
enum TicketSource: string
{
    case Monthly = 'monthly';
    case Purchased = 'purchased';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => '月次付与',
            self::Purchased => '購入',
        };
    }
}
