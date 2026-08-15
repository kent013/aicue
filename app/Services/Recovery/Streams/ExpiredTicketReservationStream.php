<?php

declare(strict_types=1);

namespace App\Services\Recovery\Streams;

use App\Contracts\Recovery\StuckWorkStream;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;

/**
 * 期限切れのチケット予約 (TTL 超過 または 失効 monthly hold)。
 *
 * 会計の述語 (失効 monthly hold の判定式) は台帳サービスの中に閉じたままにする。
 * 本 stream は候補の列挙も回収も台帳サービスへ委譲するだけである。
 */
final readonly class ExpiredTicketReservationStream implements StuckWorkStream
{
    public function __construct(private TicketLedgerService $tickets) {}

    public function stream(): RecoveryStream
    {
        return RecoveryStream::TicketReservation;
    }

    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
    {
        return $this->tickets->expiredReservationIds($sweptAt, $afterId, $pageSize);
    }

    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
    {
        return $this->tickets->releaseExpiredReservation($id, $sweptAt)
            ? RecoveryOutcome::Recovered
            : RecoveryOutcome::Skipped; // 並行 commit / release 済み = 正常事象 (失敗ではない)
    }

    public function sweepItemLimit(): ?int
    {
        return null;
    }
}
