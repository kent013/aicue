<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * スポット購入の逐減単価 (volume tier) を解決できなかった。
 *
 * `ticket_volume_prices` current に `min_count <= count` を満たす行が無い設定異常。
 * 別の (割高な) 単価へ黙って落とさず fail-closed で停止する (意図せぬ高値購入を防ぐ)。
 */
class TicketVolumeTierUnavailableException extends RuntimeException
{
    public function __construct(int $count)
    {
        parent::__construct(
            "購入枚数 {$count} に適用できる volume tier 単価が見つかりません (ticket_volume_prices 設定異常)",
        );
    }
}
