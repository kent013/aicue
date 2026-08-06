<?php

declare(strict_types=1);

use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\VerifySnsSignature;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * T120 で新設した認証系 / webhook throttle の behavioral proof。
 *
 * 目録検査 (ThrottleCoverageInventoryTest) は「throttle が付いているか」までしか見ない。
 * 実際に 429 で止まるか・どの単位で数えるか・どの middleware より先に走るかは
 * 実挙動でしか固定できないため、ここで契約として固定する。
 *
 * cache store はテスト実行時 array に強制されている (phpunit.xml) ため、
 * app を作り直す各テストで RateLimiter のバケットは空から始まる。
 */

/** 何回叩いても同じ結果になる POST helper。 */
function throttleProbePost(string $uri, array $payload = []): TestResponse
{
    return test()->post($uri, $payload);
}

test('POST /forgot-password は 5 回目まで通り 6 回目で 429 (IP レーン 5/min)', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        $response = throttleProbePost('/forgot-password', ['email' => 'probe@example.com']);
        expect($response->getStatusCode())->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }

    expect(throttleProbePost('/forgot-password', ['email' => 'probe@example.com'])->getStatusCode())->toBe(429);
});

test('429 応答は Retry-After と X-RateLimit-* ヘッダを持つ (既定ヘッダを削らない)', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        throttleProbePost('/forgot-password', ['email' => 'probe@example.com']);
    }
    $response = throttleProbePost('/forgot-password', ['email' => 'probe@example.com']);

    expect($response->getStatusCode())->toBe(429);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
    expect($response->headers->get('X-RateLimit-Limit'))->not->toBeNull();
    expect($response->headers->get('X-RateLimit-Remaining'))->not->toBeNull();
});

/*
 * IP+email レーン (10/60min) は 2 番目の Limit のため、応答ヘッダの残数はこのレーンを表す
 * (ThrottleRequests は limits を順に処理し、ヘッダは最後の Limit で上書きする)。
 * 大文字小文字違いで残数が連続して減れば「同じ bucket を消費した」= 正規化が効いている。
 */
test('POST /forgot-password は大文字小文字違いの email で同じ bucket を消費する (正規化の証明)', function (): void {
    $first = throttleProbePost('/forgot-password', ['email' => 'Probe.User@Example.COM']);
    $second = throttleProbePost('/forgot-password', ['email' => 'probe.user@example.com']);

    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
        (int) $first->headers->get('X-RateLimit-Remaining') - 1,
        '大文字小文字違いで残数が戻った = 別 bucket に分かれている (throttle bypass)',
    );
});

test('POST /forgot-password は同一 IP なら email を変えても IP レーンで止まる (メール爆撃の抑制)', function (): void {
    // email レーン (10/60min) はそれぞれ余裕があるが、IP レーン (5/min) が先に尽きる
    for ($i = 1; $i <= 5; $i++) {
        $response = throttleProbePost('/forgot-password', ['email' => "probe{$i}@example.com"]);
        expect($response->getStatusCode())->not->toBe(429);
    }

    expect(throttleProbePost('/forgot-password', ['email' => 'probe6@example.com'])->getStatusCode())->toBe(429);
});

test('POST /reset-password も 6 回目で 429 (reset token 総当りの抑制)', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        throttleProbePost('/reset-password', ['token' => 'invalid', 'email' => 'probe@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!']);
    }

    expect(throttleProbePost('/reset-password', ['token' => 'invalid', 'email' => 'probe@example.com'])->getStatusCode())->toBe(429);
});

test('POST /register も 6 回目で 429 (アカウント量産の抑制)', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        throttleProbePost('/register', ['email' => "probe{$i}@example.com"]);
    }

    expect(throttleProbePost('/register', ['email' => 'probe6@example.com'])->getStatusCode())->toBe(429);
});

/*
 * 異常入力の契約は 3 つに分ける。
 * 極端に長い文字列も有効な string なので EmailHash が計算され、anon bucket とは別になる。
 */
test('login limiter は username が配列 / 空文字のとき anon fallback として同じ bucket を消費する', function (): void {
    $payloads = [
        ['email' => ['array-value'], 'password' => 'x'],
        ['email' => '', 'password' => 'x'],
        ['password' => 'x'],
        ['email' => ['a'], 'password' => 'x'],
        ['email' => '', 'password' => 'x'],
    ];

    foreach ($payloads as $payload) {
        expect(throttleProbePost('/login', $payload)->getStatusCode())->not->toBe(429);
    }

    expect(throttleProbePost('/login', ['email' => '', 'password' => 'x'])->getStatusCode())->toBe(429);
});

test('login limiter は極端に長い文字列でも 500 にならず、同一値の反復では同じ bucket を消費する', function (): void {
    $long = str_repeat('a', 10000).'@example.com';

    for ($i = 1; $i <= 5; $i++) {
        $response = throttleProbePost('/login', ['email' => $long, 'password' => 'x']);
        expect($response->getStatusCode())->toBeLessThan(500, '極端に長い入力で 500 になりました');
        expect($response->getStatusCode())->not->toBe(429);
    }

    expect(throttleProbePost('/login', ['email' => $long, 'password' => 'x'])->getStatusCode())->toBe(429);
});

test('認証フォーム系 limiter は異なる異常文字列でも IP レーンを共有する', function (string $uri, string $field): void {
    // IP 単独レーンは email に依存しない (IP-email レーンは値ごとに分かれるのが正しい挙動)。
    // 3 レーンすべてで確認することで route と limiter の配線ミスも検出する。
    $weird = [['array'], '', str_repeat('z', 500), 12345, null];

    foreach ($weird as $value) {
        $response = throttleProbePost($uri, $value === null ? [] : [$field => $value]);
        expect($response->getStatusCode())->not->toBe(429);
    }

    expect(throttleProbePost($uri, [$field => 'probe@example.com'])->getStatusCode())->toBe(429);
})->with([
    'password-reset-request' => ['/forgot-password', 'email'],
    'password-reset-submit' => ['/reset-password', 'email'],
    'account-register' => ['/register', 'email'],
]);

/*
 * Unicode で異なる 2 つの email が同じ bucket に落ちると、無関係アカウントが
 * 巻き添えでロックアウトされる (Str::transliterate 廃止の回帰テスト)。
 */
test('login limiter は Unicode で異なる 2 つの email を同じ bucket に collapse させない', function (): void {
    // transliterate はどちらも "cafe@example.com" へ潰す
    $first = throttleProbePost('/login', ['email' => 'café@example.com', 'password' => 'x']);
    $second = throttleProbePost('/login', ['email' => 'cafe@example.com', 'password' => 'x']);

    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
        (int) $first->headers->get('X-RateLimit-Remaining'),
        'Unicode の異なる email が同じ bucket に collapse しています (巻き添えロックアウト)',
    );
});

/** 解決後 middleware 列のクラス名リスト。 */
function throttleProbeResolvedClasses(string $routeName): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();
    $route = $routes->getByName($routeName);
    expect($route)->not->toBeNull("route [{$routeName}] が存在しない");

    return array_map(
        static fn (mixed $entry): string => is_string($entry) ? explode(':', $entry, 2)[0] : '(closure)',
        $router->gatherRouteMiddleware($route),
    );
}

test('POST /ses/notification は throttle が署名検証より先に走る (実効順の固定)', function (): void {
    $resolved = throttleProbeResolvedClasses('webhooks.ses');

    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);
    $signatureIndex = array_search(VerifySnsSignature::class, $resolved, true);

    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が実効列に無い');
    expect($signatureIndex)->not->toBeFalse('VerifySnsSignature が実効列に無い');
    expect($throttleIndex)->toBeLessThan(
        $signatureIndex,
        '署名検証が throttle より先だと、署名検証コスト (証明書取得を伴う) が無制限に増幅する',
    );
});

test('POST /ses/notification は不正 body でも上限を超えると 400 ではなく 429 になる', function (): void {
    // 上限未満では VerifySnsSignature まで到達して 400 (envelope 不正)。
    // 署名不正 (403) は証明書取得を伴うため対照には使わない (テストから外部通信を出さない)。
    expect(throttleProbePost('/ses/notification')->getStatusCode())->toBe(400);

    $status = 400;
    // webhook-ses は 300/min。上限 + 1 まで叩くと throttle が先に短絡する
    for ($i = 2; $i <= 301; $i++) {
        $status = throttleProbePost('/ses/notification')->getStatusCode();
        if ($status === 429) {
            break;
        }
    }

    expect($status)->toBe(429);
})->group('slow');

test('2FA 管理 route は throttle が recent-auth より先に走る', function (): void {
    $resolved = throttleProbeResolvedClasses('two-factor.disable');

    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);
    $recentAuthIndex = array_search(RequireRecentAuth::class, $resolved, true);

    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が実効列に無い');
    expect($recentAuthIndex)->not->toBeFalse('RequireRecentAuth が実効列に無い');
    expect($throttleIndex)->toBeLessThan($recentAuthIndex);
});
