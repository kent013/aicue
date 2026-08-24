<?php

declare(strict_types=1);

namespace Tests\Support\Process;

use Webmozart\Assert\Assert;

/**
 * probe 1 回分の観測結果 (一時ディレクトリを消す前に採取したスナップショットを含む)。
 *
 * `Tests\Support\Process\BootProbeRunner` が唯一の生成者である (lctl feature:
 * subprocess-boot-probe-harness)。
 */
final readonly class BootProbeResult
{
    /**
     * @param  non-negative-int  $exitCode  強制終了なら BootProbeRunner::TIMEOUT_EXIT_CODE
     * @param  non-empty-string  $temporaryRoot  実行に使った一時ディレクトリ (実行後は消えている)
     * @param  list<non-empty-string>  $writtenRelativePaths  一時ディレクトリ配下に書かれたもの (昇順)
     * @param  positive-int  $pid  回収した子の pid。**回収済みの死骸の番号**であり操作対象ではない
     *                             (自己検査が「子が残っていない」ことを確かめるためだけに持つ)
     */
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $timedOut,
        public float $elapsedSeconds,
        public string $temporaryRoot,
        public array $writtenRelativePaths,
        public int $pid,
    ) {
        Assert::natural($exitCode);
        Assert::true(
            is_finite($elapsedSeconds) && $elapsedSeconds >= 0.0,
            '所要時間が有限の非負値でない',
        );
        Assert::stringNotEmpty($temporaryRoot);
        Assert::allStringNotEmpty($writtenRelativePaths);
        Assert::positiveInteger($pid);
    }
}
