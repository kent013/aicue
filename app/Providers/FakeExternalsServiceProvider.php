<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\SubscriptionCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * 外部サービス fake の配線 (config('testing.fake_externals') が capability flag)。
 *
 * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
 * fail-secure 二軸:
 * 1. flag === true (既定 false = 完全 no-op)
 * 2. 環境 allowlist (local / testing / bughunt.local)。denylist (非 production) ではなく
 *    allowlist で倒す = staging 等の未知環境で flag が誤設定されても fake しない
 *    (warning ログで検出可能にする)。production は加えて ProductionEnvGuard が
 *    flag=true を deploy 時 fail-fast で拒否する (二重防御)。
 */
class FakeExternalsServiceProvider extends ServiceProvider
{
    /** fake bind を許可する環境 allowlist */
    private const array ALLOWED_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    public function register(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        $environment = $this->app->environment();
        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
                'environment' => $environment,
            ]);

            return;
        }

        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
        $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
    }
}
