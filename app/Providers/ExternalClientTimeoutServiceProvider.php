<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\ExternalClientTimeouts;
use Illuminate\Support\ServiceProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;

/**
 * 外部 SDK のプロセス大域設定を pin する専用 provider。
 *
 * ★**なぜ AppServiceProvider に混ぜないか**: この pin は PHP プロセス大域の static 状態を
 *   書き換えるため、「配線が実際に効いているか」をテストが独立に検証するには
 *   provider の boot() を単独で再実行できる必要がある。AppServiceProvider に混ぜると
 *   再実行で Event::listen 等が二重登録される。
 * ★Stripe SDK は **client ごとの timeout を支えない**。`StripeClient` の config に
 *   timeout 系のキーが無く (`BaseStripeClient::DEFAULT_CONFIG`)、`ApiRequestor` の
 *   static HTTP client だけが唯一の調整点である。したがってテナント別 timeout は持たない。
 * ★`Cashier::stripe()` / `$organization->stripe()` / `PriceService` bind の 3 系統は
 *   すべてこの HTTP client を通るため、大域 pin 1 本で全経路を覆える。
 *
 * 運用契約: docs/architecture.md §外部 SDK の待ち上限の規約
 */
final class ExternalClientTimeoutServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ★CurlClient::instance() のシングルトンを直接設定せず、専用インスタンスを
        //   ApiRequestor へ差す。シングルトンを書き換えると「誰が設定したか」が
        //   追えなくなるうえ、テストの復元先が曖昧になる。
        $client = new CurlClient;
        $client->setConnectTimeout(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS);
        $client->setTimeout(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS);

        ApiRequestor::setHttpClient($client);
        Stripe::setMaxNetworkRetries(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES);
    }
}
