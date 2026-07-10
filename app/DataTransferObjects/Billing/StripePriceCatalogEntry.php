<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use Stripe\Price as StripePrice;
use Stripe\StripeObject;
use Webmozart\Assert\Assert;

/**
 * Stripe Price Catalog の 1 エントリを表す値オブジェクト。
 *
 * Stripe SDK の `Price` オブジェクトと `stripe/fixtures/*.json` の price 宣言を
 * 共通の比較可能な形に正規化する。`billing:sync-stripe-prices` /
 * `billing:verify-stripe-prices` が生配列を直接扱わず本 VO 経由で比較するため、
 * PHPStan level 10 の `mixed` 漏れをコマンド本体から排除できる。
 *
 * テンプレートのプラン価格は「定額**月次**サブスク」が固定の invariant のため、
 * 両ファクトリで `interval === 'month'` / `intervalCount === 1` を fail-fast する。
 * `currency` は config 駆動 (`CASHIER_CURRENCY`) のため VO では固定検証せず、
 * データとして保持し検証は sync / verify コマンド側で行う。
 */
final readonly class StripePriceCatalogEntry
{
    public function __construct(
        public string $lookupKey,
        public int $unitAmount,
        public string $currency,
        public string $interval,
        public int $intervalCount,
        // fixture 由来は null (ID は Stripe が採番)、Stripe 由来は非 null。
        public ?string $stripePriceId,
    ) {}

    /**
     * Stripe SDK の Price オブジェクトから生成する。
     *
     * `StripeObject` のプロパティは `__get` 経由で `mixed` のため、
     * 「型 assertion → 値 assertion」の 2 段階で narrow する。
     */
    public static function fromStripePrice(StripePrice $price): self
    {
        Assert::stringNotEmpty($price->id);
        Assert::stringNotEmpty($price->lookup_key, "Stripe Price {$price->id} の lookup_key が null");
        Assert::integer($price->unit_amount, "Stripe Price {$price->id} の unit_amount が null");
        Assert::stringNotEmpty($price->currency);

        $recurring = $price->recurring;
        Assert::isInstanceOf($recurring, StripeObject::class, "Stripe Price {$price->id} は recurring でない");

        $interval = $recurring->interval;
        Assert::string($interval);
        Assert::same($interval, 'month', "Stripe Price {$price->id} の interval が month でない");

        $intervalCount = $recurring->interval_count;
        Assert::integer($intervalCount);
        Assert::same($intervalCount, 1, "Stripe Price {$price->id} の interval_count が 1 でない");

        return new self(
            lookupKey: $price->lookup_key,
            unitAmount: $price->unit_amount,
            currency: $price->currency,
            interval: $interval,
            intervalCount: $intervalCount,
            stripePriceId: $price->id,
        );
    }

    /**
     * `stripe/fixtures/*.json` の `/v1/prices` エントリの `params` 配列から生成する。
     *
     * JSON decode 由来のため key 型は `array-key`。値は全て `Assert::*` で narrow する。
     *
     * @param  array<array-key, mixed>  $params
     */
    public static function fromFixturePriceParams(array $params): self
    {
        Assert::keyExists($params, 'lookup_key');
        $lookupKey = $params['lookup_key'];
        Assert::stringNotEmpty($lookupKey);

        Assert::keyExists($params, 'unit_amount');
        $unitAmount = $params['unit_amount'];
        Assert::integer($unitAmount);

        Assert::keyExists($params, 'currency');
        $currency = $params['currency'];
        Assert::stringNotEmpty($currency);

        Assert::keyExists($params, 'recurring');
        $recurring = $params['recurring'];
        Assert::isArray($recurring);

        Assert::keyExists($recurring, 'interval');
        $interval = $recurring['interval'];
        Assert::string($interval);
        Assert::same($interval, 'month', "fixture {$lookupKey} の interval が month でない");

        Assert::keyExists($recurring, 'interval_count');
        $intervalCount = $recurring['interval_count'];
        Assert::integer($intervalCount);
        Assert::same($intervalCount, 1, "fixture {$lookupKey} の interval_count が 1 でない");

        return new self(
            lookupKey: $lookupKey,
            unitAmount: $unitAmount,
            currency: $currency,
            interval: $interval,
            intervalCount: $intervalCount,
            stripePriceId: null,
        );
    }

    /**
     * spec (lookupKey / unitAmount / currency / interval / intervalCount) が一致するか。
     * `stripePriceId` は fixture 側が null のため比較対象に含めない。
     */
    public function matchesSpec(self $other): bool
    {
        return $this->lookupKey === $other->lookupKey
            && $this->unitAmount === $other->unitAmount
            && $this->currency === $other->currency
            && $this->interval === $other->interval
            && $this->intervalCount === $other->intervalCount;
    }
}
