<?php

declare(strict_types=1);

use App\Http\Middleware\NoStoreResponse;
use App\Support\Http\RouteMiddlewareBinder;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * RouteMiddlewareBinder (vendor route への middleware alias 後付け) の契約テスト。
 *
 * 本 binder が守るのは 2 つの失敗形である:
 *   1. **無音の無防備** — route 名が消えたのに no-op して保護が外れる
 *      (= 非 cached では fail-fast する)
 *   2. **cached 起動が落ちる** — cached 起動で例外を投げると `php artisan route:list` が
 *      必ず落ちる (T120 で実際に起きた事故)。よって cached では resolver すら呼ばない
 *
 * 配置は姉妹の tests/Feature/Security/RouteThrottleBinderTest.php に揃える
 * (Router facade を使うため Feature レーン。DB は触らない)。
 */

/** テスト用の router。 */
function middlewareBinderRouter(): Router
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();

    return $router;
}

/** name index を refresh 済みの route collection。 */
function middlewareBinderRoutes(): RouteCollectionInterface
{
    $routes = middlewareBinderRouter()->getRoutes();
    $routes->refreshNameLookups();

    return $routes;
}

/** route 自身の middleware 列 (group 展開前)。 */
function middlewareBinderMiddleware(string $name): array
{
    $route = middlewareBinderRoutes()->getByName($name);
    expect($route)->not->toBeNull("route [{$name}] が登録されていない");

    return $route->middleware();
}

/** 呼ばれたら必ず落ちる resolver (到達したことを振る舞いで表明するための道具)。 */
function middlewareBinderExplodingResolver(): callable
{
    return static function (): array {
        throw new RuntimeException('resolver が呼ばれました');
    };
}

/*
 * #1 = **T120 の恒久回帰**。
 *
 * cached 起動では resolver に**到達しない**ことを表明する。resolver に到達しない
 * ⇒ route 解決にも到達しない、が 1 本で言えるため、Application の stub を作らずに
 * 「cached 起動で落ちない」の核を純粋関数へ閉じ込められる。
 */
test('routesAreCached: true では resolver すら呼ばれない (T120 恒久回帰)', function (): void {
    RouteMiddlewareBinder::attachAll(
        middlewareBinderRouter(),
        middlewareBinderExplodingResolver(),
        true,
    );
})->throwsNoExceptions();

test('routesAreCached: true では middleware が 1 本も増えない', function (): void {
    Route::post('/mw-binder-probe/cached', fn (): string => 'ok')->name('mw-binder.cached');

    $before = middlewareBinderMiddleware('mw-binder.cached');

    RouteMiddlewareBinder::attachAll(
        middlewareBinderRouter(),
        static fn (): array => ['mw-binder.cached' => ['no-store']],
        true,
    );

    expect(middlewareBinderMiddleware('mw-binder.cached'))->toBe($before);
});

test('routesAreCached: false で route が引けないと RuntimeException (メッセージに route 名を含む)', function (): void {
    RouteMiddlewareBinder::attachAll(
        middlewareBinderRouter(),
        static fn (): array => ['mw-binder.absent-route' => ['no-store']],
        false,
    );
})->throws(RuntimeException::class, 'mw-binder.absent-route');

test('alias が実効列に 1 本増える', function (): void {
    Route::post('/mw-binder-probe/plain', fn (): string => 'ok')->name('mw-binder.plain');

    expect(middlewareBinderMiddleware('mw-binder.plain'))->not->toContain('no-store');

    RouteMiddlewareBinder::attachAll(
        middlewareBinderRouter(),
        static fn (): array => ['mw-binder.plain' => ['no-store']],
        false,
    );

    $router = middlewareBinderRouter();
    $route = middlewareBinderRoutes()->getByName('mw-binder.plain');
    expect($route)->not->toBeNull();
    expect($router->gatherRouteMiddleware($route))
        ->toContain(NoStoreResponse::class);
});

test('2 回呼んでも 1 本のまま (冪等)', function (): void {
    Route::post('/mw-binder-probe/idempotent', fn (): string => 'ok')->name('mw-binder.idempotent');

    $resolver = static fn (): array => ['mw-binder.idempotent' => ['no-store']];

    RouteMiddlewareBinder::attachAll(middlewareBinderRouter(), $resolver, false);
    RouteMiddlewareBinder::attachAll(middlewareBinderRouter(), $resolver, false);

    $middleware = middlewareBinderMiddleware('mw-binder.idempotent');
    expect(array_values(array_filter($middleware, fn (string $m): bool => $m === 'no-store')))
        ->toHaveCount(1);
});

/*
 * 列の順に append することは Passkey の順序契約 (throttle → recent-auth → 手段保持) の土台。
 * ここが崩れると PasskeyRouteProtectionTest の index 比較が落ちる。
 */
test('列の順に append される', function (): void {
    Route::post('/mw-binder-probe/ordered', fn (): string => 'ok')->name('mw-binder.ordered');

    RouteMiddlewareBinder::attachAll(
        middlewareBinderRouter(),
        static fn (): array => [
            'mw-binder.ordered' => ['throttle:passkeys', 'recent-auth', 'ensure-login-method'],
        ],
        false,
    );

    $middleware = middlewareBinderMiddleware('mw-binder.ordered');
    $indexes = array_map(
        static fn (string $alias): int|false => array_search($alias, $middleware, true),
        ['throttle:passkeys', 'recent-auth', 'ensure-login-method'],
    );

    expect($indexes[0])->toBeInt();
    expect($indexes[1])->toBeInt();
    expect($indexes[2])->toBeInt();
    expect($indexes[0])->toBeLessThan($indexes[1]);
    expect($indexes[1])->toBeLessThan($indexes[2]);
});

/*
 * ★memo を破棄しなければ「middleware() には載っているのに dispatch では実行されない」
 *   = **無音の無防備**になる。本 binder が潰そうとしている失敗形そのもの。
 */
test('付与後に computedMiddleware が破棄されている', function (): void {
    Route::post('/mw-binder-probe/memoized', fn (): string => 'ok')->name('mw-binder.memoized');

    $router = middlewareBinderRouter();
    $route = middlewareBinderRoutes()->getByName('mw-binder.memoized');
    expect($route)->not->toBeNull();

    // 先に実効列を確定させる (memo を温める) = 後付け前に覗く状況の再現
    $router->gatherRouteMiddleware($route);

    RouteMiddlewareBinder::attachAll(
        $router,
        static fn (): array => ['mw-binder.memoized' => ['no-store']],
        false,
    );

    expect($router->gatherRouteMiddleware($route))
        ->toContain(NoStoreResponse::class);
});

test('変更が無いときは既存 route を壊さない', function (): void {
    Route::post('/mw-binder-probe/unchanged', fn (): string => 'ok')
        ->middleware('no-store')
        ->name('mw-binder.unchanged');

    $before = middlewareBinderMiddleware('mw-binder.unchanged');

    RouteMiddlewareBinder::attachAll(
        middlewareBinderRouter(),
        static fn (): array => ['mw-binder.unchanged' => ['no-store']],
        false,
    );

    expect(middlewareBinderMiddleware('mw-binder.unchanged'))->toBe($before);
});

/*
 * #8 / #8b = **配線 (attachOnBooted) の振る舞い固定**。
 *
 * `Illuminate\Foundation\Application::routesAreCached()` は **まず container binding
 * `routes.cached` を見る**ため、`app()->instance('routes.cached', true)` で cached 起動を
 * 再現できる (Application の stub も route cache ファイルの生成も要らない)。
 * また `Application::booted()` は `isBooted()` なら **その場で callback を発火する**ので、
 * テスト内で `attachOnBooted()` を呼べば同期的に配線が走る。
 *
 * #8 だけだと「配線が死んでいるから green」でも通ってしまうため、
 * #8b (negative control) と**必ずセットで**扱うこと。
 */
test('attachOnBooted() は cached 起動で resolver を呼ばない (配線の固定)', function (): void {
    app()->instance('routes.cached', true);

    RouteMiddlewareBinder::attachOnBooted(app(), middlewareBinderExplodingResolver());
})->throwsNoExceptions();

test('negative control: 非 cached なら attachOnBooted() は resolver を実際に呼ぶ', function (): void {
    app()->instance('routes.cached', false);

    RouteMiddlewareBinder::attachOnBooted(app(), middlewareBinderExplodingResolver());
})->throws(RuntimeException::class, 'resolver が呼ばれました');
