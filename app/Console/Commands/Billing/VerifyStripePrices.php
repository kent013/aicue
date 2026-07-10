<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Services\Billing\StripePriceCatalogClient;
use App\Support\Billing\StripePriceFixtures;
use App\Support\Billing\StripePriceLookupKeys;
use Illuminate\Console\Command;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * stripe/fixtures・Stripe Catalog・plan_prices テーブルの価格整合を検証するコマンド。
 *
 * 通常 CI (`composer test`) の常設ゲートにはしない (Stripe 認証情報を持ち込まない)。
 * release workflow / 認証情報付き workflow / 手動 pre-release check / 定期 drift check
 * で実行する。lookup_key の宣言は {@see StripePriceLookupKeys} (単一出典)。
 */
class VerifyStripePrices extends Command
{
    /** @var string */
    protected $signature = 'billing:verify-stripe-prices';

    /** @var string */
    protected $description = 'stripe/fixtures・Stripe Catalog・plan_prices の価格整合を検証する (release / 定期 / 手動実行)';

    public function handle(StripePriceCatalogClient $client): int
    {
        $lookupKeyMap = StripePriceLookupKeys::map();

        try {
            $fixtures = StripePriceFixtures::load();
        } catch (Throwable $e) {
            $this->error('stripe/fixtures の読み込みに失敗しました: '.$e->getMessage());

            return self::FAILURE;
        }

        $expectedCurrency = config('cashier.currency');
        Assert::stringNotEmpty($expectedCurrency);

        try {
            $stripeEntries = $client->fetchByLookupKeys(array_keys($lookupKeyMap));
        } catch (Throwable $e) {
            $this->error('Stripe Catalog の取得に失敗しました: '.$e->getMessage());

            return self::FAILURE;
        }

        /** @var list<string> $errors */
        $errors = [];
        foreach ($lookupKeyMap as $lookupKey => $target) {
            $plan = Plan::query()->where('code', $target['plan_code'])->first();
            if (! $plan instanceof Plan) {
                $errors[] = "Plan code={$target['plan_code']} が DB に存在しません (PlanSeeder 未実行の可能性)";

                continue;
            }

            $price = $plan->prices()
                ->where('kind', $target['kind']->value)
                ->where('is_current', true)
                ->where('lookup_key', $lookupKey)
                ->first();
            if (! $price instanceof PlanPrice) {
                $errors[] = "plan_prices に current {$target['kind']->value} 行がありません (code={$target['plan_code']}, lookup_key={$lookupKey})";

                continue;
            }

            $fixture = $fixtures[$lookupKey] ?? null;
            if ($fixture === null) {
                $errors[] = "fixture に lookup_key {$lookupKey} が存在しません";

                continue;
            }
            $stripe = $stripeEntries[$lookupKey] ?? null;
            if ($stripe === null) {
                $errors[] = "Stripe Catalog に lookup_key {$lookupKey} の active Price が存在しません";

                continue;
            }

            // (a) fixture spec ⇔ Stripe spec (unitAmount / currency / interval / intervalCount)
            if (! $fixture->matchesSpec($stripe)) {
                $errors[] = "{$lookupKey}: fixture と Stripe Catalog の spec が不一致";
            }
            // (b) 通貨が期待値 (CASHIER_CURRENCY) と一致
            if ($stripe->currency !== $expectedCurrency) {
                $errors[] = "{$lookupKey}: 通貨 ({$stripe->currency}) が CASHIER_CURRENCY ({$expectedCurrency}) と不一致";
            }
            // (c) plan_prices.stripe_price_id ⇔ Stripe Price ID
            if ($price->stripe_price_id !== $stripe->stripePriceId) {
                $errors[] = "{$lookupKey}: plan_prices.stripe_price_id ({$price->stripe_price_id}) と Stripe Price ID ({$stripe->stripePriceId}) が不一致 (billing:sync-stripe-prices 未実行の可能性)";
            }
            // (d) plan_prices.amount ⇔ Stripe unit_amount (請求額の drift 検知)
            if ($price->amount !== $stripe->unitAmount) {
                $errors[] = "{$lookupKey}: plan_prices.amount ({$price->amount}) と Stripe unit_amount ({$stripe->unitAmount}) が不一致";
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('Stripe price catalog verified.');

        return self::SUCCESS;
    }
}
