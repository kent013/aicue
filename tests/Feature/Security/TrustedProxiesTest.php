<?php

declare(strict_types=1);

use App\Http\Middleware\RedirectToHttps;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
 * client IP / X-Forwarded-Proto の信頼境界 (audit-cycle-2 High-2 / T108 S5・S6)。
 *
 * かつて trustProxies(at: '*') だったため $request->ip() は XFF 最左 =
 * **クライアントが自由に書ける値**だった。allowlist 化した後の実挙動を固定する。
 *
 * 検証は「静的に at() が呼ばれていないこと」ではなく **振る舞い**で行う:
 * config('trustedproxy.proxies') を変えると ip() が変わる = framework の
 * config fallback 経路が生きている、という形で固定する
 * (TrustProxies の static prop に依存する検査を作らない)。
 */

beforeEach(function (): void {
    // 応答本文に解決後の client IP / secure 判定を出すだけの probe route
    Route::middleware('web')->get('/_ip-probe', fn () => response(
        (string) request()->ip().'|'.(request()->isSecure() ? 'https' : 'http'),
    ));
});

/** probe を叩いて [ip, scheme] を返す。 */
function ipProbe(TestCase $test, array $headers = []): array
{
    $response = $test->withHeaders($headers)->get('/_ip-probe');
    $response->assertOk();

    return explode('|', (string) $response->getContent());
}

test('proxies が空なら XFF は無視される (REMOTE_ADDR が client IP)', function (): void {
    config(['trustedproxy.proxies' => []]);

    [$ip] = ipProbe($this, ['X-Forwarded-For' => '9.9.9.9']);

    expect($ip)->not->toBe('9.9.9.9');
    expect($ip)->toBe('127.0.0.1');
});

test('proxies に接続元を登録すると XFF 由来の client IP になる', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1/32']]);

    [$ip] = ipProbe($this, ['X-Forwarded-For' => '9.9.9.9']);

    expect($ip)->toBe('9.9.9.9');
});

test('config を配列で上書きしても fallback が効く (config:cache 相当)', function (): void {
    // at() を呼んでいない = 常に config を読む。config:cache 後は plain array になるため
    // 「配列で上書きした状態」で挙動が変わることを確認する
    config(['trustedproxy.proxies' => ['127.0.0.0/8']]);
    [$trusted] = ipProbe($this, ['X-Forwarded-For' => '9.9.9.9']);

    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    [$untrusted] = ipProbe($this, ['X-Forwarded-For' => '9.9.9.9']);

    expect($trusted)->toBe('9.9.9.9');
    expect($untrusted)->toBe('127.0.0.1');
});

test('多段 XFF で hop を取りこぼすと client IP がその hop になる (runbook の警告の実挙動)', function (): void {
    // 経路: client(1.2.3.4) → hop(10.0.0.5) → app。hop を信頼していないので
    // client IP は hop の 10.0.0.5 に固定される = 全利用者が 1 バケットに落ちる (自己 DoS)
    config(['trustedproxy.proxies' => ['127.0.0.1/32']]);
    [$missedHop] = ipProbe($this, ['X-Forwarded-For' => '1.2.3.4, 10.0.0.5']);
    expect($missedHop)->toBe('10.0.0.5');

    // hop も信頼すれば本来の client IP まで遡れる
    config(['trustedproxy.proxies' => ['127.0.0.1/32', '10.0.0.0/8']]);
    [$allHops] = ipProbe($this, ['X-Forwarded-For' => '1.2.3.4, 10.0.0.5']);
    expect($allHops)->toBe('1.2.3.4');
});

/*
 * --- S6: RedirectToHttps は TrustProxies の **後** に走る ---
 *
 * prepend していたため TrustProxies より前に走り、$request->isSecure() が
 * X-Forwarded-Proto を見られなかった。LB 終端 + FORCE_HTTPS_REDIRECT=true で
 * 308 の無限ループになる潜在バグ。
 */

test('global middleware で TrustProxies が RedirectToHttps より前に走る', function (): void {
    /** @var Kernel $kernel */
    $kernel = app(Kernel::class);
    $global = $kernel->getGlobalMiddleware();

    $trustProxies = array_search(TrustProxies::class, $global, true);
    $redirect = array_search(RedirectToHttps::class, $global, true);

    expect($trustProxies)->not->toBeFalse('TrustProxies が global middleware に無い');
    expect($redirect)->not->toBeFalse('RedirectToHttps が global middleware に無い (route group へ移動した?)');
    expect($trustProxies)->toBeLessThan(
        $redirect,
        'RedirectToHttps が TrustProxies より前に走ると X-Forwarded-Proto を見られず 308 ループになる',
    );
});

test('LB 終端 (X-Forwarded-Proto: https) では 308 が返らない', function (): void {
    config(['security.force_https_redirect' => true]);
    config(['trustedproxy.proxies' => ['127.0.0.1/32']]);

    [, $scheme] = ipProbe($this, ['X-Forwarded-Proto' => 'https']);
    expect($scheme)->toBe('https');
});

test('LB 終端でも X-Forwarded-Proto: http なら 308 が返る', function (): void {
    config(['security.force_https_redirect' => true]);
    config(['trustedproxy.proxies' => ['127.0.0.1/32']]);

    $this->withHeaders(['X-Forwarded-Proto' => 'http'])
        ->get('/_ip-probe')
        ->assertStatus(308);
});
