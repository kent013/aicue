<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Services\Billing\StripePriceCatalogClient;
use App\Support\Billing\StripePriceLookupKeys;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * Stripe Catalog の現行 active Price を lookup_key 経由で取得し、
 * `plan_prices` (current 行) の `stripe_price_id` / `amount` / `synced_at` を
 * 同期する片方向 sync コマンド。
 *
 * lookup_key の宣言は {@see StripePriceLookupKeys} (単一出典)。
 * `plan_prices.stripe_price_id` を書き込むのは bootstrap seeder と本コマンドのみ。
 * 冪等: 同値なら skip するため再実行は no-op。
 */
class SyncStripePrices extends Command
{
    /** @var string */
    protected $signature = 'billing:sync-stripe-prices';

    /** @var string */
    protected $description = 'Stripe Catalog の現行 Price を lookup_key 経由で取得し plan_prices を同期する';

    public function handle(StripePriceCatalogClient $client): int
    {
        $lookupKeyMap = StripePriceLookupKeys::map();

        try {
            $entries = $client->fetchByLookupKeys(array_keys($lookupKeyMap));
        } catch (Throwable $e) {
            Log::error('billing:sync-stripe-prices fetch failed', ['error' => $e->getMessage()]);
            $this->error('Stripe Catalog の取得に失敗しました: '.$e->getMessage());

            return self::FAILURE;
        }

        $expectedCurrency = config('cashier.currency');
        Assert::stringNotEmpty($expectedCurrency);

        // fail-fast: 期待する lookup_key が全て解決でき、通貨が一致すること。
        foreach ($lookupKeyMap as $lookupKey => $target) {
            if (! isset($entries[$lookupKey])) {
                $this->error("lookup_key {$lookupKey} が Stripe Catalog に未登録です (先に stripe fixtures を apply してください)");

                return self::FAILURE;
            }
            if ($entries[$lookupKey]->currency !== $expectedCurrency) {
                $this->error("lookup_key {$lookupKey} の通貨 ({$entries[$lookupKey]->currency}) が CASHIER_CURRENCY ({$expectedCurrency}) と不一致です");

                return self::FAILURE;
            }
        }

        try {
            DB::transaction(function () use ($lookupKeyMap, $entries): void {
                $now = CarbonImmutable::now();
                foreach ($lookupKeyMap as $lookupKey => $target) {
                    $plan = Plan::query()->where('code', $target['plan_code'])->first();
                    if (! $plan instanceof Plan) {
                        throw new RuntimeException(
                            "Plan code={$target['plan_code']} が DB に存在しません (PlanSeeder 未実行の可能性)",
                        );
                    }

                    // lookup_key も一致条件に含め、取り違えた current 行を更新しない (fail-fast)。
                    $price = $plan->prices()
                        ->where('kind', $target['kind']->value)
                        ->where('is_current', true)
                        ->where('lookup_key', $lookupKey)
                        ->first();
                    if (! $price instanceof PlanPrice) {
                        throw new RuntimeException(
                            "plan_prices に current {$target['kind']->value} 行がありません (code={$target['plan_code']}, lookup_key={$lookupKey})",
                        );
                    }

                    $entry = $entries[$lookupKey];
                    $stripePriceId = $entry->stripePriceId;
                    Assert::stringNotEmpty($stripePriceId);

                    // 冪等: stripe_price_id / amount が同値なら synced_at も更新せず skip。
                    if ($price->stripe_price_id === $stripePriceId && $price->amount === $entry->unitAmount && $price->synced_at !== null) {
                        continue;
                    }

                    $price->stripe_price_id = $stripePriceId;
                    $price->amount = $entry->unitAmount;
                    $price->synced_at = $now;
                    $price->save();
                }
            });
        } catch (Throwable $e) {
            Log::error('billing:sync-stripe-prices update failed', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($lookupKeyMap as $lookupKey => $target) {
            $this->info("{$target['plan_code']}/{$target['kind']->value}: {$entries[$lookupKey]->stripePriceId} ({$lookupKey})");
        }
        $this->info('Stripe Prices synced.');

        return self::SUCCESS;
    }
}
