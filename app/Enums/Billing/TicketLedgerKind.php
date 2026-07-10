<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * チケット台帳エントリの種別。
 * 残高に影響するのは grant (正) / reserve_commit (負) / adjustment (正負) / clawback (負)。
 * release は予約解放の監査痕跡 (delta=0、残高には影響しない)。
 */
enum TicketLedgerKind: string
{
    case Grant = 'grant';
    case ReserveCommit = 'reserve_commit';
    case Release = 'release';
    case Adjustment = 'adjustment';
    /** 返金 (charge.refunded) による買い切り付与の逆仕訳 */
    case Clawback = 'clawback';

    public function label(): string
    {
        return match ($this) {
            self::Grant => '付与',
            self::ReserveCommit => '消費',
            self::Release => '予約解放',
            self::Adjustment => '調整',
            self::Clawback => '返金逆仕訳',
        };
    }
}
