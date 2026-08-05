<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureLoginMethodRemains;
use App\Http\Middleware\NoStoreResponse;
use App\Http\Middleware\RequireRecentAuth;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;

/*
 * passkey route の middleware 構成を列挙で固定する。
 *
 * passkey route は **vendor (Fortify) が登録**し、アプリ側は
 * PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes() が booted 後に後付けする。
 * 後付けは「配線が消えても route は生き続ける」壊れ方をするため、構成を機械的に固定する。
 */

/** @return array<string, list<string>> route 名 => 必須 middleware (alias 文字列) */
function passkeyRouteMiddlewareInventory(): array
{
    return [
        // guest。challenge を載せるため no-store を後付けする
        // (NoStoreCacheHeadersForAuthenticatedPages は認証済みのみが対象)
        'passkey.login-options' => ['web', 'guest:web', 'throttle:passkeys', 'no-store'],
        'passkey.login' => ['web', 'guest:web', 'throttle:passkeys'],
        // step-up satisfier。recent-auth は課さない (これ自体が satisfier のため)
        'passkey.confirm-options' => ['web', 'auth:web', 'throttle:passkeys'],
        'passkey.confirm' => ['web', 'auth:web', 'throttle:passkeys'],
        // credential 集合を増やす管理経路
        'passkey.registration-options' => ['web', 'auth:web', 'throttle:passkeys', 'recent-auth'],
        'passkey.store' => ['web', 'auth:web', 'throttle:passkeys', 'recent-auth'],
        // credential 集合を減らす管理経路 (手段保持 guard つき)
        'passkey.destroy' => ['web', 'auth:web', 'recent-auth', 'ensure-login-method'],
    ];
}

function passkeyRoute(string $name): RoutingRoute
{
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $route = $routes->getByName($name);

    expect($route)->not->toBeNull("route '{$name}' が存在しない");

    return $route;
}

test('passkey route の middleware 構成が inventory と一致する', function (): void {
    foreach (passkeyRouteMiddlewareInventory() as $name => $expected) {
        $actual = passkeyRoute($name)->gatherMiddleware();

        foreach ($expected as $middleware) {
            // toContain は可変長 needle を取るため、メッセージ付きの表明は in_array で行う
            expect(in_array($middleware, $actual, true))->toBeTrue(
                "route '{$name}' に middleware '{$middleware}' が付与されていない (実際: ".implode(', ', $actual).')',
            );
        }
    }
});

test('passkey route に password.confirm が付いていない (generic recent-auth へ統一済み)', function (): void {
    foreach (array_keys(passkeyRouteMiddlewareInventory()) as $name) {
        expect(in_array('password.confirm', passkeyRoute($name)->gatherMiddleware(), true))
            ->toBeFalse("route '{$name}' に password.confirm が復活している");
    }
});

/*
 * **実行順**: recent-auth を先に通し、その後で手段保持を検査する。
 * 逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
 * alias 文字列だけでなく **解決後のクラス列** ($middlewarePriority による並べ替え込み) で見る。
 */
test('passkey.destroy は recent-auth が ensure-login-method より先に走る', function (): void {
    /** @var Router $router */
    $router = app('router');
    $resolved = $router->gatherRouteMiddleware(passkeyRoute('passkey.destroy'));

    $recentAuthIndex = array_search(RequireRecentAuth::class, $resolved, true);
    $loginMethodIndex = array_search(EnsureLoginMethodRemains::class, $resolved, true);

    expect($recentAuthIndex)->not->toBeFalse('RequireRecentAuth が解決後の middleware 列に無い');
    expect($loginMethodIndex)->not->toBeFalse('EnsureLoginMethodRemains が解決後の middleware 列に無い');
    expect($recentAuthIndex)->toBeLessThan(
        $loginMethodIndex,
        'recent-auth より先に ensure-login-method が走ると stale なリクエストでも User 行ロックを取る',
    );
});

test('passkey.login-options の no-store は解決後も NoStoreResponse に落ちる', function (): void {
    /** @var Router $router */
    $router = app('router');
    $resolved = $router->gatherRouteMiddleware(passkeyRoute('passkey.login-options'));

    expect($resolved)->toContain(NoStoreResponse::class);
});
