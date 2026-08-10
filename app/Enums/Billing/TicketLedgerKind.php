<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * チケット台帳エントリの種別。
 * 残高に影響するのは grant (正) / reserve_commit (負) / adjustment (正負) / clawback (負) /
 * carry_forward (正負)。
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
    /**
     * 保持期間 (7 年) の畳み込みが作る**繰越行**。
     *
     * ★他の case と性質が違う: これは**取引記録ではなく現在残高のスナップショット**である。
     *   `(organization_id, source, expires_at)` ごとに期限超過の取引行を合算した 1 行で、
     *   原取引の識別子 (説明 / stripe id / payment intent / 予約 id / 冪等キー) を
     *   1 つも引き継がない。既存 kind へ相乗りさせないのは、この性質の違いを
     *   型で見えるようにするためである (別物の概念を「似ているから」で統合しない)。
     */
    case CarryForward = 'carry_forward';

    public function label(): string
    {
        return match ($this) {
            self::Grant => '付与',
            self::ReserveCommit => '消費',
            self::Release => '予約解放',
            self::Adjustment => '調整',
            self::Clawback => '返金逆仕訳',
            self::CarryForward => '繰越 (残高スナップショット)',
        };
    }
}
