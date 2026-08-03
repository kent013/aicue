<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * TicketLedgerService::commit の結果 (aigenba TicketCommitResult verbatim)。
 *
 * commit は pipeline の terminal transaction (成果物確定後) から呼ばれる。
 * no-charge パス (ReleasedExpired) を void で隠さず明示・可観測にするための戻り値。
 * 呼び出し側は分岐に使わない (課金の真実源は台帳)。
 */
enum TicketCommitResult
{
    /** 消費行 (負 delta) を計上して確定した。 */
    case Committed;

    /** 既に committed 済 (冪等 no-op)。 */
    case AlreadyCommitted;

    /**
     * monthly hold が commit 時点で失効していたため課金せず Released にした。
     * 成果物は既に確定済のため完了自体はブロックしない (入口与信は reserve が権威)。
     */
    case ReleasedExpired;
}
