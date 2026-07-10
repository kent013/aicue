<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Billing\TicketVolumePrice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Webmozart\Assert\Assert;

/**
 * スポット購入の数量逐減 (volume tier) 単価のサンプル段を `ticket_volume_prices` に
 * bootstrap 投入する。
 *
 * **オプトイン**: DatabaseSeeder には登録しない。傾斜単価を使う派生アプリだけが
 * DatabaseSeeder へ追加し、TIERS を自アプリの価格表に書き換えて使う (サンプル段:
 * 1〜 ¥100 / 20〜 ¥80 / 50〜 ¥70 / 100〜 ¥65 / 200〜 ¥60 / 300〜 ¥55 / 500〜 ¥50(floor))。
 *
 * PlanSeeder と同じ bootstrap 規約:
 * - sync 済 row (livemode=true OR synced_at NOT NULL) は触らない
 *   (価格差分の反映は Stripe 同期側に閉じる)
 * - stripe_price_id は `price_test_*` のプレースホルダ (Stripe 同期で実 ID に置換する)
 * - lookup_key は `{app_slug}_ticket_volume_{min_count}` 規約 (slug は config/template.php)
 * - 投入単価が floor (config billing.ticket_unit_price_floor) を下回る設定異常は Assert で停止
 */
class TicketVolumePriceSeeder extends Seeder
{
    /**
     * 段別単価表 (min_count => unit_amount)。min_count=1 の行が spot 単価を兼ねる。
     *
     * @var array<int, int>
     */
    private const array TIERS = [
        1 => 100,
        20 => 80,
        50 => 70,
        100 => 65,
        200 => 60,
        300 => 55,
        500 => 50,
    ];

    public function run(): void
    {
        $floor = config('billing.ticket_unit_price_floor');
        Assert::integer($floor, 'config billing.ticket_unit_price_floor は整数で設定してください');

        $slug = config()->string('template.slug');

        foreach (self::TIERS as $minCount => $unitAmount) {
            Assert::greaterThanEq(
                $unitAmount,
                $floor,
                "volume tier 単価 {$unitAmount} (min_count={$minCount}) が floor {$floor} を下回っています",
            );

            $lookupKey = "{$slug}_ticket_volume_{$minCount}";

            $current = TicketVolumePrice::query()
                ->where('min_count', $minCount)
                ->where('is_current', true)
                ->first();

            // sync 済 row は触らない (= bootstrap 専用)
            if ($current instanceof TicketVolumePrice && ($current->livemode || $current->synced_at !== null)) {
                continue;
            }

            TicketVolumePrice::query()->updateOrCreate(
                ['min_count' => $minCount, 'is_current' => true],
                [
                    'lookup_key' => $lookupKey,
                    'stripe_price_id' => "price_test_{$lookupKey}",
                    'unit_amount' => $unitAmount,
                    'currency' => 'jpy',
                    'nickname' => "チケット {$minCount} 枚〜",
                    'livemode' => false,
                    'synced_at' => null,
                    'active_from' => CarbonImmutable::now(),
                    'active_to' => null,
                ],
            );
        }
    }
}
