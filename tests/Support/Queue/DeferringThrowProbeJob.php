<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * 振る舞い probe: **未処理例外**を投げるジョブ (B4 用)。
 *
 * 期限内でも `$maxExceptions` に達したところで終端することを pin する。
 */
final class DeferringThrowProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** 例外メッセージ。failed_jobs の理由がこの例外であることの判定に使う。 */
    public const FAILURE_MARKER = 'deferral-probe-unhandled-exception';

    public int $maxExceptions = 3;

    private const HORIZON_MINUTES = 30;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::HORIZON_MINUTES);
    }

    public function handle(): void
    {
        throw new RuntimeException(self::FAILURE_MARKER);
    }
}
