<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AI\Testing\CannedPromptFakeRegistrar;
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
 * 2. 環境 allowlist。denylist (非 production) ではなく allowlist で倒す = staging 等の
 *    未知環境で flag が誤設定されても fake しない (warning ログで検出可能にする)。
 *    production は加えて ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する。
 *
 * fake 対象は 2 系統で allowlist が異なる:
 * - Stripe 課金 gateway: container bind (per-test 隔離が効くため testing 可)。register() で配線。
 * - LLM (Prism): Prompt::$fake は static (プロセスグローバル) のため testing/local を除外。
 *   boot() で bughunt.local のみ配線 (HTTP serve / queue worker / artisan 全 bootstrap で発火)。
 */
class FakeExternalsServiceProvider extends ServiceProvider
{
    /** Stripe 課金 gateway fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可) */
    private const array PAYMENT_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** LLM (Prism) fake の install を許可する環境 allowlist (Prompt::$fake は static。testing/local を除外) */
    private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];

    public function register(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        $environment = $this->app->environment();
        if (! in_array($environment, self::PAYMENT_FAKE_ENVIRONMENTS, true)) {
            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
                'environment' => $environment,
            ]);

            return;
        }

        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
        $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
    }

    public function boot(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        // LLM fake は Prompt::$fake (プロセスグローバル static) を書き換えるため、
        // per-test で static を占有する testing、実 API 検証を潰す local は除外する。
        // (Stripe と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
        if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
            return;
        }

        // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
        $this->app->make(CannedPromptFakeRegistrar::class)->install();
    }
}
