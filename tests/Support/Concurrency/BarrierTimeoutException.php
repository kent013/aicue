<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use RuntimeException;

/**
 * 締切を超えた (合図が現れなかった / 作業全体の締切を使い切った)。
 *
 * ★プロトコルが破られたこと ({@see ConcurrencyProtocolException}) と**型で分ける**。
 *   探している退行 (二重実行) を「締切超過」という紛らわしい形で出さないためである。
 */
final class BarrierTimeoutException extends RuntimeException
{
    public static function waitingFor(SignalName $name, float $remainingSeconds): self
    {
        return new self(sprintf(
            '合図 "%s" が %.3f 秒以内に現れなかった',
            $name->value,
            $remainingSeconds,
        ));
    }

    /** 作業の締切を使い切った (次の待ちに入れない) */
    public static function workDeadlineExhausted(): self
    {
        return new self('実プロセス並行テストの作業の締切を使い切った (次の待ちに入れない)');
    }

    /**
     * 既に伏せ字にしたメッセージで同じ型を作り直す (診断の choke point 専用)。
     *
     * ★`new ($e::class)(…)` のような**動的 new を使わない**。生成されるクラスが静的に決まらない形は
     *   走査器から見えなくなり、実際に `CachePayloadPlainDataGateTest` の検査 L4h が
     *   deny-by-default で拒否する。型ごとに明示の入口を持つ。
     */
    public static function withRedactedMessage(string $message): self
    {
        return new self($message);
    }

    /** どの子も本処理へ入らないまま作業の締切を超えた */
    public static function waitingForSingleEntered(): self
    {
        return new self('本処理へ入った子が 1 本も現れないまま作業の締切を超えた');
    }
}
