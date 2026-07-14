<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\SubscriptionCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use App\Support\FakeStorageGate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 外部サービス fake の配線 (系統別に capability flag を分離)。
 *
 * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
 * fail-secure 二軸:
 * 1. flag === true (既定 false = 完全 no-op)
 * 2. 環境 allowlist。denylist (非 production) ではなく allowlist で倒す = staging 等の
 *    未知環境で flag が誤設定されても fake しない (warning ログで検出可能にする)。
 *    production は加えて ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する。
 *
 * fake 対象は 2 系統で capability flag も allowlist も異なる:
 * - Stripe 課金 gateway: config('testing.fake_externals') が capability flag。
 *   container bind (per-test 隔離が効くため testing 可)。register() で配線。
 * - LLM (Prism): config('testing.fake_llm') が capability flag (fake_externals から分離)。
 *   Prompt::$fake は static (プロセスグローバル) のため testing/local を除外し bughunt.local のみ配線。
 *   bughunt 既定は real-llm (fake_llm off) で install しない。--fake-llm 時のみ install する。
 *   LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
 */
class FakeExternalsServiceProvider extends ServiceProvider
{
    /** Stripe 課金 gateway fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可) */
    private const array PAYMENT_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** LLM (Prism) fake の install を許可する環境 allowlist (Prompt::$fake は static。testing/local を除外) */
    private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];

    public function register(): void
    {
        // capability ごとに独立 private method へ分離する (early return が他 capability を巻き込まない)。
        $this->registerPaymentFakes(); // Stripe: fake_externals 依存 (挙動不変)
        $this->registerStorageFakes(); // storage: fake_storage (FakeStorageGate) 依存 — 独立
    }

    public function boot(): void
    {
        $this->bootLlmFake();       // LLM: fake_llm 依存 (挙動不変)
        $this->bootStorageRoutes(); // storage signed route — 独立
    }

    /** Stripe 課金 gateway fake (fake_externals + PAYMENT_FAKE_ENVIRONMENTS。挙動不変) */
    private function registerPaymentFakes(): void
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

    /** LLM (Prism) fake (fake_llm + LLM_FAKE_ENVIRONMENTS。挙動不変) */
    private function bootLlmFake(): void
    {
        // LLM fake は fake_llm (既定 false = real LLM) で判定する。bughunt 既定は real-llm で、
        // --fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入され install される。
        // Stripe fake (register) は従来どおり fake_externals 依存で不変。
        if (config('testing.fake_llm') !== true) {
            return;
        }

        // LLM fake は Prompt::$fake (プロセスグローバル static) を書き換えるため、
        // per-test で static を占有する testing、実 API 検証を潰す local は allowlist から除外する。
        // LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
        // (Stripe と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
        if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
            return;
        }

        // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
        $this->app->make(CannedPromptFakeRegistrar::class)->install();
    }

    /**
     * storage fake: FakeStorageGate 成立時のみ concrete → fake へ rebind (gate = predicate SSOT)。
     * env allowlist / production 拒否は gate に一元化される。
     */
    private function registerStorageFakes(): void
    {
        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
            return;
        }

        $this->app->bind(TakeObjectStorage::class, FakeTakeObjectStorage::class);
        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);
    }

    /** storage fake の signed route (gate 成立時のみ。web CSRF group 外 = signed のみ) */
    private function bootStorageRoutes(): void
    {
        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
            return;
        }

        // 冪等化: boot() が複数回走っても (route:cache 併用・テストの provider 再実走等)
        // 同名 route を二重登録しない。通常の bootstrap では未登録 = そのまま登録される。
        if (Route::has('bughunt.storage.put')) {
            return;
        }

        Route::middleware('signed')->group(function (): void {
            Route::put('/_fake-storage/object', PutFakeStorageObjectController::class)
                ->name('bughunt.storage.put');
            Route::get('/_fake-storage/object', GetFakeStorageObjectController::class)
                ->name('bughunt.storage.get');
        });
    }
}
