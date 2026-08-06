<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\User;
use App\Support\EmailHash;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\Support\RateLimiterRegistrationScanner;
use Webmozart\Assert\Assert;

/*
 * named limiter のキー規約 `{レーン}:{種別}:{値}` の behavioral proof。
 *
 * ★検査対象は **named limiter のみ**。inline throttle (`throttle:6,1` 等) は
 *   フレームワーク既定のキー (認証済み = user id / 未認証 = ハッシュ化 IP) を使い、
 *   これは「認証済みかつ actor 自身に閉じる操作」では正しい数える単位である。
 *   キー明示規約は「**自前でキーを組み立てるとき**」の規約であり、対象を named limiter に限る。
 *
 * ★2 層で守る:
 *   (1) 登録の網羅 — token 走査 (RateLimiterRegistrationScanner) で見つけた
 *       `RateLimiter::for()` の名前集合が inventory と完全一致すること。
 *       解析できない登録 (unresolved) は 1 件でも fail (沈黙する登録を作らせない)。
 *   (2) キーの実挙動 — 各 limiter を実際に評価し、produce されたキーが規約に合うこと。
 */

/** キー規約の正規表現 (`{レーン}:{種別}:` の接頭辞)。 */
function rateLimiterKeyConventionPattern(): string
{
    return '#^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*:#';
}

/** 評価シナリオが使う固定 IP (キーに現れることを前提にしない。単に決定性のため)。 */
function rateLimiterScenarioIp(): string
{
    return '203.0.113.7';
}

/** email を扱う scenario が使う平文 email (大文字混じり = 正規化の検証を兼ねる)。 */
function rateLimiterScenarioEmail(): string
{
    return 'Throttle.Probe@Example.COM';
}

/** guest な Request (session なし / user なし)。 */
function rateLimiterGuestRequest(array $input = []): Request
{
    $request = Request::create('/probe', 'POST', $input, server: ['REMOTE_ADDR' => rateLimiterScenarioIp()]);
    $request->setUserResolver(static fn (): ?User => null);

    return $request;
}

/** 認証済み Request (指定 user を全 guard で返す)。 */
function rateLimiterAuthenticatedRequest(User $user, array $input = []): Request
{
    $request = rateLimiterGuestRequest($input);
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

/** session 付き Request (two-factor limiter は session 必須)。 */
function rateLimiterSessionRequest(?string $loginId): Request
{
    $request = rateLimiterGuestRequest();
    $session = new Store('probe-session', new ArraySessionHandler(120));
    if ($loginId !== null) {
        $session->put('login.id', $loginId);
    }
    $request->setLaravelSession($session);

    return $request;
}

/** DB に触れずに id を持つ User を組み立てる (Architecture レーンは RefreshDatabase 非適用)。 */
function rateLimiterProbeUser(?int $organizationId = null): User
{
    $user = User::factory()->make();
    Assert::isInstanceOf($user, User::class);
    $user->forceFill(['id' => 4242, 'current_organization_id' => $organizationId]);

    return $user;
}

/** DB に触れずに id を持つ ApiKey を組み立てる。 */
function rateLimiterProbeApiKey(): ApiKey
{
    $apiKey = ApiKey::factory()->make(['organization_id' => 77]);
    Assert::isInstanceOf($apiKey, ApiKey::class);
    $apiKey->forceFill(['id' => 99]);

    return $apiKey;
}

/** api-* limiter の with-api-key scenario。 */
function rateLimiterApiKeyRequest(): Request
{
    $request = rateLimiterGuestRequest();
    $request->attributes->set('api_key', rateLimiterProbeApiKey());

    return $request;
}

/**
 * limiter ごとの評価シナリオと期待されるキー接頭辞。
 *
 * @return array<string, array{
 *   scenarios: array<string, callable(): Request>,
 *   expectedKeyPrefixes: list<string>,
 *   emailScenarios: list<string>,
 * }>
 *   scenarios           = 分岐名 => Request ビルダ
 *   expectedKeyPrefixes = produce されるべき `{レーン}:{種別}` の**完全な**集合
 *   emailScenarios      = email をキーに含む scenario 名 (平文残存 / ハッシュ化の検証対象)
 */
function rateLimiterKeyInventory(): array
{
    $email = rateLimiterScenarioEmail();
    $withEmail = static fn (string $field): callable => static fn (): Request => rateLimiterGuestRequest([$field => $email]);
    $noEmail = static fn (): Request => rateLimiterGuestRequest();

    /** @var array<string, array{scenarios: array<string, callable(): Request>, expectedKeyPrefixes: list<string>, emailScenarios: list<string>}> $inventory */
    $inventory = [
        'login' => [
            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
            'expectedKeyPrefixes' => ['login:email-ip'],
            'emailScenarios' => ['with-email'],
        ],
        'two-factor' => [
            'scenarios' => [
                'with-login-id' => static fn (): Request => rateLimiterSessionRequest('4242'),
                'guest' => static fn (): Request => rateLimiterSessionRequest(null),
            ],
            'expectedKeyPrefixes' => ['two-factor:login-id', 'two-factor:ip'],
            'emailScenarios' => [],
        ],
        'passkeys' => [
            'scenarios' => [
                'authenticated' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser()),
                'guest' => $noEmail,
            ],
            'expectedKeyPrefixes' => ['passkeys:user', 'passkeys:ip'],
            'emailScenarios' => [],
        ],
        'render-trigger' => [
            'scenarios' => [
                'authenticated-with-org' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser(7)),
                'guest' => $noEmail,
            ],
            'expectedKeyPrefixes' => ['render-trigger:actor-org'],
            'emailScenarios' => [],
        ],
        'inquiry' => [
            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
            'expectedKeyPrefixes' => ['inquiry:ip', 'inquiry:ip-email'],
            'emailScenarios' => ['with-email'],
        ],
        'password-reset-request' => [
            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
            'expectedKeyPrefixes' => ['password-reset-request:ip', 'password-reset-request:ip-email'],
            'emailScenarios' => ['with-email'],
        ],
        'password-reset-submit' => [
            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
            'expectedKeyPrefixes' => ['password-reset-submit:ip', 'password-reset-submit:ip-email'],
            'emailScenarios' => ['with-email'],
        ],
        'account-register' => [
            'scenarios' => ['with-email' => $withEmail('email'), 'no-email' => $noEmail],
            'expectedKeyPrefixes' => ['account-register:ip', 'account-register:ip-email'],
            'emailScenarios' => ['with-email'],
        ],
        'api-mcp' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['mcp:ip'],
            'emailScenarios' => [],
        ],
        'oauth-register' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['oauth-register:ip'],
            'emailScenarios' => [],
        ],
        'webhook-ses' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['webhook-ses:ip'],
            'emailScenarios' => [],
        ],
        'webhook-stripe' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['webhook-stripe:ip'],
            'emailScenarios' => [],
        ],
        'social-callback' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['social-callback:ip'],
            'emailScenarios' => [],
        ],
        'invitation-accept' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['invitation-accept:ip'],
            'emailScenarios' => [],
        ],
        // 認証済み / 未認証の 2 分岐 (passkeys と同じ形)。
        // throttle は auth middleware より先に走るため guest 分岐も実在する。
        'two-factor-secret-read' => [
            'scenarios' => [
                'authenticated' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser()),
                'guest' => $noEmail,
            ],
            'expectedKeyPrefixes' => ['two-factor-secret-read:user', 'two-factor-secret-read:ip'],
            'emailScenarios' => [],
        ],
    ];

    // api-read / api-write / api-status は同一 apiRateKey() を共有する
    // (oauth-user 分岐は guard 解決が要るため scenario から外す = expectedKeyPrefixes にも入れない)。
    foreach (['api-read', 'api-write', 'api-status'] as $lane) {
        $inventory[$lane] = [
            'scenarios' => [
                'with-api-key' => static fn (): Request => rateLimiterApiKeyRequest(),
                'guest' => $noEmail,
            ],
            'expectedKeyPrefixes' => ['api:api-key', 'api:ip'],
            'emailScenarios' => [],
        ];
    }

    return $inventory;
}

/**
 * limiter を評価して produce された Limit を返す。
 *
 * @return list<Limit>
 */
function rateLimiterProduceLimits(string $name, Request $request): array
{
    $limiter = app(CacheRateLimiter::class)->limiter($name);
    Assert::notNull($limiter, "named limiter [{$name}] が登録されていません");

    $result = $limiter($request);
    $limits = is_array($result) ? array_values($result) : [$result];
    Assert::allIsInstanceOf($limits, Limit::class);

    return $limits;
}

/** キーから `{レーン}:{種別}` の接頭辞を取り出す。 */
function rateLimiterKeyPrefix(string $key): string
{
    $segments = explode(':', $key);

    return ($segments[0] ?? '').':'.($segments[1] ?? '');
}

test('scan で検出した limiter 名の集合が inventory と完全一致する (未知 limiter は fail)', function (): void {
    $scanned = RateLimiterRegistrationScanner::scanDirectory(app_path(), 'app');

    $found = array_values(array_unique($scanned['names']));
    sort($found);
    $expected = array_keys(rateLimiterKeyInventory());
    sort($expected);

    expect($found)->toBe($expected,
        'app/ 配下の RateLimiter::for() 登録と rateLimiterKeyInventory() が食い違っています。'
        .'limiter を足したらキー規約の検証シナリオも同時に登録してください。');
});

test('scan の unresolved が 0 件である (解析できない登録を沈黙させない)', function (): void {
    $scanned = RateLimiterRegistrationScanner::scanDirectory(app_path(), 'app');

    expect($scanned['unresolved'])->toBe([],
        'RateLimiter::for() の登録で解析できないものがあります。'
        .'第 1 引数はリテラル文字列で、呼び出しは use 済み短縮名か完全修飾名で書いてください。'
        .PHP_EOL.implode(PHP_EOL, $scanned['unresolved']));
});

test('全 scenario の全 Limit キーが規約パターン {レーン}:{種別}:{値} に一致する', function (): void {
    $pattern = rateLimiterKeyConventionPattern();
    $violations = [];

    foreach (rateLimiterKeyInventory() as $name => $spec) {
        foreach ($spec['scenarios'] as $scenario => $build) {
            foreach (rateLimiterProduceLimits($name, $build()) as $limit) {
                $key = (string) $limit->key;
                if (preg_match($pattern, $key) !== 1) {
                    $violations[] = "{$name}/{$scenario}: キー [{$key}] が規約に一致しません";
                }
            }
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('produce された {レーン}:{種別} 集合が expectedKeyPrefixes と完全一致する', function (): void {
    $violations = [];

    foreach (rateLimiterKeyInventory() as $name => $spec) {
        $produced = [];
        foreach ($spec['scenarios'] as $build) {
            foreach (rateLimiterProduceLimits($name, $build()) as $limit) {
                $produced[rateLimiterKeyPrefix((string) $limit->key)] = true;
            }
        }

        $actual = array_keys($produced);
        sort($actual);
        $expected = $spec['expectedKeyPrefixes'];
        sort($expected);

        if ($actual !== $expected) {
            $violations[] = "{$name}: 期待 [".implode(', ', $expected).'] 実際 ['.implode(', ', $actual).']';
        }
    }

    expect($violations)->toBe([],
        'limiter が produce するキー接頭辞が宣言と食い違っています。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('email を扱う limiter のキーに平文 email も正規化済み email も含まれない', function (): void {
    $plain = rateLimiterScenarioEmail();
    $normalized = mb_strtolower($plain);
    $violations = [];

    foreach (rateLimiterKeyInventory() as $name => $spec) {
        foreach ($spec['emailScenarios'] as $scenario) {
            foreach (rateLimiterProduceLimits($name, $spec['scenarios'][$scenario]()) as $limit) {
                $key = (string) $limit->key;
                if (str_contains($key, $plain) || str_contains($key, $normalized)) {
                    $violations[] = "{$name}/{$scenario}: キーに email 平文が残っています";
                }
            }
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('email を扱う limiter のキーに EmailHash::compute の値が含まれる (正規化 + 鍵付きハッシュ)', function (): void {
    // 大文字混じりの平文を正規化してからハッシュ化する = 大文字小文字での bypass が起きない
    $hash = EmailHash::compute(mb_strtolower(rateLimiterScenarioEmail()));
    $violations = [];

    foreach (rateLimiterKeyInventory() as $name => $spec) {
        foreach ($spec['emailScenarios'] as $scenario) {
            $found = false;
            foreach (rateLimiterProduceLimits($name, $spec['scenarios'][$scenario]()) as $limit) {
                if (str_contains((string) $limit->key, $hash)) {
                    $found = true;
                }
            }
            if (! $found) {
                $violations[] = "{$name}/{$scenario}: キーに EmailHash::compute() の値が含まれていません";
            }
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});
