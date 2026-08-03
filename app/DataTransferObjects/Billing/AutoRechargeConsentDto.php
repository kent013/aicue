<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * P8a: オートリチャージ有効化時の off-session mandate 同意。
 *
 * client から受けるのは version のみ。同意金額 (consented_max_amount) はサーバが現行カタログ
 * (TicketVolumePrice::currentTierFor) で再計算する — client hidden の金額は信用しない。
 */
final readonly class AutoRechargeConsentDto
{
    public function __construct(
        public string $version,
    ) {}
}
