<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\FxSnapshotDto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * USD→JPY 為替レートの解決。
 *
 * 解決順:
 *   1. 当日 cache (key: fx_rate_usd_jpy_YYYY-MM-DD)
 *   2. Frankfurter API (短い connect/read timeout)
 *   3. null (graceful degradation。呼び出し側は total_cost_jpy = null で記録する)
 *
 * 絶対に throw しない — FX の取得失敗で LLM 呼び出し本体を巻き込まない。
 */
final readonly class FxRateService
{
    public function resolve(): ?FxSnapshotDto
    {
        $cacheKey = 'fx_rate_usd_jpy_'.CarbonImmutable::now()->toDateString();

        try {
            /** @var mixed $cached */
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return FxSnapshotDto::fromArray($cached);
            }
        } catch (Throwable $e) {
            Log::warning('FxRate cache deserialization failed', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
            Cache::forget($cacheKey);
        }

        $fresh = $this->fetchFromFrankfurter();

        if ($fresh !== null) {
            Cache::put($cacheKey, $fresh->toArray(), CarbonImmutable::now()->endOfDay());
        }

        return $fresh;
    }

    private function fetchFromFrankfurter(): ?FxSnapshotDto
    {
        try {
            $url = config('llm-pricing.frankfurter_url', 'https://api.frankfurter.dev/v1/latest');
            Assert::string($url);

            $timeout = config('llm-pricing.frankfurter_timeout', 2);
            Assert::integer($timeout);

            $connectTimeout = config('llm-pricing.frankfurter_connect_timeout', 1);
            Assert::integer($connectTimeout);

            /** @var mixed $response */
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->get($url, ['base' => 'USD', 'symbols' => 'JPY'])
                ->throw()
                ->json();

            if (! is_array($response)
                || ! isset($response['rates'])
                || ! is_array($response['rates'])
                || ! isset($response['rates']['JPY'])
                || ! is_numeric($response['rates']['JPY'])
            ) {
                Log::warning('Frankfurter response malformed', ['response' => $response]);

                return null;
            }

            $rate = (float) $response['rates']['JPY'];
            if ($rate <= 0) {
                Log::warning('Frankfurter returned non-positive rate', ['rate' => $rate]);

                return null;
            }

            return new FxSnapshotDto(
                rate: $rate,
                pair: 'USDJPY',
                source: 'frankfurter',
                fetchedAt: CarbonImmutable::now(),
            );
        } catch (Throwable $e) {
            Log::warning('Frankfurter fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
