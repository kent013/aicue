<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\StripePriceCatalogEntry;
use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use App\Services\Billing\StripePriceCatalogClient;
use App\Support\Billing\StripePriceLookupKeys;

/*
 * billing:sync-stripe-prices (Stripe Catalog → plan_prices の片方向同期)。
 * Stripe API は呼ばない: StripePriceCatalogClient をモックして検証する。
 */

function syncStandardBaseLookupKey(): string
{
    return StripePriceLookupKeys::key('standard', PlanPriceKind::Base);
}

function syncStarterBaseLookupKey(): string
{
    return StripePriceLookupKeys::key('starter', PlanPriceKind::Base);
}

/**
 * @param  array<string, int|string>  $overrides
 */
function syncEntry(string $lookupKey, string $stripePriceId, array $overrides = []): StripePriceCatalogEntry
{
    $unitAmount = $overrides['unitAmount'] ?? 4980;
    assert(is_int($unitAmount));
    $currency = $overrides['currency'] ?? 'jpy';
    assert(is_string($currency));

    return new StripePriceCatalogEntry(
        lookupKey: $lookupKey,
        unitAmount: $unitAmount,
        currency: $currency,
        interval: 'month',
        intervalCount: 1,
        stripePriceId: $stripePriceId,
    );
}

/**
 * 宣言済み全 lookup_key の live エントリ map。
 * コマンドは宣言済み lookup_key が 1 つでも欠けると fail-fast するため、
 * 全宣言 (starter / standard) を揃える。
 *
 * @return array<string, StripePriceCatalogEntry>
 */
function allSyncEntries(): array
{
    $starterKey = syncStarterBaseLookupKey();
    $standardKey = syncStandardBaseLookupKey();

    return [
        $starterKey => syncEntry($starterKey, 'price_live_starter_base', ['unitAmount' => 980]),
        $standardKey => syncEntry($standardKey, 'price_live_standard_base'),
    ];
}

beforeEach(function (): void {
    config(['cashier.currency' => 'jpy']);
});

test('Stripe Catalog の現行 Price が plan_prices (current base) に同期される', function (): void {
    $this->mock(StripePriceCatalogClient::class, function ($mock): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn(allSyncEntries());
    });

    $this->artisan('billing:sync-stripe-prices')->assertExitCode(0);

    $this->assertDatabaseHas('plan_prices', [
        'lookup_key' => syncStandardBaseLookupKey(),
        'stripe_price_id' => 'price_live_standard_base',
        'kind' => 'base',
    ]);

    // synced_at が設定される (bootstrap=null から live 同期済みへ)
    $price = Plan::query()->where('code', 'standard')->firstOrFail()->currentPrice(PlanPriceKind::Base);
    expect($price?->synced_at)->not->toBeNull();
});

test('lookup_key が Stripe Catalog に無ければ失敗し plan_prices は不変', function (): void {
    $this->mock(StripePriceCatalogClient::class, function ($mock): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn([]);
    });

    $this->artisan('billing:sync-stripe-prices')->assertExitCode(1);

    // plan_prices は bootstrap のまま不変
    $this->assertDatabaseHas('plan_prices', [
        'lookup_key' => syncStandardBaseLookupKey(),
        'stripe_price_id' => 'price_test_'.syncStandardBaseLookupKey(),
    ]);
});

test('通貨が CASHIER_CURRENCY と不一致なら失敗する', function (): void {
    $lookupKey = syncStandardBaseLookupKey();
    $entries = allSyncEntries();
    $entries[$lookupKey] = syncEntry($lookupKey, 'price_live_standard_base', ['currency' => 'usd']);

    $this->mock(StripePriceCatalogClient::class, function ($mock) use ($entries): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn($entries);
    });

    $this->artisan('billing:sync-stripe-prices')->assertExitCode(1);

    $this->assertDatabaseHas('plan_prices', [
        'lookup_key' => $lookupKey,
        'stripe_price_id' => 'price_test_'.$lookupKey,
    ]);
});

test('Stripe Catalog の取得が throw したら失敗する', function (): void {
    $this->mock(StripePriceCatalogClient::class, function ($mock): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andThrow(new RuntimeException('stripe down'));
    });

    $this->artisan('billing:sync-stripe-prices')->assertExitCode(1);
});

test('対象 Plan 行が無ければ失敗する', function (): void {
    Plan::query()->where('code', 'standard')->delete();

    $this->mock(StripePriceCatalogClient::class, function ($mock): void {
        $mock->shouldReceive('fetchByLookupKeys')->once()->andReturn(allSyncEntries());
    });

    $this->artisan('billing:sync-stripe-prices')->assertExitCode(1);
});

test('再実行は冪等 (no-op) で成功する', function (): void {
    $this->mock(StripePriceCatalogClient::class, function ($mock): void {
        $mock->shouldReceive('fetchByLookupKeys')->twice()->andReturn(allSyncEntries());
    });

    $this->artisan('billing:sync-stripe-prices')->assertExitCode(0);
    $this->artisan('billing:sync-stripe-prices')->assertExitCode(0);

    $this->assertDatabaseHas('plan_prices', [
        'lookup_key' => syncStandardBaseLookupKey(),
        'stripe_price_id' => 'price_live_standard_base',
    ]);
});
