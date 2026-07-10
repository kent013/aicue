<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\DataTransferObjects\Billing\TicketVolumeTier;
use App\Exceptions\Billing\TicketVolumeTierUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Webmozart\Assert\Assert;

/**
 * スポット購入の数量逐減 (volume tier) 単価の Stripe one-time Price DB snapshot。
 *
 * tier 解決は `min_count <= count` を満たす最大 `min_count` の current 行
 * (currentTierFor)。min_count=1 の行が spot 単価を兼ねる。
 *
 * invariant (DB CHECK + 生成列 partial UNIQUE で機械強制):
 * - `is_current=true ⇔ active_to IS NULL`
 * - current は min_count (段) あたり 1 行
 *
 * 行の投入は TicketVolumePriceSeeder (bootstrap。オプトイン) が行い、
 * Stripe との同期反映は派生アプリの sync 拡張点に委ねる。
 *
 * @property int $id
 * @property string $lookup_key
 * @property string $stripe_price_id
 * @property int $min_count
 * @property int $unit_amount
 * @property string $currency
 * @property string|null $nickname
 * @property bool $livemode
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable $active_from
 * @property CarbonImmutable|null $active_to
 * @property bool $is_current
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TicketVolumePrice extends Model
{
    /** 購入枚数の下限 (tier 解決の入力検証) */
    public const int PURCHASE_MIN_COUNT = 1;

    /** 購入枚数の上限 (tier 解決の入力検証。異常量の Checkout 構築を弾く) */
    public const int PURCHASE_MAX_COUNT = 1000;

    /** @var list<string> */
    protected $fillable = [
        'lookup_key',
        'stripe_price_id',
        'min_count',
        'unit_amount',
        'currency',
        'nickname',
        'livemode',
        'synced_at',
        'active_from',
        'active_to',
        'is_current',
    ];

    /**
     * 購入枚数に適用される逐減単価 tier を解決する。
     *
     * - current 行のうち `min_count <= count` を満たす最大 min_count 行を採用する
     * - 該当 0 件は TicketVolumeTierUnavailableException (別段へ黙って落とさない = fail-closed)
     * - production では未 sync (livemode=false or synced_at null) の Price を Assert で弾く
     * - 解決単価が floor (config billing.ticket_unit_price_floor) 未満は設定異常として停止する
     */
    public static function currentTierFor(int $count): TicketVolumeTier
    {
        Assert::range(
            $count,
            self::PURCHASE_MIN_COUNT,
            self::PURCHASE_MAX_COUNT,
            sprintf('チケット購入数は %d〜%d の範囲で指定してください', self::PURCHASE_MIN_COUNT, self::PURCHASE_MAX_COUNT),
        );

        $row = self::query()
            ->where('is_current', true)
            ->where('min_count', '<=', $count)
            ->orderByDesc('min_count')
            ->first();

        if (! $row instanceof self) {
            throw new TicketVolumeTierUnavailableException($count);
        }

        if (app()->environment('production')) {
            Assert::true(
                $row->livemode && $row->synced_at !== null,
                "Stripe 未同期の ticket volume price は本番で使用不可: {$row->lookup_key}",
            );
        }

        // 単価 floor 検証 (seed / sync の設定異常で傾斜表が floor を割った場合に停止する)
        $floor = config('billing.ticket_unit_price_floor');
        Assert::integer($floor, 'config billing.ticket_unit_price_floor は整数で設定してください');
        Assert::greaterThanEq(
            $row->unit_amount,
            $floor,
            "チケット単価 {$row->unit_amount} が floor {$floor} を下回っています (lookup_key={$row->lookup_key})",
        );

        return new TicketVolumeTier(
            minCount: $row->min_count,
            unitAmount: $row->unit_amount,
            stripePriceId: $row->stripe_price_id,
            lookupKey: $row->lookup_key,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_count' => 'integer',
            'unit_amount' => 'integer',
            'livemode' => 'boolean',
            'is_current' => 'boolean',
            'synced_at' => 'immutable_datetime',
            'active_from' => 'immutable_datetime',
            'active_to' => 'immutable_datetime',
        ];
    }
}
