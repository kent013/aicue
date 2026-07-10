<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use App\Support\Billing\StripePriceLookupKeys;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * プラン定義のシーダー (再実行安全。テストでは TestCase の $seed=true で毎回走る)。
 *
 * - 能力はチケット付与数 (monthly_ticket_grant) と config/quota.php の limits の
 *   「値」で表現する (プラン名でのコード分岐は禁止 = docs 07 ガイド §4)
 * - 価格の真実源は plan_prices (DB snapshot)。ここでは bootstrap 行
 *   (stripe_price_id=price_test_* / livemode=false / synced_at=null) を投入し、
 *   実運用では `billing:sync-stripe-prices` が Stripe Catalog の実 Price ID へ上書きする
 * - free プランは Stripe Price を持たない (Checkout 対象外。未契約の既定)
 */
class PlanSeeder extends Seeder
{
    /**
     * bootstrap 投入する価格 (plan_code → kind → 金額)。
     * 金額は stripe/fixtures/*.json の unit_amount と一致させる
     * (drift は `billing:verify-stripe-prices` が検知する)。
     *
     * @var array<string, array<string, int>>
     */
    private const PRICE_AMOUNTS = [
        'standard' => ['base' => 1980],
    ];

    public function run(): void
    {
        // free は Checkout を持たないため plan_prices は作らない
        Plan::query()->updateOrCreate(
            ['code' => 'free'],
            [
                'name' => 'Free',
                'monthly_ticket_grant' => 10,
                'sort_order' => 0,
            ],
        );

        Plan::query()->updateOrCreate(
            ['code' => 'standard'],
            [
                'name' => 'Standard',
                'monthly_ticket_grant' => 100,
                'sort_order' => 1,
            ],
        );

        $this->seedPlanPrices();
    }

    /**
     * lookup_key 宣言 (StripePriceLookupKeys) に沿って current な plan_prices を
     * bootstrap 投入する。
     */
    private function seedPlanPrices(): void
    {
        foreach (StripePriceLookupKeys::map() as $lookupKey => $target) {
            $amount = self::PRICE_AMOUNTS[$target['plan_code']][$target['kind']->value] ?? null;
            if ($amount === null) {
                continue;
            }

            $plan = Plan::query()->where('code', $target['plan_code'])->firstOrFail();
            $this->ensureCurrentPrice($plan, $target['kind'], $lookupKey, $amount);
        }
    }

    private function ensureCurrentPrice(Plan $plan, PlanPriceKind $kind, string $lookupKey, int $amount): void
    {
        $current = $plan->prices()->where('kind', $kind->value)->where('is_current', true)->first();

        // sync 済 row (本番 Price) は触らない。bootstrap row のみ drift 修復のため updateOrCreate。
        if ($current !== null && ($current->livemode || $current->synced_at !== null)) {
            return;
        }

        $plan->prices()->updateOrCreate(
            ['kind' => $kind->value, 'is_current' => true],
            [
                'lookup_key' => $lookupKey,
                'stripe_price_id' => "price_test_{$lookupKey}",
                'amount' => $amount,
                'currency' => config()->string('cashier.currency'),
                'livemode' => false,
                'synced_at' => null,
                'active_from' => Carbon::now(),
                'active_to' => null,
            ],
        );
    }
}
