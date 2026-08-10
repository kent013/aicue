<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\Enums\Billing\BillingRetentionTarget;
use App\Services\Billing\Contracts\BillingRetentionPurger;
use App\Services\Billing\TicketLedgerCarryForwardService;
use Carbon\CarbonImmutable;

/**
 * チケット台帳の purger (**物理削除ではなく畳み込み**)。
 *
 * 他の target は行を消して決着させるが、台帳は残高の真実源であり、消すと残高が変わる。
 * よってここは {@see AbstractBillingRetentionPurger} を継承せず、畳み込み
 * ({@see TicketLedgerCarryForwardService}) への薄い adapter に徹する。
 *
 * ★`countFailClosed()` は常に 0 である。台帳は補助時計 (起算不能の異常検出) を持たず
 *   (`created_at` は必ず入る)、参照されて消せない行も無い。決着できなかった組織は
 *   `unexpectedFailures` として報告され、その行は `expiredRemaining` に残る
 *   — 「安全のため残した」と「決着できなかった」を混同しない。
 */
final class TicketLedgerEntryPurger implements BillingRetentionPurger
{
    public function __construct(
        private readonly TicketLedgerCarryForwardService $carryForward,
    ) {}

    public function target(): BillingRetentionTarget
    {
        return BillingRetentionTarget::TicketLedgerEntry;
    }

    public function countExpired(CarbonImmutable $threshold): int
    {
        return $this->carryForward->countExpired($threshold);
    }

    public function countFailClosed(CarbonImmutable $threshold): int
    {
        return 0;
    }

    public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        return $this->carryForward->carryForward($threshold);
    }
}
