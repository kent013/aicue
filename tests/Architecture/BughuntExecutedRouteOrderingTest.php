<?php

declare(strict_types=1);

use App\Http\Middleware\BughuntExecutedRouteMiddleware;
use App\Http\Middleware\EnsureAccountNotPendingDeletion;
use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
use App\Http\Middleware\RequireActiveSubscription;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\Routing\MiddlewareShortCircuitInventory;
use Tests\Support\Routing\NestedRouteDefenseInventory;

/**
 * 実行済み route の記録器 (BughuntExecutedRouteMiddleware) の位置に関する順序不変条件 (T164)。
 *
 * 記録器の出力は「その route まで実際に到達できた」ことの証拠として使う。したがって
 * **短絡しうる middleware が記録器より後ろで走ると、遮断された要求まで実行済みに数える**
 * (例: recent-auth の 302 は session に errors を残さないため ok と誤記録される)。
 * これは本 TODO が消そうとしている偽陽性そのものなので、順序を機械的に固定する。
 *
 * 分類の正本は {@see MiddlewareShortCircuitInventory}。未分類クラスは
 * **短絡しうる (true) 側の既定**で扱うため、分類漏れが偽陰性にならない。
 *
 * 違反したときの直し方: bootstrap/app.php の「記録器より前で走る短絡 middleware」の一覧へ
 * その middleware を足す (= `prependToPriorityList(BughuntExecutedRouteMiddleware::class, $短絡)`)。
 * priority list は「載っている middleware 同士の相対順序」しか強制しないため、短絡側も
 * priority list に載せる必要がある。
 *
 * ⚠ **append 側で書かない**。`appendToPriorityList($after, $append)` は
 * `[$append => $after]` の連想配列で持つため、同じ記録器を複数の anchor で append すると
 * 後勝ちで 1 本しか残らず、直したつもりで順序が閉じない。
 */

/**
 * 解決後の middleware 列で「記録器より後ろに短絡しうる middleware がある」ものを列挙する。
 *
 * 記録器を含まない列 (api / Filament) は対象外として空を返す。
 *
 * @param  list<string>  $resolved
 * @return list<string>
 */
function bughuntRecorderOrderViolations(array $resolved): array
{
    $recorderIndex = array_search(BughuntExecutedRouteMiddleware::class, $resolved, true);
    if ($recorderIndex === false) {
        return [];
    }

    $classification = MiddlewareShortCircuitInventory::classification();
    $violations = [];
    foreach ($resolved as $index => $middleware) {
        if ($index < $recorderIndex) {
            continue;
        }
        if (($classification[$middleware] ?? true) === true) {
            $violations[] = $middleware;
        }
    }

    return $violations;
}

test('主契約: 記録器が付いた全 route で、短絡しうる middleware は記録器より前で走る', function (): void {
    $violations = [];
    $checked = 0;

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes() as $route) {
        $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
        if (! in_array(BughuntExecutedRouteMiddleware::class, $resolved, true)) {
            continue; // web グループ外 (api / Filament) は記録器を持たない
        }
        $checked++;

        foreach (bughuntRecorderOrderViolations($resolved) as $middleware) {
            // route 名は null になりうるので URI と method も出す (原因追跡のため)
            $label = $route->getName() ?? '(無名)';
            $violations[] = "{$label} [".implode('|', $route->methods())." /{$route->uri()}]: "
                ."{$middleware} が記録器より後ろで走る";
        }
    }

    $violations = array_values(array_unique($violations));
    expect($violations)->toBe([],
        '記録器より後ろで短絡すると、遮断された要求が「実行済み」と誤記録されます。'
        .'bootstrap/app.php の「記録器より前で走る短絡 middleware」の一覧へ足してください '
        .'(= prependToPriorityList(BughuntExecutedRouteMiddleware::class, $短絡middleware))。'
        .'appendToPriorityList 側で書くと、同じ記録器を複数 anchor に付けたときに後勝ちで消えます。'
        .PHP_EOL.implode(PHP_EOL, $violations));
    // 配線消失の検出 (0 件なら記録器が web グループから外れている)
    expect($checked)->toBeGreaterThan(0, '記録器が付いた route が 1 本も無い = web グループへの登録が消えている');
});

test('代表 route: 記録器は認証・テナント境界 404・課金ゲート・退会凍結より後ろにある', function (): void {
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $route = $routes->getByName('projects.update');
    expect($route)->not->toBeNull("route 'projects.update' が存在しない");

    $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
    $recorderIndex = array_search(BughuntExecutedRouteMiddleware::class, $resolved, true);
    expect($recorderIndex)->not->toBeFalse('記録器が projects.update の解決後 middleware 列に無い');

    foreach ([
        Authenticate::class,
        EnsureProjectBelongsToRouteOrganization::class,
        RequireActiveSubscription::class,
        EnsureAccountNotPendingDeletion::class,
    ] as $upstream) {
        $index = array_search($upstream, $resolved, true);
        expect($index)->not->toBeFalse("{$upstream} が projects.update の列に無い");
        expect($index)->toBeLessThan($recorderIndex, "{$upstream} が記録器より後ろで走る");
    }
});

test('負の対照: 短絡クラスが記録器より後ろにある合成の列を違反として検出する', function (): void {
    $shortCircuiting = MiddlewareShortCircuitInventory::shortCircuiting();
    expect($shortCircuiting)->not->toBe([], '短絡しうると分類された middleware が 1 つも無い');
    $shortCircuit = $shortCircuiting[0];

    // 記録器の後ろに短絡クラスを置いた合成の列 = 違反として検出されること
    expect(bughuntRecorderOrderViolations([
        BughuntExecutedRouteMiddleware::class,
        $shortCircuit,
    ]))->toBe([$shortCircuit]);

    // 前に置いた列は違反にならないこと (常に真を返す判定式でないことの対照)
    expect(bughuntRecorderOrderViolations([
        $shortCircuit,
        BughuntExecutedRouteMiddleware::class,
    ]))->toBe([]);

    // 記録器を含まない列は対象外 (api / Filament を巻き込まない)
    expect(bughuntRecorderOrderViolations([$shortCircuit]))->toBe([]);
});
