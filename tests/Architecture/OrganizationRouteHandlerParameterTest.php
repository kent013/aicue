<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * `{organization}` を持つ route の handler は **organization 引数を受ける** (家系裁定 AG-037)。
 *
 * ## なぜ必要か (実測事故)
 *
 * framework は route parameter を **位置で** handler の引数へ割り当てる
 * (`RouteDependencyResolverTrait::resolveMethodDependencies` はクラス型を差し込んだ後、
 *  残りを `array_values($routeParameters)` から順に埋める)。したがって組織 URL 配下の
 * handler が `{organization}` を受けないと、**後続の引数が 1 つずつずれる**。
 *
 * 実測では通知の既読化 (`notifications.read`) が `string $notification` に Organization を
 * 受け取り、通知が見つからず 404 になっていた。**型が合わないのに例外にならない**
 * (Organization は `__toString()` を持たないが、そのまま検索値として渡ってしまう) ため、
 * 失敗は「なぜか 404」という形でしか現れない。
 *
 * ## 判定
 *
 * `{organization}` を持つ route の handler に `organization` という名前の引数があること。
 * **使うかどうかは問わない** — 位置ずれを防ぐことが目的なので、受けていれば足りる。
 *
 * ## 保証しないもの
 *
 * - handler が closure の route は対象外である (`app/` の外に本体があり、位置の契約も違う)。
 * - `{organization}` 以外の parameter の順序ずれは見ない (この検査は組織セグメントの導入で
 *   全業務 route が 1 つずれたことへの回帰固定である)。
 * - 引数の**型**は見ない (binding が Organization を返すことは binder 側の契約)。
 */

test('{organization} を持つ route の handler はすべて organization 引数を受ける', function (): void {
    $routes = Route::getRoutes();
    $routes->refreshNameLookups();

    $population = 0;
    $violations = [];

    /** @var RoutingRoute $route */
    foreach ($routes as $route) {
        if (! in_array('organization', $route->parameterNames(), true)) {
            continue;
        }

        $action = $route->getActionName();
        if ($action === 'Closure') {
            continue;
        }

        [$class, $method] = str_contains($action, '@')
            ? explode('@', $action, 2)
            : [$action, '__invoke'];

        if (! class_exists($class) || ! method_exists($class, $method)) {
            // 解決できない形は落とす (fail-closed)
            $violations[] = ($route->getName() ?? $route->uri()).' -> 解決できない handler: '.$action;

            continue;
        }

        $population++;
        $names = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod($class, $method))->getParameters(),
        );

        if (! in_array('organization', $names, true)) {
            $violations[] = ($route->getName() ?? $route->uri())
                ." -> {$class}::{$method}(".implode(', ', $names).')';
        }
    }

    // 母集団が空なら走査が壊れている (組織 route は多数あるのが前提)
    expect($population)->toBeGreaterThan(20);

    sort($violations);
    expect($violations)->toBe([]);
});

test('負例: organization 引数を持たない合成 handler を検出できる', function (): void {
    $withOrganization = new class
    {
        public function show(Organization $organization, string $notification): string
        {
            return $organization->slug.$notification;
        }
    };
    $withoutOrganization = new class
    {
        public function show(string $notification): string
        {
            return $notification;
        }
    };

    $names = static fn (object $handler): array => array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod($handler, 'show'))->getParameters(),
    );

    expect(in_array('organization', $names($withOrganization), true))->toBeTrue();
    expect(in_array('organization', $names($withoutOrganization), true))->toBeFalse();
});
