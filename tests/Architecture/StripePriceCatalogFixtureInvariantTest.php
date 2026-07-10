<?php

declare(strict_types=1);

use App\Support\Billing\StripePriceFixtures;
use App\Support\Billing\StripePriceLookupKeys;

/*
 * Stripe Price Catalog as code の invariant。
 *
 * `stripe/fixtures/*.json` (desired state) と `StripePriceLookupKeys` (コード側宣言) の
 * lookup_key 集合一致を CI で固定し、slug 変更・プラン追加時の書き換え漏れ (drift) を
 * 検知する。金額・Stripe 実体との整合は `billing:verify-stripe-prices` が担当する。
 */

test('stripe fixtures の lookup_key が StripePriceLookupKeys の宣言と集合一致する', function (): void {
    $fixtures = StripePriceFixtures::load();

    expect(array_keys($fixtures))->toEqualCanonicalizing(array_keys(StripePriceLookupKeys::map()));
});

test('全 fixture エントリが有効な月次価格宣言である', function (): void {
    $fixtures = StripePriceFixtures::load();
    $expectedCurrency = config()->string('cashier.currency');

    expect($fixtures)->not->toBeEmpty();
    foreach ($fixtures as $lookupKey => $entry) {
        expect($entry->lookupKey)->toBe($lookupKey)
            ->and($entry->interval)->toBe('month')
            ->and($entry->intervalCount)->toBe(1)
            ->and($entry->currency)->toBe($expectedCurrency)
            ->and($entry->unitAmount)->toBeGreaterThan(0)
            ->and($entry->stripePriceId)->toBeNull();
    }
});
