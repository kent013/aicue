<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * 表示用の per-source チケット残高 (aigenba TicketBalanceDto verbatim)。
 *
 * monthlyRemaining / purchasedRemaining は出所ごとの生残高を max(…, 0) で clamp した
 * **表示値**。判定 (与信・閾値) には使わないこと — clamp が負残高 (返金逆仕訳による債務) を
 * 隠すため、判定に使うと誤判定する。判定は TicketLedgerService::availableTrueBalance() を使う。
 *
 * @phpstan-type TicketBalanceShape array{
 *   monthlyRemaining: int,
 *   purchasedRemaining: int,
 *   totalAvailable: int,
 *   activeReservations: int,
 *   nextExpireAt: string|null
 * }
 */
final readonly class TicketBalanceDto
{
    public function __construct(
        /** monthly バケットの生残高を clamp した表示値 (hold は控除しない) */
        public int $monthlyRemaining,
        /** purchased バケット (source=purchased ∪ source IS NULL) の生残高を clamp した表示値 */
        public int $purchasedRemaining,
        /** Reserved 予約が拘束している「枚数」(SUM(amount)。legacy 行も計上する保守側) */
        public int $activeReservations,
        /** 未失効・正 delta の最短失効時刻 (ISO8601)。無ければ null */
        public ?string $nextExpireAt,
    ) {}

    /** 表示用の利用可能枚数 (clamp 済み残高 − 拘束枚数。常に 0 以上) */
    public function totalAvailable(): int
    {
        return max($this->monthlyRemaining + $this->purchasedRemaining - $this->activeReservations, 0);
    }

    /**
     * @return TicketBalanceShape
     */
    public function toArray(): array
    {
        return [
            'monthlyRemaining' => $this->monthlyRemaining,
            'purchasedRemaining' => $this->purchasedRemaining,
            'totalAvailable' => $this->totalAvailable(),
            'activeReservations' => $this->activeReservations,
            'nextExpireAt' => $this->nextExpireAt,
        ];
    }
}
