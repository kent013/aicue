<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/**
 * C4 **正例** fixture: `retryUntil()` を親から継承する (自ファイルに宣言が 1 つも無い)。
 *
 * 素朴に「エントリ自身のファイルを読む」実装では `return` 0 件で偽レッドになり、
 * 素朴に `ReflectionClass($child)->getConstant('SELF_HORIZON_MINUTES')` で解決する実装では
 * private 定数が取れず偽レッドになる。
 */
final class DeferralProbeInheritedHorizon extends DeferralProbeInheritedHorizonBase {}
