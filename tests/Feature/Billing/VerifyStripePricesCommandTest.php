<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\StripePriceCatalogEntry;
use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Services\Billing\StripePriceCatalogClient;
use App\Support\Billing\StripePriceLookupKeys;

/*
 * billing:verify-stripe-prices (fixture / Stripe Catalog / plan_prices の整合検証)。
 * Stripe API は呼ばない: StripePriceCatalogClient をモックして検証する。
 * fixture 側の期待値は stripe/fixtures/plan_{starter,standard}.json (unit_amount=980 / 4980) に一致させる。
 */

function verifyStandardBaseLookupKey(): string
{
    return StripePriceLookupKeys::key('standard', PlanPriceKind::Base);
}

function verifyStarterBaseLookupKey(): string
{
    return StripePriceLookupKeys::key('starter', PlanPriceKind::Base);
}

function verifyEntry(string $lookupKey, string $stripePriceId, int $unitAmount, string $currency = 'jpy'): StripePriceCatalogEntry
{
    return new StripePriceCatalogEntry(
        lookupKey: $lookupKey,
        unitAmount: $unitAmount,
        currency: $currency,
        interval: 'month',
        intervalCount: 1,
        stripePriceId: $stripePriceId,
    );
}

/** plan_prices (current) を Stripe live id と fixture の金額に揃える (宣言済み全 lookup_key) */
function alignPlanPricesToStripe(): void
{
    PlanPrice::query()
        ->where('lookup_key', verifyStarterBaseLookupKey())
        ->where('is_current', true)
        ->update(['stripe_price_id' => 'price_live_starter_base', 'amount' => 980]);

    PlanPrice::query()
        ->where('lookup_key', verifyStandardBaseLookupKey())
        ->where('is_current', true)
        ->update(['stripe_price_id' => 'price_live_standard_base', 'amount' => 4980]);
}

/**
 * 宣言済み全 lookup_key が fixture (starter 980 / standard 4980) と一致する map。
 *
 * @return array<string, StripePriceCatalogEntry>
 */
function happyStripeEntries(): array
{
    $starterKey = verifyStarterBaseLookupKey();
    $standardKey = verifyStandardBaseLookupKey();

    return [
        $starterKey => verifyEntry($starterKey, 'price_live_starter_base', 980),
        $standardKey => verifyEntry($standardKey, 'price_live_standard_base', 4980),
    ];
}

beforeEach(function (): void {
    config(['cashier.currency' => 'jpy']);
});

test('fixture / Stripe / plan_prices が全て一致すれば成功する', function (): void {
    alignPlanPricesToStripe();
    $entries = happyStripeEntries();
    $this->mock(StripePriceCatalogClient::class, function ($mock) use ($entries): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn($entries);
    });

    $this->artisan('billing:verify-stripe-prices')->assertExitCode(0);
});

test('plan_prices.stripe_price_id が Stripe Price ID から drift していたら失敗する', function (): void {
    alignPlanPricesToStripe();
    PlanPrice::query()->where('lookup_key', verifyStandardBaseLookupKey())->where('is_current', true)
        ->update(['stripe_price_id' => 'price_stale']);
    $entries = happyStripeEntries();
    $this->mock(StripePriceCatalogClient::class, function ($mock) use ($entries): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn($entries);
    });

    $this->artisan('billing:verify-stripe-prices')->assertExitCode(1);
});

test('plan_prices.amount が Stripe unit_amount から drift していたら失敗する', function (): void {
    alignPlanPricesToStripe();
    PlanPrice::query()->where('lookup_key', verifyStandardBaseLookupKey())->where('is_current', true)
        ->update(['amount' => 9999]);
    $entries = happyStripeEntries();
    $this->mock(StripePriceCatalogClient::class, function ($mock) use ($entries): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn($entries);
    });

    $this->artisan('billing:verify-stripe-prices')->assertExitCode(1);
});

test('Stripe の通貨が CASHIER_CURRENCY と不一致なら失敗する', function (): void {
    config(['cashier.currency' => 'usd']);
    alignPlanPricesToStripe();
    $entries = happyStripeEntries();
    $this->mock(StripePriceCatalogClient::class, function ($mock) use ($entries): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn($entries);
    });

    $this->artisan('billing:verify-stripe-prices')->assertExitCode(1);
});

test('lookup_key が Stripe Catalog に無ければ失敗する', function (): void {
    alignPlanPricesToStripe();
    $this->mock(StripePriceCatalogClient::class, function ($mock): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn([]);
    });

    $this->artisan('billing:verify-stripe-prices')->assertExitCode(1);
});

test('fixture spec が Stripe Catalog と不一致なら失敗する', function (): void {
    alignPlanPricesToStripe();
    $lookupKey = verifyStandardBaseLookupKey();
    // fixture は 4980 だが Stripe が 30000 = matchesSpec 不一致。
    // plan_prices.amount も合わせて (d) でなく (a) を単独発火させる
    $entries = happyStripeEntries();
    $entries[$lookupKey] = verifyEntry($lookupKey, 'price_live_standard_base', 30000);
    PlanPrice::query()->where('lookup_key', $lookupKey)->where('is_current', true)->update(['amount' => 30000]);
    $this->mock(StripePriceCatalogClient::class, function ($mock) use ($entries): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn($entries);
    });

    $this->artisan('billing:verify-stripe-prices')->assertExitCode(1);
});

test('Stripe Catalog の取得が throw したら失敗する (verify)', function (): void {
    alignPlanPricesToStripe();
    $this->mock(StripePriceCatalogClient::class, function ($mock): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andThrow(new RuntimeException('stripe down'));
    });

    $this->artisan('billing:verify-stripe-prices')->assertExitCode(1);
});

test('対象 Plan 行が無ければ失敗する (verify)', function (): void {
    alignPlanPricesToStripe();
    Plan::query()->where('code', 'standard')->delete();
    $entries = happyStripeEntries();
    $this->mock(StripePriceCatalogClient::class, function ($mock) use ($entries): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn($entries);
    });

    $this->artisan('billing:verify-stripe-prices')->assertExitCode(1);
});
