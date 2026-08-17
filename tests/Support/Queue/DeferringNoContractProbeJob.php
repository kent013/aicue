<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * 振る舞い probe の**対照**: `retryUntil()` も `$maxExceptions` も持たないジョブ。
 *
 * - B3 の対照 … 退避すると回数で終端してしまう (期限が無いので `maxTries` が効く)
 * - B4 の対照 … 例外を積んでも `$maxExceptions` が無いので終端しない
 *
 * 1 クラスで 2 つの対照を兼ねるのは、退避と例外のどちらを起こすかだけが違うためである
 * (契約を持たないという性質は同じ)。
 */
final class DeferringNoContractProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public bool $throws = false) {}

    public function handle(): void
    {
        if ($this->throws) {
            throw new RuntimeException('deferral-probe-no-contract');
        }

        $this->release(0);
    }
}
