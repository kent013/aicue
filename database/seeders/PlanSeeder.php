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
 * - 月次付与は廃止 (D28)。全 tier の monthly_ticket_grant は 0 で、チケットは
 *   signup grant と都度購入で供給する (列とコード経路は残すため運用上の再開は可能)
 * - 価格の真実源は plan_prices (DB snapshot)。ここでは bootstrap 行
 *   (stripe_price_id=price_test_* / livemode=false / synced_at=null) を投入し、
 *   実運用では `billing:sync-stripe-prices` が Stripe Catalog の実 Price ID へ上書きする
 * - personal プランは Stripe Price を持たない (Checkout 対象外。activate 経由の無料プランで
 *   requiresStripeCheckout()=false)。free entitlement は organizations.free_plan_code='personal'
 *   で表現する。plan_code は entitlement 判定に使わない (quota 解決キーであり、利用可否は
 *   BillingAccess::state() が決める)
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
        'starter' => ['base' => 980],
        'standard' => ['base' => 4980],
    ];

    public function run(): void
    {
        // personal は Checkout を持たないため plan_prices は作らない
        $this->upsertPlan('personal', 'Personal', 1);
        $this->upsertPlan('starter', 'Starter', 2);
        $this->upsertPlan('standard', 'Standard', 3);

        $this->seedPlanPrices();
    }

    /**
     * プラン行を投入する (D28: monthly_ticket_grant は全 tier 0)。
     *
     * is_active は属性配列に入れず新規作成時のみ true を確定する。運用者が管理画面で
     * 変更した公開状態を seed 再実行で踏み潰さないため (公開制御の唯一の場所は
     * PricingService::listPublicPlans() の is_active フィルタ)。
     */
    private function upsertPlan(string $code, string $name, int $sortOrder): void
    {
        $plan = Plan::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'monthly_ticket_grant' => 0,
                'sort_order' => $sortOrder,
            ],
        );

        if ($plan->wasRecentlyCreated) {
            $plan->is_active = true;
            $plan->save();
        }
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
