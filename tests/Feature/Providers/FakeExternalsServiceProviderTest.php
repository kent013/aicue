<?php

declare(strict_types=1);

use App\Prompts\ExampleSummaryPrompt;
use App\Providers\FakeExternalsServiceProvider;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Prompt;

/*
 * FakeExternalsServiceProvider: config('testing.fake_externals') が capability flag。
 * fail-secure 二軸 (flag 既定 false = 完全 no-op / 環境 allowlist) を固定する。
 * Pest はテスト毎に app を再構築するため register() 再実行の container 汚染は漏れない。
 *
 * boot() は LLM (Prism) fake を配線する。Prompt::$fake は static (プロセスグローバル) のため
 * allowlist は bughunt.local のみ (testing/local は除外)。static リークを避けるため
 * afterEach で必ず stopFaking する (テスト本体が例外で落ちても到達させる)。
 */

afterEach(function (): void {
    // boot() が install した Prompt::$fake (static) を各テスト境界でリークさせない。
    Prompt::stopFaking();
});

test('既定 (flag=false) では両 gateway とも Cashier 実装に解決される', function (): void {
    expect(config('testing.fake_externals'))->toBeFalse();
    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
    expect(app(StripeGatewayInterface::class))->toBeInstanceOf(CashierStripeGateway::class);
});

test('flag=true かつ allowlist 環境 (testing) では両 gateway が fake に解決される', function (): void {
    config(['testing.fake_externals' => true]);
    (new FakeExternalsServiceProvider($this->app))->register();

    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(FakeTicketCheckoutGateway::class);
    expect(app(StripeGatewayInterface::class))->toBeInstanceOf(FakeStripeGateway::class);
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
    expect(app(StripeGatewayInterface::class))->toBeInstanceOf(CashierStripeGateway::class);
    Log::shouldHaveReceived('warning')->once();
});

/*
 * boot(): LLM (Prism) fake は config('testing.fake_llm') が capability flag (fake_externals から分離)。
 * 環境 allowlist は bughunt.local のみ。bughunt 既定は real-llm (fake_llm off) で install しない。
 * 各テストは env と config を try/finally で原値復元する (static/config 汚染を漏らさない)。
 */

test('boot: env=bughunt.local ∧ fake_llm=true で Prompt fake が有効になり canned を返す', function (): void {
    // 万一の FX 解決 HTTP を stray にしない防御。
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);

    $originalEnv = $this->app['env'];
    $originalFlag = config('testing.fake_llm');
    try {
        config(['testing.fake_llm' => true]);
        $this->app['env'] = 'bughunt.local';
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeTrue();

        // 代表 prompt が canned を返す (stray call 0 = 実 API 未到達)。
        $summary = ExampleSummaryPrompt::make('本文')->executeSync();
        expect($summary)->toBeString();
        expect(trim((string) $summary))->not->toBe('');
    } finally {
        Prompt::stopFaking();
        config(['testing.fake_llm' => $originalFlag]);
        $this->app['env'] = $originalEnv;
    }
});

test('boot: env=testing ∧ fake_llm=true では Prompt::$fake に触れない (static 占有を避ける)', function (): void {
    $originalFlag = config('testing.fake_llm');
    try {
        // env は既定の testing のまま。
        config(['testing.fake_llm' => true]);
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        config(['testing.fake_llm' => $originalFlag]);
    }
});

test('boot: env=local ∧ fake_llm=true では Prompt::$fake に触れない (実 API 検証を潰さない)', function (): void {
    $originalEnv = $this->app['env'];
    $originalFlag = config('testing.fake_llm');
    try {
        config(['testing.fake_llm' => true]);
        $this->app['env'] = 'local';
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        config(['testing.fake_llm' => $originalFlag]);
        $this->app['env'] = $originalEnv;
    }
});

test('boot: fake_llm=false では bughunt.local でも Prompt fake を配線しない (real 経路)', function (): void {
    $originalEnv = $this->app['env'];
    $originalFlag = config('testing.fake_llm');
    try {
        config(['testing.fake_llm' => false]);
        $this->app['env'] = 'bughunt.local';
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        config(['testing.fake_llm' => $originalFlag]);
        $this->app['env'] = $originalEnv;
    }
});

test('boot: fake_externals=true でも fake_llm=false なら install しない (系統分離の回帰)', function (): void {
    // Stripe fake が立っていても LLM は real (fake_externals と fake_llm の分離を固定)。
    $originalEnv = $this->app['env'];
    $originalExternals = config('testing.fake_externals');
    $originalLlm = config('testing.fake_llm');
    try {
        config(['testing.fake_externals' => true]);
        config(['testing.fake_llm' => false]);
        $this->app['env'] = 'bughunt.local';
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        config(['testing.fake_externals' => $originalExternals]);
        config(['testing.fake_llm' => $originalLlm]);
        $this->app['env'] = $originalEnv;
    }
});
