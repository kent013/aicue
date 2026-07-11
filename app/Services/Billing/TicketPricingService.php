<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\PurchaseTierDto;
use App\Models\Billing\TicketVolumePrice;
use Webmozart\Assert\Assert;

/**
 * チケット価格の表示専用の読み取り口 (消費 = TicketLedgerService / 購入 =
 * TicketCheckoutService の経路とは独立)。
 *
 * tier の権威解決は TicketVolumePrice::currentTierFor に一元化されており、
 * 本 Service は料金表・購入画面が表示する値だけを供給する。
 */
final class TicketPricingService
{
    /**
     * current 全段を min_count 昇順で返す (min_count=1 の行が spot を兼ねる)。
     * 各段の単価が floor (config billing.ticket_unit_price_floor) を下回る
     * 設定異常は Assert で fail-fast する (原価割れ表示を出さない)。
     *
     * @return list<PurchaseTierDto>
     */
    public function volumeTiersForDisplay(): array
    {
        $floor = config('billing.ticket_unit_price_floor');
        Assert::integer($floor, 'config billing.ticket_unit_price_floor は整数で設定してください');

        return array_values(TicketVolumePrice::query()
            ->where('is_current', true)
            ->orderBy('min_count')
            ->get()
            ->map(function (TicketVolumePrice $row) use ($floor): PurchaseTierDto {
                Assert::greaterThanEq(
                    $row->unit_amount,
                    $floor,
                    "チケット単価 {$row->unit_amount} が floor {$floor} を下回っています (lookup_key={$row->lookup_key})",
                );

                return new PurchaseTierDto($row->min_count, $row->unit_amount);
            })
            ->all());
    }

    /**
     * spot 単価 (min_count=1 の current 行)。無ければ
     * TicketVolumeTierUnavailableException (fail-closed。currentTierFor に委譲し二重実装しない)。
     */
    public function spotUnitAmount(): int
    {
        return TicketVolumePrice::currentTierFor(1)->unitAmount;
    }

    /**
     * 初回 signup grant の枚数 (config billing.signup_grant_tickets)。
     * TicketLedgerService::grantSignupGrant と同じ config key を読む表示用の口。
     */
    public function signupGrantTickets(): int
    {
        $count = config('billing.signup_grant_tickets');
        Assert::integer($count, 'config billing.signup_grant_tickets は整数で設定してください');
        Assert::greaterThan($count, 0, 'signup_grant_tickets は 1 以上で設定してください');

        return $count;
    }

    /** 初回 signup grant の有効期限 (日) (config billing.signup_grant_expiry_days)。 */
    public function signupGrantExpiryDays(): int
    {
        $days = config('billing.signup_grant_expiry_days');
        Assert::integer($days, 'config billing.signup_grant_expiry_days は整数で設定してください');
        Assert::greaterThan($days, 0, 'signup_grant_expiry_days は 1 以上で設定してください');

        return $days;
    }
}
