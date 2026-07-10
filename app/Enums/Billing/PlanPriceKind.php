<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * plan_prices の価格種別。
 *
 * - base: プランの基本料 (テンプレート既定はこれのみ)
 * - seat: 追加席課金 (席課金を導入するアプリの拡張点。schema / 部分 UNIQUE は
 *   plan×kind 軸で用意済みのため、case を使うだけで多軸価格に拡張できる)
 */
enum PlanPriceKind: string
{
    case Base = 'base';
    case Seat = 'seat';
}
