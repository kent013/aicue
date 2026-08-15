<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * 後付け middleware の「焼き込み」と「剥落」を実測で固定する (T173 / 逸脱 D19)。
 *
 * 本アプリは vendor route への middleware 後付けを 2 つの binder
 * (RouteThrottleBinder / RouteMiddlewareBinder) で行い、**経路キャッシュ起動では
 * 1 本も走らせない**契約を採っている。したがって cached 運用での保護の実体は
 * `php artisan route:cache` の**生成時に焼き込まれた middleware 列**である。
 * この構造を選んだ判断は docs/template-divergence.md の D19 に登録してある。
 *
 * ★このテストが保証すること (2 つ):
 *   1. 起動時に後付けした middleware 列が、`route:cache` と**同じ順序**
 *      (直列化の準備 → compile) を通しても欠落・変形せず、焼き込みの入力へ写ること。
 *   2. その並びから alias が 1 本欠けると、保護が**実際に**効かなくなること
 *      (= stale cache が無音で保護を外す、という主張の実測)。
 *
 * ★保証しないこと (誇張しない):
 *   - `php artisan route:cache` コマンド全体が成功すること。とくに担い手が Closure の
 *     route の直列化可否は本テストの主題ではない (下の母集団の限定を参照)。
 *   - 実際に cache ファイルを書き出して**別プロセスで起動**したときの起動順の再現。
 *     本テストは同一プロセス内で完結する。**「cached 起動を再現した」とは書かない**。
 *   - 起動時の cache の鮮度 (古い cache から起動していないか) は検査できない。
 *   - `compile()` の戻り値と実際に書き出されるファイルの内容が同一であることは、
 *     `RouteCacheCommand` の実装を読んだ上での推論である。検査 1 が
 *     「直列化の準備 → compile」の順を実際に通すことでその推論を支えるが、
 *     `var_export` してファイルへ書き、別プロセスで読み戻す区間は通っていない。
 *     **framework を更新したら本テスト群の前提を人手で読み直すこと。**
 *
 * ★検査 1 の性質を正確に言う: これは 2 つの性質の合成である —
 *   (i) 直列化の準備 (`Route::prepareForSerialization()`) が middleware 列を変えないこと、
 *   (ii) `compile()` が準備後の action を `attributes` へ写すこと。
 *   (ii) だけなら vendor 実装の転記の確認にすぎない。**転記の確認に、実際に変わり得る
 *   直列化の準備の段を足した**形である。
 */

/** 起動中の router。 */
function routeCacheBakedRouter(): Router
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();

    return $router;
}

/**
 * 比較の母集団: **名前を持ち、かつ担い手が文字列**の route。
 *
 * 担い手が Closure の route を外すのは、`prepareForSerialization()` が Closure の
 * 直列化を試みるためである。直列化できるか否かは「`route:cache` が成功するか」の話であって、
 * 「後付けした middleware 列が焼き込みの入力へ写るか」という本テストの主題ではない。
 * 名前を持たない route を外すのは、`compile()` の `attributes` が route 名をキーにするため
 * 空文字のキーへ潰れて比較が成立しないからである。
 *
 * @return list<IlluminateRoute>
 */
function routeCacheBakedTargetRoutes(): array
{
    $routes = routeCacheBakedRouter()->getRoutes();
    $routes->refreshNameLookups();

    $targets = [];

    foreach ($routes as $route) {
        $name = $route->getName();
        if (! is_string($name) || $name === '') {
            continue;
        }

        if (! is_string($route->getAction('uses'))) {
            continue;
        }

        $targets[] = $route;
    }

    return $targets;
}

/**
 * route の**生の** `action['middleware']` を列として取り出す。
 *
 * `gatherMiddleware()` / `Router::resolveMiddleware()` は使わない。alias の解決・group の展開・
 * 重複の畳み込みを挟むと「焼き込みの入力が同じ」を見たことにならないためである。
 * 集合化も sort もしない (順序と重複をそのまま比較する)。
 *
 * @return list<string>
 */
function routeCacheBakedRawMiddleware(mixed $action): array
{
    if (! is_array($action)) {
        return [];
    }

    $middleware = $action['middleware'] ?? [];

    if (is_string($middleware)) {
        return [$middleware];
    }

    if (! is_array($middleware)) {
        return [];
    }

    $normalized = [];
    foreach ($middleware as $entry) {
        $normalized[] = is_string($entry) ? $entry : var_export($entry, true);
    }

    return $normalized;
}

/**
 * `RouteCacheCommand::handle()` と**同じ順序**を、実アプリの経路一覧に触れずに通す。
 *
 * 1. 対象 route の**複製**を新しい経路一覧へ入れる (実アプリ側を壊さないため)
 * 2. 複製 1 本ずつに `prepareForSerialization()` を掛ける
 * 3. その経路一覧を `compile()` する
 *
 * @return array{compiled: mixed, attributes: array<string, mixed>}
 */
function routeCacheBakedCompile(): array
{
    $collection = new RouteCollection;

    foreach (routeCacheBakedTargetRoutes() as $route) {
        $collection->add(clone $route);
    }

    $collection->refreshNameLookups();
    $collection->refreshActionLookups();

    foreach ($collection as $route) {
        $route->prepareForSerialization();
    }

    /** @var array{compiled: mixed, attributes: array<string, mixed>} $compiled */
    $compiled = $collection->compile();

    return $compiled;
}

/**
 * compile 結果の `attributes` から、route の生の middleware 列を取り出す。
 *
 * @param  array<string, mixed>  $attributes
 * @return list<string>
 */
function routeCacheBakedAttributeMiddleware(array $attributes, string $name): array
{
    // ★`toHaveKey()` の第 2 引数は期待値であってメッセージではないため、bool へ落として理由を書く。
    expect(array_key_exists($name, $attributes))->toBeTrue("compile 結果に route [{$name}] がありません");

    $entry = $attributes[$name];
    expect($entry)->toBeArray();

    return routeCacheBakedRawMiddleware(is_array($entry) ? ($entry['action'] ?? null) : null);
}

/*
 * 3-0: 検査 1 の前提。`attributes` は route 名をキーにするため、同名の route があると
 *      後勝ちで潰れて比較そのものが意味を失う。静かに緑にならないよう先に表明する。
 */
test('比較対象の route 名が重複していない (検査 1 の前提)', function (): void {
    $names = array_map(
        static fn (IlluminateRoute $route): string => (string) $route->getName(),
        routeCacheBakedTargetRoutes(),
    );

    $duplicates = array_values(array_unique(array_diff_assoc($names, array_unique($names))));

    expect($duplicates)->toBe([], implode("\n", [
        '同名の route が複数あります (compile() の attributes は名前キーなので後勝ちで潰れます):',
        '  '.implode("\n  ", $duplicates),
    ]));

    expect(count($names))->toBeGreaterThan(100, '母集団が小さすぎます (走査が空振りしていないかを確認すること)');
});

/*
 * 3-1: 検査 1 の本体。起動時に後付けした列が、直列化の準備を通しても
 *      焼き込みの入力 (compile() の attributes) へ**欠落なく**写ること。
 */
test('複製へ直列化の準備を掛けてから compile() しても middleware 列が元と厳密一致する', function (): void {
    $expected = [];
    foreach (routeCacheBakedTargetRoutes() as $route) {
        $expected[(string) $route->getName()] = routeCacheBakedRawMiddleware($route->getAction());
    }

    $compiled = routeCacheBakedCompile();

    $actual = [];
    foreach (array_keys($expected) as $name) {
        $actual[$name] = routeCacheBakedAttributeMiddleware($compiled['attributes'], $name);
    }

    expect($actual)->toBe($expected, implode("\n", [
        '直列化の準備 → compile() を通すと middleware 列が変わりました。',
        'cached 運用の保護は焼き込まれた列そのものなので、ここでの欠落・並び替えは',
        'そのまま本番の無音の無防備になります。',
    ]));
});

/*
 * 3-2: 検査 1 の隔離の証明。複製で隔離できていない場合、実アプリの経路一覧を
 *      壊しながら緑になっている可能性が残る。
 */
test('compile() の後も元の route の middleware 列が 1 つも変わっていない', function (): void {
    $before = [];
    foreach (routeCacheBakedTargetRoutes() as $route) {
        $before[(string) $route->getName()] = routeCacheBakedRawMiddleware($route->getAction());
    }

    routeCacheBakedCompile();

    $after = [];
    foreach (routeCacheBakedTargetRoutes() as $route) {
        $after[(string) $route->getName()] = routeCacheBakedRawMiddleware($route->getAction());
    }

    expect($after)->toBe($before, '複製での隔離が効いておらず、実アプリの経路一覧が書き換わりました');
});

/*
 * 3-3: 検査 1 の負のコントロール。後付けの 5 系統がそれぞれ代表 route の
 *      attributes に現れることを、**route 名を名指しして**確かめる
 *      (アプリ全体のどこかに throttle があれば成立する、という空振りを避ける)。
 *      件数の網羅は既存の目録テストの担当であり、ここでは重複させない。
 */
test('後付けの 5 系統が代表 route の焼き込み入力に現れる (空振り green の排除)', function (string $routeName, string $alias, bool $prefixMatch): void {
    $compiled = routeCacheBakedCompile();
    $middleware = routeCacheBakedAttributeMiddleware($compiled['attributes'], $routeName);

    $found = false;
    foreach ($middleware as $entry) {
        if ($prefixMatch ? str_starts_with($entry, $alias) : $entry === $alias) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue(implode("\n", [
        "route [{$routeName}] の焼き込み入力に [{$alias}] がありません。",
        '実際の列: '.implode(', ', $middleware),
        '後付けが走らなくなった (= cached 生成時に焼き込まれなくなった) 可能性があります。',
    ]));
})->with([
    'recent-auth (2FA 秘密 GET)' => ['two-factor.secret-key', 'recent-auth', false],
    'recent-auth.on-email-change (プロフィール更新)' => ['user-profile-information.update', 'recent-auth.on-email-change', false],
    'ensure-login-method (passkey 削除)' => ['passkey.destroy', 'ensure-login-method', false],
    'no-store (passkey ログイン options)' => ['passkey.login-options', 'no-store', false],
    'throttle (passkey 削除)' => ['passkey.destroy', 'throttle:', true],
    'throttle (2FA 秘密 GET)' => ['two-factor.secret-key', 'throttle:', true],
    'throttle (Stripe webhook)' => ['cashier.webhook', 'throttle:', true],
]);

/*
 * 3-4: 付与順の契約が焼き込み入力でも崩れないこと。
 *      throttle を先に置くのは、priority 適用後も ThrottleRequests が RequireRecentAuth より
 *      前になるようにするため (逆順だと stale なリクエストでも User 行ロックを取りに行く)。
 */
test('passkey 削除 route で 3 つの alias の相対順序が保たれる', function (): void {
    $compiled = routeCacheBakedCompile();
    $middleware = routeCacheBakedAttributeMiddleware($compiled['attributes'], 'passkey.destroy');

    $throttle = null;
    foreach ($middleware as $index => $entry) {
        if (str_starts_with($entry, 'throttle:')) {
            $throttle = $index;
            break;
        }
    }

    $recentAuth = array_search('recent-auth', $middleware, true);
    $ensure = array_search('ensure-login-method', $middleware, true);

    expect($throttle)->toBeInt('throttle が焼き込み入力にありません: '.implode(', ', $middleware));
    expect($recentAuth)->toBeInt('recent-auth が焼き込み入力にありません: '.implode(', ', $middleware));
    expect($ensure)->toBeInt('ensure-login-method が焼き込み入力にありません: '.implode(', ', $middleware));

    expect($throttle)->toBeLessThan($recentAuth, 'throttle は recent-auth より前でなければならない');
    expect($recentAuth)->toBeLessThan($ensure, 'recent-auth は ensure-login-method より前でなければならない');
});

/*
 * 3-5 / 3-6: 検査 2 (剥落の実証)。**1 テスト 1 シナリオ**で、差し替えは
 *   そのテストの**最後の操作**にする。`setCompiledRoutes()` は Router の持ち物だけでなく
 *   容器の `routes` 束縛も張り替え、それを見ている URL 生成器も付いてくるため、
 *   元へ戻す形は採らない。テスト間の隔離は Laravel が各テストでアプリを作り直すことに依る
 *   (これは全テストが既に依っている既定であり、本テスト固有の仮定ではない)。
 *   テストの途中でアプリを作り直さない (RefreshDatabase の接続ごと道連れになる)。
 */
test('保護が載った compiled 経路一覧では鮮度切れの 2FA 秘密 GET が 409 で秘密を返さない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $compiled = routeCacheBakedCompile();

    // 自己証明 (1): 差し替えが実際に効いていること
    routeCacheBakedRouter()->setCompiledRoutes($compiled);
    expect(routeCacheBakedRouter()->getRoutes())->toBeInstanceOf(CompiledRouteCollection::class);

    // 自己証明 (2): 差し替えた経路一覧の側に recent-auth が載っていること
    $swapped = routeCacheBakedRouter()->getRoutes()->getByName('two-factor.secret-key');
    expect($swapped)->toBeInstanceOf(IlluminateRoute::class);
    expect(routeCacheBakedRawMiddleware($swapped?->getAction()))->toContain('recent-auth');

    // 自己証明 (3): HTTP 要求は差し替えの**後**に初めて実行する
    $this->actingAs($user)
        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonMissingPath('secretKey');
});

test('recent-auth を 1 本抜いた compiled 経路一覧では同じ要求が 200 になる (剥落の実測)', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $compiled = routeCacheBakedCompile();

    $before = routeCacheBakedAttributeMiddleware($compiled['attributes'], 'two-factor.secret-key');
    $position = array_search('recent-auth', $before, true);
    expect($position)->toBeInt('剥がす対象の recent-auth が焼き込み入力にありません');

    $after = $before;
    array_splice($after, (int) $position, 1);

    // 抜けていないのに 200 になった、という取り違えを防ぐ
    expect(count($after))->toBe(count($before) - 1);
    expect($after)->not->toContain('recent-auth');

    /** @var array<string, mixed> $attributes */
    $attributes = $compiled['attributes'];
    /** @var array<string, mixed> $entry */
    $entry = $attributes['two-factor.secret-key'];
    /** @var array<string, mixed> $action */
    $action = $entry['action'];
    $action['middleware'] = $after;
    $entry['action'] = $action;
    $attributes['two-factor.secret-key'] = $entry;
    $compiled['attributes'] = $attributes;

    // 自己証明 (1)(2): 差し替えが効いており、recent-auth が確かに 1 本減っていること
    routeCacheBakedRouter()->setCompiledRoutes($compiled);
    expect(routeCacheBakedRouter()->getRoutes())->toBeInstanceOf(CompiledRouteCollection::class);

    $swapped = routeCacheBakedRouter()->getRoutes()->getByName('two-factor.secret-key');
    expect($swapped)->toBeInstanceOf(IlluminateRoute::class);
    $swappedMiddleware = routeCacheBakedRawMiddleware($swapped?->getAction());
    expect($swappedMiddleware)->not->toContain('recent-auth');
    expect(count($swappedMiddleware))->toBe(count($before) - 1);

    // 自己証明 (3): HTTP 要求は差し替えの**後**に初めて実行する。
    // 本文の形には踏み込まない (Fortify の応答表現の変更に脆くしないため)。
    $this->actingAs($user)
        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
        ->assertOk();
});
