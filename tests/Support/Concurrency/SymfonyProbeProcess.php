<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException as SymfonyProcessRuntimeException;
use Symfony\Component\Process\Process;
use Webmozart\Assert\Assert;

/**
 * {@see Process} を包む唯一の実装。
 *
 * ★**`waitFor()` は Symfony の `wait()` を包まない**。`Process::wait()` は秒数を受け取る
 *   API ではない (`waitUntil()` は述語を取るがタイムアウトは Process 自身の設定に依る)。
 *   ここでは **`isRunning()` と単調時計 (`hrtime`) で bounded wait を自前実装する**
 *   (ポーリング + 上限)。`$seconds` に 0 を渡した場合は 1 度だけ状態を確かめて返す。
 * ★シグナル送出は生存しているときだけ行う (`Process::signal()` は停止済みに投げると例外)。
 *   既に止まっている子へ送らないことは回収の契約を弱めない (止まっているのが目的だから)。
 */
final class SymfonyProbeProcess implements ProbeProcess
{
    /** 終了待ちのポーリング間隔 (マイクロ秒) */
    private const int POLL_INTERVAL_MICROSECONDS = 1_000;

    public function __construct(private readonly Process $process) {}

    public function start(): void
    {
        $this->process->start();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function output(): string
    {
        return $this->process->getOutput();
    }

    public function errorOutput(): string
    {
        return $this->process->getErrorOutput();
    }

    public function signalTerminate(): void
    {
        $this->send(SIGTERM);
    }

    public function signalKill(): void
    {
        $this->send(SIGKILL);
    }

    public function waitFor(float $seconds): ?int
    {
        Assert::greaterThanEq($seconds, 0.0);

        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);

        while (true) {
            if (! $this->process->isRunning()) {
                return $this->process->getExitCode();
            }

            if (hrtime(true) >= $deadline) {
                return null;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    private function send(int $signal): void
    {
        if (! $this->process->isRunning()) {
            return;
        }

        try {
            $this->process->signal($signal);
        } catch (LogicException|SymfonyProcessRuntimeException) {
            // 送出と着弾の間に自然終了した / シグナルを送れない環境。
            // 回収の契約は「要求すること」までなので、ここで落とさない
            // (停止を確認できなければ後段の判定が失敗させる)。
        }
    }
}
