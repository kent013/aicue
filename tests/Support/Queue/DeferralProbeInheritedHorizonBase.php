<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;

/**
 * C4 正例 fixture の親。**`retryUntil()` の宣言はこちらにしかない**。
 *
 * - `SELF_HORIZON_MINUTES` は **private** … 子の `ReflectionClass::getConstant()` では
 *   取れない (false になる)。`self::` を**宣言クラス**から解決していないと偽レッドになる。
 * - `STATIC_HORIZON_MINUTES` は **protected** … `static::` を**検査対象クラス**から解決する
 *   ことの証明に使う (子が 0 で上書きすれば違反になるはず)。
 * - `ALIASED_HORIZON_MINUTES` は **public** … alias 解決の fixture が外から参照する。
 */
abstract class DeferralProbeInheritedHorizonBase
{
    private const SELF_HORIZON_MINUTES = 15;

    protected const STATIC_HORIZON_MINUTES = 10;

    public const ALIASED_HORIZON_MINUTES = 20;

    /** C4 負例 (クラス定数が負) の合成ソースが参照する。 */
    public const NEGATIVE_HORIZON_MINUTES = -5;

    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::SELF_HORIZON_MINUTES)->addMinutes(static::STATIC_HORIZON_MINUTES);
    }
}
