<?php

declare(strict_types=1);

use App\Providers\ExternalClientTimeoutServiceProvider;
use App\Support\ExternalClientTimeouts;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;

/*
 * Stripe SDK のプロセス大域 timeout pin (T126 施策 2)。
 *
 * ★**既知の初期状態から検証する**。ambient state (別テストが既に pin 済み) のままだと
 *   provider が何もしなくても green になる = 偽グリーンになるため、
 *   毎回「pin されていない状態」へ戻してから boot() を再実行する。
 * ★退避直後に try を開き、assert 失敗時も finally で必ず復元する
 *   (プロセス大域状態を他テストへ漏らさない)。
 * ★Http::fake() は不要 (このテストは 1 バイトも送信しない)。
 */

test('Stripe HTTP client の timeout / connect_timeout / max_network_retries が pin 値になる', function (): void {
    // ApiRequestor::httpClient() は `if (!self::$_httpClient) { … CurlClient::instance() }` の
    // 遅延生成のため null を返さない (vendor 実査)。setHttpClient() も nullable を受けない。
    $originalClient = ApiRequestor::httpClient();
    $originalRetries = Stripe::getMaxNetworkRetries();

    try {
        // 既知の「pin されていない」状態へ戻す
        ApiRequestor::setHttpClient(new CurlClient);
        Stripe::setMaxNetworkRetries(7);

        (new ExternalClientTimeoutServiceProvider($this->app))->boot();

        $client = ApiRequestor::httpClient();
        expect($client)->toBeInstanceOf(CurlClient::class);
        expect($client->getTimeout())->toBe(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS);
        expect($client->getConnectTimeout())->toBe(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS);
        expect(Stripe::getMaxNetworkRetries())->toBe(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES);
    } finally {
        ApiRequestor::setHttpClient($originalClient);
        Stripe::setMaxNetworkRetries($originalRetries);
    }
});

test('負のコントロール: pin されていない CurlClient は SDK 既定値を返す', function (): void {
    // 上のテストが「何もしなくても green」ではないことを示す。
    $unpinned = new CurlClient;

    expect($unpinned->getTimeout())->toBe(CurlClient::DEFAULT_TIMEOUT);
    expect($unpinned->getConnectTimeout())->toBe(CurlClient::DEFAULT_CONNECT_TIMEOUT);
    expect($unpinned->getTimeout())->not->toBe(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS);
    expect($unpinned->getConnectTimeout())->not->toBe(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS);
});

test('provider が bootstrap/providers.php に登録されている', function (): void {
    // 登録漏れは「本番だけ pin されない」= 最悪の偽グリーンになるため機械で固定する。
    $providers = require base_path('bootstrap/providers.php');

    expect($providers)->toBeArray();
    expect($providers)->toContain(ExternalClientTimeoutServiceProvider::class);
});
