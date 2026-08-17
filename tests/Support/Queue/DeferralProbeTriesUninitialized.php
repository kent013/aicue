<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/**
 * 負例 fixture: C2 — `$tries` を**既定値なしの typed property** で宣言している。
 *
 * `getDefaultProperties()` で宣言の有無を判定する実装ではここを取りこぼしうる
 * (Codex 実装レビュー Round 1 [Warning])。契約は「宣言しない」なので
 * `ReflectionClass::hasProperty()` で見るのが正しい。
 */
final class DeferralProbeTriesUninitialized
{
    public int $tries;

    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
