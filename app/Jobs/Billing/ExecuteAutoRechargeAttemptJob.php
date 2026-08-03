<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Services\Billing\AutoRechargeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * P8a: pending attempt の課金実行 (invoice 作成 → invoice_id 永続化 → off-session pay)。
 *
 * $tries = 1: queue の自動リトライを使わない。再試行はリコンサイル (i) の管轄 —
 * 同一 idempotency key base で Stripe 冪等が効くため、リトライ主体を一本化して
 * 二重課金の検討面を減らす。
 */
final class ExecuteAutoRechargeAttemptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $attemptId) {}

    public function handle(AutoRechargeService $autoRecharge): void
    {
        $attempt = TicketAutoRechargeAttempt::query()->find($this->attemptId);
        if (! $attempt instanceof TicketAutoRechargeAttempt) {
            return;
        }

        $autoRecharge->executeAttempt($attempt);
    }
}
