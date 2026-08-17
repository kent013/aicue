<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/**
 * C4 **負例** fixture: `static::` 側の定数を 0 で上書きした子。
 *
 * 違反になることで、`static::` が (親ではなく) **検査対象クラスから**解決されていることを証明する。
 */
final class DeferralProbeInheritedHorizonZero extends DeferralProbeInheritedHorizonBase
{
    protected const STATIC_HORIZON_MINUTES = 0;
}
