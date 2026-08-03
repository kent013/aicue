<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Services\Billing\AutoRechargeService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * P8a: オートリチャージ pending attempt のリコンサイル (5 分岐)。
 *
 * webhook の terminal ack (MAX_PROCESSING_ATTEMPTS=8 で再送打ち切り) により恒久 drop した
 * 「課金済み・付与なし」の**唯一のセーフティネット**。scheduler で 15 分毎に実行する
 * (routes/console.php。失敗は onFailure → report() で運用アラートへ載る)。
 */
final class ReconcileAutoRechargeAttempts extends Command
{
    protected $signature = 'billing:reconcile-auto-recharge';

    protected $description = 'オートリチャージの pending attempt を回収する (課金済み回収 / 再実行 / 期限切れ終端 / 取りこぼし起票)';

    public function handle(AutoRechargeService $autoRecharge): int
    {
        try {
            /** @var array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int} $stats */
            $stats = Cache::lock('billing:auto-recharge-reconcile', 300)
                ->block(5, fn (): array => $autoRecharge->reconcile());
        } catch (LockTimeoutException $e) {
            $this->error('別プロセスが billing:reconcile-auto-recharge を実行中。exit 1');
            Log::warning('ReconcileAutoRechargeAttempts: lock timeout', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'auto-recharge reconcile: recovered_paid=%d retried=%d sca_reminded=%d expired=%d triggered=%d',
            $stats['recovered_paid'],
            $stats['retried'],
            $stats['sca_reminded'],
            $stats['expired'],
            $stats['triggered'],
        ));

        return self::SUCCESS;
    }
}
