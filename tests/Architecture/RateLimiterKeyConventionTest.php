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
 *
 * ★空振り検査 (母集団非空) の**新規付与は不要**である。理由:
 *   走査 (`RateLimiterRegistrationScanner::scanDirectory(app_path(), 'app')`) の結果が
 *   0 件になると、「scan で検出した limiter 名の集合が inventory と完全一致する」が
 *   非空の inventory (`rateLimiterKeyInventory()`) と食い違って**必ず赤くなる**。
 *   つまり母集団の非空は完全一致の pin が構造的に担保している。
 *   加えて各 limiter は実評価されるため、登録が消えれば `rateLimiterProduceLimits()` の
 *   `Assert::notNull` が落ちる (静的走査と実挙動の両側から空振りが塞がっている)。
 *   走査器自身の正例 / 負例は `tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php`。
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
    $user->forceFill(['id' => 4242]);

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
        // アプリ内受諾 (T134)。RateLimiterKeys::actorOrIp の actor/IP 2 分岐。
        // route parameter ({invitation}) はキーに混ぜない (bucket が id ごとに分かれると
        // 「429 になるまでの回数」が招待の実在オラクルになる)。
        'invitation-accept-in-app' => [
            'scenarios' => [
                'authenticated' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser()),
                'guest' => $noEmail,
            ],
            'expectedKeyPrefixes' => ['invitation-accept-in-app:user', 'invitation-accept-in-app:ip'],
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

    // ── T125: inline から移行したレーン群 ──────────────────────────────
    // いずれも「認証済みは actor / 未認証は IP」の 2 分岐 (passkeys と同形)。
    // throttle は route によっては auth より後に走る (現行 priority list では後) ため、
    // guest 分岐は防御的な冗長だが、closure 単体としては両分岐が実在する。
    foreach ([
        'password-verify',
        'password-set',
        'email-verification',
        'two-factor-manage',
        'invitation-accept-submit',
        'plan-activate',
    ] as $lane) {
        $inventory[$lane] = [
            'scenarios' => [
                'authenticated' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser()),
                'guest' => $noEmail,
            ],
            'expectedKeyPrefixes' => [$lane.':user', $lane.':ip'],
            'emailScenarios' => [],
        ];
    }

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

/*
 |--------------------------------------------------------------------------
 | T125: レーン分離の実証 (キーの衝突検査 + full key の固定)
 |--------------------------------------------------------------------------
 |
 | ★保証範囲を誇張しない: 以下の衝突検査は **inventory の scenario で produce した
 |   キーだけ**を見る。scenario に無い分岐 (例: api-* の oauth-user 経路) の衝突は
 |   検出できない。これは既存の expectedKeyPrefixes 検査と同じ制約である。
 */

/**
 * 意図的に同一キーを共有している limiter の組 (それ以外は pairwise disjoint であること)。
 *
 * ★レーンを分ける = **bucket が実際に分かれる**ことであり、
 *   キー接頭辞の宣言が違っても produce されるキーが同じなら分かれていない。
 *   ここに載っていない組が衝突したら、それは「レーンを分けたつもりで分かれていない」バグである。
 *
 * @return array<string, array{limiters: list<string>, reason: string}>
 */
function rateLimiterSharedKeyGroups(): array
{
    return [
        'api-actor' => [
            'limiters' => ['api-read', 'api-write', 'api-status'],
            'reason' => '3 本とも apiRateKey() を返し、1 クライアントの read / write / status を'
                .'1 つの bucket で数える現行仕様 (実効上限は最小の api-status = 30/min に律速する)。'
                .'分離は 1 クライアントの総量上限を実質 120/min から 210/min へ**緩める**変更であり、'
                .'API の abuse 耐性の判断を伴うため T125 では挙動を変えず、事実の記録のみ行う。',
        ],
    ];
}

/**
 * limiter が produce するキー文字列の集合 (全 scenario 合算)。
 *
 * @param  array{scenarios: array<string, callable(): Request>, expectedKeyPrefixes: list<string>, emailScenarios: list<string>}  $spec
 * @return list<string>
 */
function rateLimiterProducedKeys(string $name, array $spec): array
{
    $keys = [];
    foreach ($spec['scenarios'] as $build) {
        foreach (rateLimiterProduceLimits($name, $build()) as $limit) {
            $keys[(string) $limit->key] = true;
        }
    }

    return array_keys($keys);
}

/**
 * helper (`RateLimiterKeys::actorOrIp`) を使う limiter の **full key** 期待値。
 *
 * ★`expectedKeyPrefixes` は接頭辞しか見ないため、suffix (actor id / IP) の作り方が
 *   変わっても検出できない。S1 の helper 移行が **bucket をリセットしない**ことを
 *   主張するには full key の同一性が要る (prefix 一致では不十分)。
 *
 * probe の固定値: user id = 4242 (rateLimiterProbeUser) / IP = 203.0.113.7。
 *
 * @return array<string, array{authenticated: string, guest: string}>
 */
function rateLimiterActorOrIpFullKeys(): array
{
    $ip = rateLimiterScenarioIp();
    $lanes = [
        'passkeys',
        'two-factor-secret-read',
        'password-verify',
        'password-set',
        'email-verification',
        'two-factor-manage',
        'invitation-accept-submit',
        'plan-activate',
        // T134 で新設。helper 経由なので同じ full key 契約に載る
        'invitation-accept-in-app',
    ];

    $expected = [];
    foreach ($lanes as $lane) {
        $expected[$lane] = [
            'authenticated' => $lane.':user:4242',
            'guest' => $lane.':ip:'.$ip,
        ];
    }

    return $expected;
}

test('actor/IP レーンの full key が宣言と完全一致する (helper 移行で bucket をリセットしない)', function (): void {
    $inventory = rateLimiterKeyInventory();
    $violations = [];

    foreach (rateLimiterActorOrIpFullKeys() as $lane => $expected) {
        foreach ($expected as $scenario => $key) {
            $limits = rateLimiterProduceLimits($lane, $inventory[$lane]['scenarios'][$scenario]());
            $actual = array_map(static fn (Limit $limit): string => (string) $limit->key, $limits);
            if ($actual !== [$key]) {
                $violations[] = "{$lane}/{$scenario}: 期待 [{$key}] 実際 [".implode(', ', $actual).']';
            }
        }
    }

    expect($violations)->toBe([],
        'キー文字列が変わると既存 bucket がリセットされ、デプロイ直後に枠が復活します。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('共有グループの宣言は実在する limiter を 2 本以上指す', function (): void {
    $known = array_keys(rateLimiterKeyInventory());
    $violations = [];

    foreach (rateLimiterSharedKeyGroups() as $group => $spec) {
        if (count($spec['limiters']) < 2) {
            $violations[] = "{$group}: 共有グループは 2 本以上でなければ意味がありません";
        }
        if (mb_strlen($spec['reason']) < 30) {
            $violations[] = "{$group}: 根拠が 30 文字未満です";
        }
        foreach ($spec['limiters'] as $limiter) {
            if (! in_array($limiter, $known, true)) {
                $violations[] = "{$group}: 未知の limiter [{$limiter}]";
            }
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('宣言した共有グループは実際にキーを共有している (死んだ宣言の検出)', function (): void {
    // ★グループが実際には衝突していないなら、その宣言は「もう不要な免除」である。
    //   残すと次に読む人へ嘘を伝え、かつ本物の衝突を隠す枠になる。
    $inventory = rateLimiterKeyInventory();
    $violations = [];

    foreach (rateLimiterSharedKeyGroups() as $group => $spec) {
        $sets = [];
        foreach ($spec['limiters'] as $limiter) {
            $sets[$limiter] = rateLimiterProducedKeys($limiter, $inventory[$limiter]);
        }

        foreach ($spec['limiters'] as $limiter) {
            $others = array_merge(...array_values(array_diff_key($sets, [$limiter => true])));
            if (array_intersect($sets[$limiter], $others) === []) {
                $violations[] = "{$group}/{$limiter}: 他のメンバーとキーを共有していません (宣言が古い)";
            }
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('共有グループ外の limiter は互いにキーを共有しない (レーン分離の実証)', function (): void {
    $inventory = rateLimiterKeyInventory();

    // 同一グループのペアだけを許可集合にする
    $allowed = [];
    foreach (rateLimiterSharedKeyGroups() as $spec) {
        foreach ($spec['limiters'] as $a) {
            foreach ($spec['limiters'] as $b) {
                $allowed[$a.'|'.$b] = true;
            }
        }
    }

    $keys = [];
    foreach ($inventory as $name => $spec) {
        $keys[$name] = rateLimiterProducedKeys($name, $spec);
    }

    $names = array_keys($inventory);
    $violations = [];
    foreach ($names as $i => $a) {
        foreach (array_slice($names, $i + 1) as $b) {
            if (isset($allowed[$a.'|'.$b])) {
                continue;
            }
            $shared = array_intersect($keys[$a], $keys[$b]);
            if ($shared !== []) {
                $violations[] = "{$a} と {$b} が同じキーを produce しています: ".implode(', ', $shared);
            }
        }
    }

    expect($violations)->toBe([],
        'レーンを分けたつもりで bucket が分かれていません。'
        .'キーの接頭辞にレーン名が入っているか確認してください'
        .'(意図的な共有なら rateLimiterSharedKeyGroups() へ根拠付きで登録すること)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
