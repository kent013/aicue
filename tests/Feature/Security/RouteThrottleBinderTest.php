<?php

declare(strict_types=1);

use App\Support\Http\RouteThrottleBinder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * RouteThrottleBinder (vendor route への throttle 後付け) の契約テスト。
 *
 * 本 binder は「設定で貼れない vendor route」に throttle を付ける唯一の手段であり、
 * 壊れ方が **無音の無防備** (route は生きているが制限が消える) になる。
 * そのため fail-fast (route 名の消失 / 想定外 throttle / 不正形式) と
 * 冪等性 (route:cache 下の再適用) の両方を機械固定する。
 */

/** テスト用の router。 */
function binderRouter(): Router
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();

    return $router;
}

/** throttle 実効 entry (`{class}:{params}`) を取り出す。 */
function binderThrottleEntries(string $name): array
{
    $router = binderRouter();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();
    $route = $routes->getByName($name);
    expect($route)->not->toBeNull("route [{$name}] が登録されていない");

    return RouteThrottleBinder::throttleEntries($router, $route);
}

test('未登録の route 名を渡すと RuntimeException (メッセージに route 名を含む)', function (): void {
    RouteThrottleBinder::attachByName(binderRouter(), 'binder.absent-route', 'api-read');
})->throws(RuntimeException::class, 'binder.absent-route');

test('throttle 無しの route に付与すると実効列に 1 本増える', function (): void {
    Route::post('/binder-probe/plain', fn (): string => 'ok')->name('binder.plain');

    expect(binderThrottleEntries('binder.plain'))->toBe([]);

    RouteThrottleBinder::attachByName(binderRouter(), 'binder.plain', 'api-read');

    $entries = binderThrottleEntries('binder.plain');
    expect($entries)->toHaveCount(1);
    expect($entries[0])->toBe(ThrottleRequests::class.':api-read');
});

test('同じ limiter で 2 回呼んでも実効列は 1 本のまま (route:cache 下の再適用が冪等)', function (): void {
    Route::post('/binder-probe/idempotent', fn (): string => 'ok')->name('binder.idempotent');

    RouteThrottleBinder::attachByName(binderRouter(), 'binder.idempotent', 'api-read');
    RouteThrottleBinder::attachByName(binderRouter(), 'binder.idempotent', 'api-read');

    expect(binderThrottleEntries('binder.idempotent'))->toHaveCount(1);
});

test('別 limiter が既にある route へ呼ぶと RuntimeException (二重付与で実効上限が半減するため)', function (): void {
    Route::post('/binder-probe/other', fn (): string => 'ok')
        ->middleware('throttle:api-write')
        ->name('binder.other');

    RouteThrottleBinder::attachByName(binderRouter(), 'binder.other', 'api-read');
})->throws(RuntimeException::class, '想定外の throttle');

test('params なしの throttle が既にある route へ呼ぶと RuntimeException', function (): void {
    Route::post('/binder-probe/bare', fn (): string => 'ok')
        ->middleware(ThrottleRequests::class)
        ->name('binder.bare');

    RouteThrottleBinder::attachByName(binderRouter(), 'binder.bare', 'api-read');
})->throws(RuntimeException::class, '許容形式');

test('既存 entry の params が不正形式の route へ呼ぶと RuntimeException', function (): void {
    Route::post('/binder-probe/malformed', fn (): string => 'ok')
        ->middleware('throttle:6,1,9')
        ->name('binder.malformed');

    RouteThrottleBinder::attachByName(binderRouter(), 'binder.malformed', 'api-read');
})->throws(RuntimeException::class, '許容形式');

/*
 * 期待値の形式検証は **route 解決や既存 entry の有無より前**に行う。
 * 後回しにすると「throttle が 1 本も無い route への初回呼び出しでは不正形式を素通しする」
 * という非対称な穴になる (= 意図しない上限のまま公開される)。
 */
test('期待値が不正形式なら throttle が 1 本も無い route に対しても RuntimeException', function (string $limiter): void {
    Route::post('/binder-probe/bad-expect', fn (): string => 'ok')->name('binder.bad-expect');

    RouteThrottleBinder::attachByName(binderRouter(), 'binder.bad-expect', $limiter);
})->with(['6,1,9', 'Foo Bar', '', 'API-Read'])->throws(RuntimeException::class, '許容形式');

/*
 * route:cache 起動時の契約。
 *
 * framework の RouteServiceProvider は `withRouting()` が booting callback で登録するため
 * **最後に boot** され、compiled route の読み込みはさらにその中の `$app->booted()` へ積まれる。
 * つまり本 binder の booted callback が走る時点では compiled route collection がまだ無く、
 * named route を 1 本も解決できない。ここで fail-fast すると **cached 起動が必ず落ちる**ため、
 * cache 済みなら skip する (付与自体は route:cache 生成時の再 bootstrap で焼き込み済み)。
 */
test('routes が cache 済みなら attachAll は何もしない (cached 起動を落とさない)', function (): void {
    // 実在しない route 名を渡しても skip されるため例外にならない
    RouteThrottleBinder::attachAll(binderRouter(), ['binder.absent-route' => 'api-read'], true);
})->throwsNoExceptions();

test('routes が cache されていなければ attachAll は route 不在で fail-fast する', function (): void {
    RouteThrottleBinder::attachAll(binderRouter(), ['binder.absent-route' => 'api-read'], false);
})->throws(RuntimeException::class, 'binder.absent-route');

test('attachAll は cache されていなければ全 route へ付与する', function (): void {
    Route::post('/binder-probe/all-a', fn (): string => 'ok')->name('binder.all-a');
    Route::post('/binder-probe/all-b', fn (): string => 'ok')->name('binder.all-b');

    RouteThrottleBinder::attachAll(
        binderRouter(),
        ['binder.all-a' => 'api-read', 'binder.all-b' => '6,1'],
        false,
    );

    expect(binderThrottleEntries('binder.all-a'))->toBe([ThrottleRequests::class.':api-read']);
    expect(binderThrottleEntries('binder.all-b'))->toBe([ThrottleRequests::class.':6,1']);
});

/*
 * boot 中の後付けが **controller を container 解決しない**ことを固定する。
 *
 * `Route::gatherMiddleware()` は controller middleware を集めるために controller を
 * 解決する。boot 中にこれが起きると、controller が constructor injection で要求する
 * request scope の singleton (Fortify の ConfirmablePasswordController → StatefulGuard
 * → session.store) が boot 時点で確定し、その後の設定変更・request 生成に追随しなくなる
 * (実測で PasswordUpdateSessionInvalidationTest が壊れた)。
 */
test('boot 中の後付けは Fortify controller を解決していない', function (): void {
    $routes = binderRouter()->getRoutes();
    $routes->refreshNameLookups();
    $route = $routes->getByName('password.confirm.store');

    expect($route)->not->toBeNull('前提: password.confirm.store は後付け対象として存在する');
    expect($route->controller)->toBeNull(
        'boot 中の後付けが controller を解決しています'
        .' (StatefulGuard → session.store が boot 時点で確定し、request scope が壊れます)',
    );
});

test('routeThrottleEntries は group 展開後の throttle を検出する (controller は解決しない)', function (): void {
    Route::post('/binder-probe/grouped', fn (): string => 'ok')
        ->middleware(['web', 'throttle:api-read'])
        ->name('binder.grouped');

    $router = binderRouter();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();
    $route = $routes->getByName('binder.grouped');

    expect(RouteThrottleBinder::routeThrottleEntries($router, $route))
        ->toBe([ThrottleRequests::class.':api-read']);
});

test('isThrottleEntry は throttle 系 middleware だけを true にする', function (): void {
    expect(RouteThrottleBinder::isThrottleEntry(ThrottleRequests::class.':api-read'))->toBeTrue();
    expect(RouteThrottleBinder::isThrottleEntry(ThrottleRequestsWithRedis::class.':api-read'))->toBeTrue();
    expect(RouteThrottleBinder::isThrottleEntry(ThrottleRequests::class))->toBeTrue();
    expect(RouteThrottleBinder::isThrottleEntry('Illuminate\Auth\Middleware\Authenticate:web'))->toBeFalse();
    expect(RouteThrottleBinder::isThrottleEntry('throttle:api-read'))->toBeFalse();
});

/*
 * ★memoization の破棄がなければ「middleware() には載っているのに実行されない throttle」になる。
 *   Route::gatherMiddleware() は結果を memoize し、dispatch 時もその値が使われるため、
 *   後付け前に実効列を覗いた時点で memo が温まっていると付与が無効化される (無音の無防備)。
 */
test('後付けした throttle は dispatch 経路の実効列にも反映される (memoization を破棄している)', function (): void {
    Route::post('/binder-probe/memoized', fn (): string => 'ok')->name('binder.memoized');

    $router = binderRouter();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();
    $route = $routes->getByName('binder.memoized');

    // 先に実効列を確定させる (memo を温める) = 後付け前に覗く状況の再現
    $router->gatherRouteMiddleware($route);

    RouteThrottleBinder::attachByName($router, 'binder.memoized', 'api-read');

    $resolved = $router->gatherRouteMiddleware($route);
    expect($resolved)->toContain(ThrottleRequests::class.':api-read');
});
