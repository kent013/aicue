<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\Routing\OrganizationlessWebRouteInventory;

/*
 * 業務 route は 1 本残らず組織 URL 配下にある (家系裁定 AG-037 / 不変条件 I2)。
 *
 * 「いまどの組織か」は **URL だけ**で決まる。保持列 (`users.current_organization_id`) と
 * 切替 endpoint は撤去済みで、**2 方式の併存は認めない**。したがって業務 route が
 * `{organization}` を持たないことは、その route が「どの組織か」を URL 以外から
 * 取っている (= 裁定違反) か、取れずに壊れているかのどちらかである。
 *
 * ## 走査対象
 *
 * `Route::getRoutes()` のうち **`web` middleware group を宣言し `auth` を持つ named route**。
 * 母集団が空なら fail する (走査根の改名・group 宣言の変更で空振りしても気付ける)。
 *
 * ## 判定
 *
 * deny-by-default。`{organization}` param を持たない route は
 * `OrganizationlessWebRouteInventory` に**理由 30 文字以上**で登録が要る。
 * 登録が実在 route と対応しない (= 陳腐化した登録) 場合も fail する。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - **API v1 / MCP / Filament は対象外**である (組織を API キー・consent・{record} から
 *   確定する別レイヤーであり、`web` group を宣言しない)。
 * - route が `{organization}` を持つことと、controller がそれを使っていることは別である。
 *   後者は `OrganizationRouteGenerationTest` と各 Feature テストが見る。
 */

/** @return list<RoutingRoute> */
function organizationScopedRouteCoveragePopulation(): array
{
    $routes = [];
    foreach (Route::getRoutes() as $route) {
        if ($route->getName() === null) {
            continue;
        }
        if (! in_array('web', $route->middleware(), true)) {
            continue;
        }
        if (! in_array('auth', $route->gatherMiddleware(), true)) {
            continue;
        }
        $routes[] = $route;
    }

    return $routes;
}

test('web + auth の named route は母集団として空でない', function (): void {
    expect(organizationScopedRouteCoveragePopulation())->not->toBeEmpty();
});

test('業務 route は 1 本残らず {organization} param を持つ (除外は理由付き目録のみ)', function (): void {
    $exact = OrganizationlessWebRouteInventory::exactNames();
    $prefixes = OrganizationlessWebRouteInventory::prefixes();

    $violations = [];
    $usedExact = [];
    $usedPrefixes = [];

    foreach (organizationScopedRouteCoveragePopulation() as $route) {
        $name = $route->getName();
        expect($name)->toBeString();
        if (in_array('organization', $route->parameterNames(), true)) {
            continue;
        }

        if (array_key_exists($name, $exact)) {
            $usedExact[$name] = true;

            continue;
        }

        $matched = null;
        foreach (array_keys($prefixes) as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $matched = $prefix;
                break;
            }
        }
        if ($matched !== null) {
            $usedPrefixes[$matched] = true;

            continue;
        }

        $violations[] = "{$name} ({$route->uri()}) が {organization} を持たない"
            .' (組織 URL 配下へ移すか、理由付きで OrganizationlessWebRouteInventory へ登録すること)';
    }

    expect($violations)->toBe([]);

    // 陳腐化した登録も落とす (消えた route の除外理由が残り続けない)
    expect(array_values(array_diff(array_keys($exact), array_keys($usedExact))))->toBe([]);
    expect(array_values(array_diff(array_keys($prefixes), array_keys($usedPrefixes))))->toBe([]);
});

test('除外の理由は 30 文字以上である (「なんとなく除外」を書けなくする)', function (): void {
    $short = [];
    foreach ([...OrganizationlessWebRouteInventory::exactNames(), ...OrganizationlessWebRouteInventory::prefixes()] as $key => $reason) {
        if (mb_strlen($reason) < 30) {
            $short[] = $key;
        }
    }

    expect($short)->toBe([]);
});

test('負例: {organization} を持たない業務 route を合成すると検出される', function (): void {
    Route::middleware(['web', 'auth'])
        ->get('/synthetic-business-route', fn (): string => 'ok')
        ->name('synthetic.business');
    Route::getRoutes()->refreshNameLookups();

    $names = [];
    foreach (organizationScopedRouteCoveragePopulation() as $route) {
        if (! in_array('organization', $route->parameterNames(), true)) {
            $names[] = $route->getName();
        }
    }

    expect($names)->toContain('synthetic.business');
    expect(array_key_exists('synthetic.business', OrganizationlessWebRouteInventory::exactNames()))->toBeFalse();
});
