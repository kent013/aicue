<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\DataTransferObjects\Billing\StripePriceCatalogEntry;
use Webmozart\Assert\Assert;

/**
 * `stripe/fixtures/*.json` を読み込み、lookup_key → {@see StripePriceCatalogEntry} の
 * map を返す support クラス。
 *
 * `billing:verify-stripe-prices` と fixture ⇔ lookup_key 宣言の invariant テストの
 * 双方が利用するため、fixture ファイルの読み込み・JSON decode・price エントリ抽出を
 * 1 箇所に集約する。
 */
final class StripePriceFixtures
{
    /**
     * `stripe/fixtures/*.json` の全 `/v1/prices` エントリを VO 化して返す。
     *
     * @return array<string, StripePriceCatalogEntry> lookup_key => entry
     */
    public static function load(): array
    {
        $entries = [];

        foreach (glob(base_path('stripe/fixtures/*.json')) ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            Assert::isArray($decoded, "fixture {$path} の JSON decode に失敗");
            Assert::keyExists($decoded, 'fixtures', "fixture {$path} に fixtures キーがない");
            $fixtures = $decoded['fixtures'];
            Assert::isArray($fixtures);

            foreach ($fixtures as $fixture) {
                Assert::isArray($fixture);
                if (($fixture['path'] ?? null) !== '/v1/prices') {
                    continue;
                }
                Assert::keyExists($fixture, 'params', "fixture {$path} の price エントリに params がない");
                $params = $fixture['params'];
                Assert::isArray($params);

                $entry = StripePriceCatalogEntry::fromFixturePriceParams($params);
                Assert::keyNotExists(
                    $entries,
                    $entry->lookupKey,
                    "fixture に lookup_key {$entry->lookupKey} が重複しています",
                );
                $entries[$entry->lookupKey] = $entry;
            }
        }

        return $entries;
    }
}
