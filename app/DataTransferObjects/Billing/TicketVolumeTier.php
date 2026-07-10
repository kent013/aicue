<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * スポット購入の数量逐減 (volume tier) で解決された 1 段分の単価 snapshot。
 *
 * `ticket_volume_prices` current の `min_count <= count` 最大行を表す
 * (min_count=1 の行が spot 単価を兼ねる)。派生アプリの Stripe Checkout は
 * `stripePriceId` × quantity=count で構築し、webhook は `unitAmount` で金額整合を検証する。
 */
final readonly class TicketVolumeTier
{
    public function __construct(
        public int $minCount,
        public int $unitAmount,
        public string $stripePriceId,
        public string $lookupKey,
    ) {}
}
