<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\StripePriceCatalogEntry;
use Stripe\Price as StripePrice;
use Stripe\Service\PriceService;
use Webmozart\Assert\Assert;
use Webmozart\Assert\InvalidArgumentException;

/**
 * Stripe Price Catalog への薄い read-only adapter。
 *
 * `Cashier::stripe()` の static 呼び出しをコマンド本体から排除し、Stripe API 境界を
 * 1 クラスに閉じ込める。DI するのは `StripeClient` 全体ではなく
 * `\Stripe\Service\PriceService` (`AppServiceProvider` で `Cashier::stripe()->prices`
 * に束縛) — `PriceService::all()` は実メソッドのためテストで素直にモックできる。
 *
 * sync / verify コマンドのテストで本クラスを `$this->mock()` できるよう `final` にしない。
 */
class StripePriceCatalogClient
{
    public function __construct(private readonly PriceService $prices) {}

    /**
     * 指定 lookup_key 群の現行 active Price を Stripe Catalog から取得する。
     *
     * Search API は eventual consistency のため使わず、`prices.list` の
     * `lookup_keys` パラメータで取得する。
     *
     * @param  list<string>  $lookupKeys
     * @return array<string, StripePriceCatalogEntry> lookup_key => entry
     *
     * @throws InvalidArgumentException 同一 lookup_key に active Price が複数存在
     */
    public function fetchByLookupKeys(array $lookupKeys): array
    {
        if ($lookupKeys === []) {
            return [];
        }

        $prices = $this->prices->all([
            'lookup_keys' => $lookupKeys,
            'active' => true,
            'limit' => 100,
        ]);

        $byLookupKey = [];
        foreach ($prices->data as $price) {
            Assert::isInstanceOf($price, StripePrice::class);
            $entry = StripePriceCatalogEntry::fromStripePrice($price);
            Assert::keyNotExists(
                $byLookupKey,
                $entry->lookupKey,
                "lookup_key {$entry->lookupKey} に active Price が複数存在します",
            );
            $byLookupKey[$entry->lookupKey] = $entry;
        }

        return $byLookupKey;
    }
}
