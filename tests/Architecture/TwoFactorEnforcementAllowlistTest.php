<?php

declare(strict_types=1);

use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use Illuminate\Routing\Router;

/**
 * 組織 2FA 強制ゲートの allowlist (ALLOWED_ROUTE_NAMES) の鮮度 invariant。
 *
 * allowlist は「準拠達成に必要な最小経路」の正であり、route リネーム・削除で名前が
 * 浮く (= 実は通していない) と未準拠ユーザーが 2FA を設定できなくなる。逆に理由の
 * 無いエントリは過剰許可の温床になる。実際の到達可能性 (ゲート中に 302 されない) は
 * tests/Feature/Organizations/TwoFactorEnforcementTest.php の dataset が担保する。
 */
test('allowlist の route name は全て実在する named route である', function (): void {
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    foreach (array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES) as $name) {
        expect($routes->getByName($name))
            ->not->toBeNull("allowlist の route '{$name}' が存在しない (リネーム/削除に追従していない)");
    }
});

test('allowlist の各エントリは必要理由 (非空) を持つ', function (): void {
    foreach (RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES as $name => $reason) {
        expect(trim($reason))->not->toBe('', "route '{$name}' の必要理由が空 (運用劣化)");
    }
});
