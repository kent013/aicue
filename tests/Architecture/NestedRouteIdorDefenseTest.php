<?php

declare(strict_types=1);

use App\Enums\Security\NestedRouteDefenseMode;
use Illuminate\Support\Facades\Route;
use Tests\Support\Routing\NestedRouteDefenseInventory;

/**
 * route parameter ごとの IDOR / 存在オラクル 防御の網羅性 invariant。
 *
 * 「id を URL で受ける route は、その id が必ず URL 親/テナントに属することを構造的に担保し、
 * 不整合は認可より前に 404 (403 や 302 で存在を漏らさない)」という不変条件を、各 parameter が
 * どの防御方式 (NestedRouteDefenseMode) で守っているかを deny-by-default で機械検証する。
 *
 * inventory の正本は {@see NestedRouteDefenseInventory} (TenantBoundaryOrderingTest と共有)。
 * 本テストは「分類漏れ・stale・無記名の逃げ道」を落とす役割に限定する
 * (inline guard の静的正当性は証明しない)。モードごとの**順序不変条件**は
 * tests/Architecture/TenantBoundaryOrderingTest、実挙動 (不整合→404 等) は各 Feature テスト
 * (MemberRouteExistenceOracleTest / TenantBoundaryPrecedenceTest / UrlIntegrityGuardTest /
 * OrganizationBoundaryNotFoundTest 等) が担保する。
 */
test('1+param 候補 route の全 parameter が inventory に明示分類されている (未知は fail)', function (): void {
    $inventory = NestedRouteDefenseInventory::inventory();
    $violations = [];

    foreach (NestedRouteDefenseInventory::candidates() as $route) {
        $name = $route->getName();
        if ($name === null) {
            $violations[] = '無名の param 付き route: '.$route->uri().' (name を付け inventory 登録してください)';

            continue;
        }
        if (! array_key_exists($name, $inventory)) {
            $violations[] = $name.' ('.$route->uri().') が未分類';

            continue;
        }

        foreach ($route->parameterNames() as $param) {
            if (! array_key_exists($param, $inventory[$name])) {
                $violations[] = $name.' の parameter {'.$param.'} が未分類';
            }
        }
        foreach (array_keys($inventory[$name]) as $declared) {
            if (! in_array($declared, $route->parameterNames(), true)) {
                $violations[] = $name.' の inventory に存在しない parameter {'.$declared.'} が登録されている';
            }
        }
    }

    expect($violations)->toBe([],
        '未分類の route parameter があります。NestedRouteDefenseInventory::inventory() に'
        .'parameter 単位で防御方式を登録してください (テナント親子でなければ NonResourceParameter / '
        .'PublicGlobalResource を宣言し、nonTenantReasons() に理由を書くこと)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('inventory の key は現存 named route (逆方向整合・stale 検出)', function (): void {
    $named = [];
    foreach (Route::getRoutes() as $route) {
        $n = $route->getName();
        if ($n !== null) {
            $named[$n] = true;
        }
    }

    $stale = [];
    foreach (array_keys(NestedRouteDefenseInventory::inventory()) as $key) {
        if (! isset($named[$key])) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'inventory に現存しない route 名 (削除/rename 済): '.implode(', ', $stale));
});

test('非テナントモードの宣言と理由は 1 対 1 (逃げ道を無記名で作らせない)', function (): void {
    $reasons = NestedRouteDefenseInventory::nonTenantReasons();
    $declared = [];

    foreach (NestedRouteDefenseInventory::inventory() as $routeName => $params) {
        foreach ($params as $param => $mode) {
            if ($mode->isTenantDefense()) {
                continue;
            }
            $declared[] = $routeName.'#'.$param;
        }
    }

    $missingReason = array_values(array_diff($declared, array_keys($reasons)));
    $staleReason = array_values(array_diff(array_keys($reasons), $declared));

    expect($missingReason)->toBe([],
        '非テナントモードを宣言した parameter に理由がありません: '.implode(', ', $missingReason));
    expect($staleReason)->toBe([],
        'テナント防御モードに変わった / 消えた parameter の理由が残っています: '.implode(', ', $staleReason));

    foreach ($reasons as $key => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThan(15, "{$key} の理由が空疎です");
    }
});

test('inventory の各値は NestedRouteDefenseMode', function (): void {
    foreach (NestedRouteDefenseInventory::inventory() as $params) {
        foreach ($params as $mode) {
            expect($mode)->toBeInstanceOf(NestedRouteDefenseMode::class);
        }
    }
});
