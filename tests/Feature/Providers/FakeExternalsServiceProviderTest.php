<?php

declare(strict_types=1);

use App\Providers\FakeExternalsServiceProvider;
use App\Services\Billing\CashierSubscriptionCheckoutGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\SubscriptionCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use Illuminate\Support\Facades\Log;

/*
 * FakeExternalsServiceProvider: config('testing.fake_externals') が capability flag。
 * fail-secure 二軸 (flag 既定 false = 完全 no-op / 環境 allowlist) を固定する。
 * Pest はテスト毎に app を再構築するため register() 再実行の container 汚染は漏れない。
 */

test('既定 (flag=false) では両 gateway とも Cashier 実装に解決される', function (): void {
    expect(config('testing.fake_externals'))->toBeFalse();
    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
    expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(CashierSubscriptionCheckoutGateway::class);
});

test('flag=true かつ allowlist 環境 (testing) では両 gateway が fake に解決される', function (): void {
    config(['testing.fake_externals' => true]);
    (new FakeExternalsServiceProvider($this->app))->register();

    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(FakeTicketCheckoutGateway::class);
    expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(FakeSubscriptionCheckoutGateway::class);
});

test('flag=true でも allowlist 外の環境 (production) では fake に bind せず warning を出す', function (): void {
    config(['testing.fake_externals' => true]);
    Log::spy();

    $originalEnv = $this->app['env'];
    try {
        $this->app['env'] = 'production';
        (new FakeExternalsServiceProvider($this->app))->register();
    } finally {
        $this->app['env'] = $originalEnv;
    }

    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
    expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(CashierSubscriptionCheckoutGateway::class);
    Log::shouldHaveReceived('warning')->once();
});
