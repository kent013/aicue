<?php

declare(strict_types=1);

use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\VerifySnsSignature;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;

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

test('2FA 管理 route は throttle が recent-auth より先に走る', function (string $routeName): void {
    $resolved = throttleProbeResolvedClasses($routeName);

    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);
    $recentAuthIndex = array_search(RequireRecentAuth::class, $resolved, true);

    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が実効列に無い');
    expect($recentAuthIndex)->not->toBeFalse('RequireRecentAuth が実効列に無い');
    expect($throttleIndex)->toBeLessThan($recentAuthIndex);
})->with([
    'DELETE (2FA 無効化)' => ['two-factor.disable'],
    // T121: 秘密を返す GET 側も同じ実効順であること (throttle が先 = recent-auth の
    // 判定コストと 409/302 分岐の前に回数上限が効く)。
    'GET (リカバリコード表示)' => ['two-factor.recovery-codes'],
]);

/*
 |--------------------------------------------------------------------------
 | T121: 未認証で到達する認証面 GET / 2FA 秘密 GET の throttle (behavioral proof)
 |--------------------------------------------------------------------------
 |
 | ここで固定するのは「何回で 429 になるか」と「**何を 1 つの bucket として数えるか**」。
 | 目録検査 (ThrottleCoverageInventoryTest) は付与の有無しか見ないため、
 | キーに route parameter / token が混ざる (= bucket が分かれて総当りが有界にならない) 事故や、
 | named limiter を inline に戻してレーンが合流する事故はここでしか検出できない。
 */

test('GET /auth/{provider}/callback は 10 回目まで通り 11 回目で 429 (IP レーン 10/min。無効リクエストも枠を消費する)', function (): void {
    // session に social_auth_intent を置かない = controller は Socialite に触れる前に
    // login へ 302 する。**その無効リクエストでも枠を消費する**ことを同時に示す
    // (throttle は controller より前で数えるため)。
    for ($i = 1; $i <= 10; $i++) {
        $response = $this->get('/auth/google/callback?code=dummy&state=dummy');
        expect($response->getStatusCode())->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }

    expect($this->get('/auth/google/callback?code=dummy&state=dummy')->getStatusCode())->toBe(429);
});

test('social.callback は provider を変えても同じ bucket を消費する (存在オラクル不成立)', function (): void {
    // limiter キーに route parameter ({provider}) が混ざっていないことの証明。
    // 混ざっていると provider ごとに bucket が分かれ、「429 になるまでの回数」が
    // 「その provider が有効か」を漏らす観測点になる。
    config()->set('template.social_providers', [
        'google' => ['label' => 'Google', 'capability' => 'fresh_auth_prompt_only', 'email_trust' => 'confirmed'],
        'probe' => ['label' => 'Probe', 'capability' => 'fresh_auth_prompt_only', 'email_trust' => 'unconfirmed'],
    ]);

    $first = $this->get('/auth/google/callback?code=dummy&state=dummy');
    $second = $this->get('/auth/probe/callback?code=dummy&state=dummy');

    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
        (int) $first->headers->get('X-RateLimit-Remaining') - 1,
        'provider を変えたら残数が戻った = provider ごとに bucket が分かれている (存在オラクル)',
    );
});

test('social.callback の throttle は Socialite を一切呼ばずに枠を消費する (外向き HTTP の増幅が有界)', function (): void {
    // 「throttle が外向き HTTP より前にある」ことの本体。
    // intent 不在で controller が Socialite に触れる前に短絡することを spy で直接示す
    // (Socialite は Guzzle を直接使うため Http::preventStrayRequests() では捕まらない。
    //  preventStrayRequests は Laravel HTTP client 側の追加の網として併用する)。
    Http::preventStrayRequests();
    Socialite::spy();

    $first = $this->get('/auth/google/callback?code=dummy&state=dummy');
    $second = $this->get('/auth/google/callback?code=dummy&state=dummy');

    // ★「Socialite を呼ばない」だけでは半分。**枠を消費している**ことまで示して初めて
    //   「外向き HTTP の増幅が有界」になる (呼ばれず数えられもしないなら無制限に踏める)。
    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
        (int) $first->headers->get('X-RateLimit-Remaining') - 1,
        'Socialite に到達しない無効リクエストが枠を消費していません (連打で増幅を狙える)',
    );
    Socialite::shouldNotHaveReceived('driver');
});

test('GET /invitations/accept は 10 回目まで通り 11 回目で 429 (無効 token でも枠を消費する)', function (): void {
    // 存在しない token で叩く (Invitations/Invalid が返るだけで DB を汚さない)。
    for ($i = 1; $i <= 10; $i++) {
        $response = $this->get('/invitations/accept?token=invalid-token');
        expect($response->getStatusCode())->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }

    expect($this->get('/invitations/accept?token=invalid-token')->getStatusCode())->toBe(429);
});

test('invitations.accept は token を変えても同じ bucket を消費する (token 総当りが有界)', function (): void {
    // query の token が limiter キーに混ざっていたら bucket が token ごとに分かれ、
    // **総当りが一切有界にならない** (1 token あたり 10 回ではなく無限に試せる)。
    $first = $this->get('/invitations/accept?token=probe-token-a');
    $second = $this->get('/invitations/accept?token=probe-token-b');

    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
        (int) $first->headers->get('X-RateLimit-Remaining') - 1,
        'token を変えたら残数が戻った = token ごとに bucket が分かれている (総当りが有界にならない)',
    );
});

test('2FA 秘密 GET のレーンは独立している — 10 回踏んでも recent-auth / 2FA 管理 POST が 429 にならない', function (): void {
    // ★本設計で最も重要な恒久回帰。**削らないこと**。
    //   two-factor.qr-code に inline throttle (`10,1`) を貼ると、inline のキーは
    //   sha1(user id) のみで route も limiter 名も入らないため、同一 actor の
    //   **全 inline throttle route が 1 bucket を共有する**
    //   (ThrottleRequests::handle() の $prefix 既定 '' + resolveRequestSignature())。
    //   その状態で描画のたびに飛ぶ GET を足すと、共有カウンタが最小 max の
    //   recent-auth.password (6,1) を先に食い潰し **再認証できなくなる**。
    //   named limiter (two-factor-secret-read) はキーに limiter 名が入るためレーンが分かれる。
    //   inline へ戻す変更を入れたらこのテストが落ちる。
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->get('/user/two-factor-qr-code')->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }
    expect($this->get('/user/two-factor-qr-code')->getStatusCode())->toBe(429, '2FA 秘密 GET のレーンが使い切られていません');

    // 秘密読み取りレーンを使い切った直後でも、別レーンは 1 枠も消費していない。
    // (認証情報が誤っていてもよい。429 でないこと = throttle で止まっていないことだけ見る)
    expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
        ->not->toBe(429, '再認証 (recent-auth.password) が 2FA 秘密 GET の巻き添えで 429 になりました');

    expect($this->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])->getStatusCode())
        ->not->toBe(429, '2FA 管理 POST が 2FA 秘密 GET の巻き添えで 429 になりました');
});

test('2FA 秘密 GET は 11 回目で 429 — これは連続取得の回数上限であって認証強度ではない (認証強度は後続 TODO B2)', function (): void {
    // ★誤読防止: ここで固定しているのは「回数の上限」だけである。
    //   qr-code / secret-key / recovery-codes を **step-up なしで読めること自体**の是非は
    //   aicue:T120 の後続 TODO B2 (recent-auth 化) の担当であり、本テストが green でも
    //   「秘密の保護が済んだ」ことは 1 ミリも意味しない。
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->get('/user/two-factor-secret-key')->getStatusCode())
            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
    }

    expect($this->get('/user/two-factor-secret-key')->getStatusCode())->toBe(429);
});

test('2FA 秘密 GET 3 本は 1 つのレーンを共有する (描画で複数発飛ぶ GET を合算して数える)', function (): void {
    // qr-code / secret-key は 2FA 設定画面の 1 描画で 2 発飛ぶ。両者が別 bucket だと
    // 「画面を開く回数」ではなく「endpoint ごとの回数」を数えることになり、
    // 秘密の連続取得の上限としては実効が薄れる。同一 limiter 名を共有していることを示す。
    // ★3 本すべてを対象にする。1 本でも別 limiter (inline `10,1` 等) に戻ると
    //   残数が連続しなくなりここで落ちる。
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $uris = ['/user/two-factor-qr-code', '/user/two-factor-secret-key', '/user/two-factor-recovery-codes'];
    $previous = null;

    foreach ($uris as $uri) {
        $remaining = $this->get($uri)->headers->get('X-RateLimit-Remaining');
        expect($remaining)->not->toBeNull("{$uri} に X-RateLimit-* が付いていません (throttle が効いていない)");

        if ($previous !== null) {
            expect((int) $remaining)->toBe($previous - 1,
                "{$uri} が他の 2FA 秘密 GET と別 bucket へ分かれています");
        }
        $previous = (int) $remaining;
    }
});
