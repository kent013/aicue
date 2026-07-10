<?php

declare(strict_types=1);

use App\Http\Middleware\RequireRecentAuth;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;

/*
 * 機微操作 route に recent-auth middleware が付与されていることを CI で担保する (付与漏れ検出)。
 * 新たな機微操作 route を追加した PR は本 allowlist の更新を PR review で判断すること。
 */

/**
 * @return list<string>
 */
function recentAuthRequiredRouteNames(): array
{
    return [
        // API キー (発行 / 失効)
        'organizations.api-keys.store',
        'organizations.api-keys.revoke',
        // OAuth セッション失効 (組織管理経路。API キー失効と同じ機微度)
        'organizations.api-keys.sessions.revoke',
        // アカウント削除
        'settings.account.destroy',
        // オーナー移譲
        'organizations.transfer-ownership',
        // 組織の 2FA 必須方針トグル (Owner 専権のセキュリティ方針変更)
        'organizations.two-factor-requirement.update',
        // メンバー 2FA リセット (アカウント全体の第二要素を外す機微操作)
        'organizations.members.two-factor.reset',
    ];
}

function routeHasRecentAuth(RoutingRoute $route): bool
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }
        // alias 'recent-auth' / 'recent-auth:param' / 完全クラス名のいずれかを許容 (堅牢化)
        if ($middleware === RequireRecentAuth::class || str_starts_with($middleware, 'recent-auth')) {
            return true;
        }
    }

    return false;
}

test('機微操作 route 全件に recent-auth middleware が付与されている', function (): void {
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    foreach (recentAuthRequiredRouteNames() as $name) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' が存在しない (allowlist の更新漏れ?)");
        expect(routeHasRecentAuth($route))->toBeTrue("route '{$name}' に recent-auth middleware が付与されていない (付け忘れ)");
    }
});
