<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;
use Tests\Support\Queue\DeferralProbeInheritedHorizonBase as Policy;

/**
 * C4 **正例** fixture (alias 解決の証明)。
 *
 * `retryUntil()` の行範囲だけを見て alias 表を**ファイル全体**から作らない実装は
 * `Policy` を解決できず偽レッドになる (断片には `use` 宣言が含まれないため)。
 */
final class DeferralProbeAliasedHorizon
{
    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(Policy::ALIASED_HORIZON_MINUTES);
    }
}
