<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * `{organization}` を持つ route の handler は **route parameter を宣言順に受ける**
 * (家系裁定 AG-037)。
 *
 * ## なぜ必要か (実測事故)
 *
 * framework は route parameter を **位置で** handler の引数へ割り当てる
 * (`RouteDependencyResolverTrait::resolveMethodDependencies` はクラス型を差し込んだ後、
 *  残りを `array_values($routeParameters)` から順に埋める)。したがって組織 URL 配下の
 * handler が `{organization}` を受けない・順序が違うと、**引数が 1 つずつずれる**。
 *
 * 実測では通知の既読化 (`notifications.read`) が `string $notification` に Organization を
 * 受け取り、通知が見つからず 404 になっていた。**型が合わないのに例外にならない**ため、
 * 失敗は「なぜか 404」という形でしか現れない。
 *
 * ## 判定
 *
 * handler の引数のうち **route parameter と同じ名前のもの**を宣言順に取り出し、
 * それが route parameter の並びの**先頭からの連続した並び (prefix)** であることを求める。
 *  - `{organization}` を受けていなければ不一致になる (先頭が合わない)
 *  - 途中の parameter を飛ばしても不一致になる (`[organization, manual]` は prefix ではない)
 *  - 順序が違っても不一致になる (位置ずれそのもの)
 * **部分列では足りない**: 途中を飛ばすと、飛ばした parameter の値が次の引数へ入る。
 * closure route も同じ resolution を通るので**同じ検査を掛ける**。
 *
 * ## 保証しないもの
 *
 * - 引数の**型**は見ない (binding が Organization を返すことは binder 側の契約)。
 * - route parameter と無関係な名前の引数 (DI で解決されるもの) は数えない。
 * - `{organization}` を持たない route は母集団に入らない (本検査は組織セグメントの
 *   導入で全業務 route が 1 つずれたことへの回帰固定である)。
 */

/**
 * handler の引数のうち route parameter と同名のものを宣言順に返す。
 *
 * @param  list<string>  $routeParameters
 * @return list<string>
 */
function organizationRouteHandlerParameterNames(ReflectionFunctionAbstract $handler, array $routeParameters): array
{
    $names = [];
    foreach ($handler->getParameters() as $parameter) {
        if (in_array($parameter->getName(), $routeParameters, true)) {
            $names[] = $parameter->getName();
        }
    }

    return $names;
}

test('{organization} を持つ route の handler は route parameter を宣言順に受ける', function (): void {
    $routes = Route::getRoutes();
    $routes->refreshNameLookups();

    $population = 0;
    $violations = [];

    /** @var RoutingRoute $route */
    foreach ($routes as $route) {
        $parameters = $route->parameterNames();
        if (! in_array('organization', $parameters, true)) {
            continue;
        }

        $action = $route->getAction('uses');
        try {
            $handler = $action instanceof Closure
                ? new ReflectionFunction($action)
                : (function (string $uses): ReflectionFunctionAbstract {
                    [$class, $method] = str_contains($uses, '@')
                        ? explode('@', $uses, 2)
                        : [$uses, '__invoke'];

                    return new ReflectionMethod($class, $method);
                })(is_string($action) ? $action : '');
        } catch (ReflectionException $exception) {
            // 解決できない形は落とす (fail-closed)
            $violations[] = ($route->getName() ?? $route->uri()).' -> handler を解決できません: '
                .$exception->getMessage();

            continue;
        }

        $population++;
        $declared = organizationRouteHandlerParameterNames($handler, $parameters);
        // route parameter の並びの**先頭から**同じ本数を取る (prefix 一致を求める)
        $expected = array_slice($parameters, 0, count($declared));

        if ($declared === [] || $declared !== $expected) {
            $violations[] = ($route->getName() ?? $route->uri())
                .' -> handler の引数 ['.implode(', ', $declared).'] が route parameter ['
                .implode(', ', $parameters).'] の並びと一致しません';
        }
    }

    // 母集団が空なら走査が壊れている (組織 route は多数あるのが前提)
    expect($population)->toBeGreaterThan(20);

    sort($violations);
    expect($violations)->toBe([]);
});

test('負例: 欠落・中間の飛ばし・順序違いのいずれも検出できる (検出力の裏取り)', function (): void {
    $parameters = ['organization', 'project', 'manual'];

    /** @var array<string, ReflectionFunction> $handlers */
    $handlers = [
        // 正例: 先頭から連続して受けている
        'ok-all' => new ReflectionFunction(
            static fn (Request $request, string $organization, int $project, int $manual): string => $organization.$project.$manual,
        ),
        // 正例: 先頭から連続していれば途中で打ち切ってよい (残りは framework が使わない)
        'ok-prefix' => new ReflectionFunction(
            static fn (Request $request, string $organization, int $project): string => $organization.$project,
        ),
        // 負例 1: organization を受けていない (欠落)
        'missing-organization' => new ReflectionFunction(
            static fn (Request $request, int $project, int $manual): string => $project.$manual,
        ),
        // 負例 2: 中間を飛ばした (project の値が manual へ入る)
        'skips-middle' => new ReflectionFunction(
            static fn (Request $request, string $organization, int $manual): string => $organization.$manual,
        ),
        // 負例 3: 順序が違う
        'reordered' => new ReflectionFunction(
            static fn (Request $request, int $project, string $organization): string => $organization.$project,
        ),
    ];

    $violates = static function (ReflectionFunction $handler) use ($parameters): bool {
        $declared = organizationRouteHandlerParameterNames($handler, $parameters);

        return $declared === [] || $declared !== array_slice($parameters, 0, count($declared));
    };

    expect($violates($handlers['ok-all']))->toBeFalse();
    expect($violates($handlers['ok-prefix']))->toBeFalse();
    expect($violates($handlers['missing-organization']))->toBeTrue();
    expect($violates($handlers['skips-middle']))->toBeTrue();
    expect($violates($handlers['reordered']))->toBeTrue();
});
