<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| org-boundary-404 invariant: `{organization}` route param は web ルート (auth + web group) 専用
|--------------------------------------------------------------------------
|
| MembershipScopedOrganizationBinder (AppServiceProvider で Route::bind 登録) は
| Auth::guard('web') = session guard に依存してテナント存在秘匿 (非メンバー 404) を担う。
| web 以外 (api / ai / webhooks / Filament) で `{organization}` param 名を使うと session
| guard 不在の binding が誤適用され、常時 404 などの誤動作になる。web 以外では別名 param
| (例: orgSlug) を使うこと。
*/

it('web 以外の route は {organization} param を使わない', function (): void {
    // binder は Auth::guard('web') 固定のため、session guard 以外 (auth:api-key 等) は
    // invariant 違反として落とす。許可するのは素の `auth` (default = web) と `auth:web` のみ。
    $hasWebSessionAuth = static function (RoutingRoute $route): bool {
        $middleware = array_filter($route->gatherMiddleware(), 'is_string');

        return in_array('web', $middleware, true)
            && (in_array('auth', $middleware, true) || in_array('auth:web', $middleware, true));
    };

    $offenders = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('organization', $route->parameterNames(), true))
        ->reject($hasWebSessionAuth);

    expect($offenders->map->uri()->values()->all())->toBe(
        [],
        '{organization} param は MembershipScopedOrganizationBinder (web session guard 依存) が'
        .'適用されるため web + auth ルート専用。web 以外では別名 param (orgSlug 等) を使うこと: '
        .$offenders->map->uri()->implode(', '),
    );
});

it('default auth guard は web (binder の Auth::guard("web") 前提)', function (): void {
    // 素の `auth` middleware を web guard とみなす上記 invariant の前提を pin する
    expect(config('auth.defaults.guard'))->toBe('web');
});

it('Organization の routeKeyName は id (field 無指定 binding = id 解決の前提)', function (): void {
    // binder は `{organization}` (field 無指定 = organizations.switch 等) を
    // getRouteKeyName() で解決する。routeKeyName が slug 等に変わると id binding 前提が静かに崩れる。
    expect((new Organization)->getRouteKeyName())->toBe('id');
});
