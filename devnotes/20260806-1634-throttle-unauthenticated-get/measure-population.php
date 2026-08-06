<?php

declare(strict_types=1);

/*
 * ThrottleCoverageInventoryTest の保護対象群 (S1 ∪ S2 ∪ S3) の件数を、
 * 「S3 から $isMutating を外す前 / 後」で比較する一時計測スクリプト。
 *
 * 設計時の実測 (devnotes/20260806-1634-throttle-unauthenticated-get/conceptual-design.md §2-2) を
 * 実装者が再現するためのもの。**恒久 scripts/ へ昇格させない** (AGENTS.md: 一時スクリプトは devnotes へ)。
 *
 * 使い方 (リポジトリルートから):
 *   APP_ENV=testing php devnotes/20260806-1634-throttle-unauthenticated-get/measure-population.php
 *
 * 期待 (設計時点): 現行母集団 47 / 拡張後母集団 70 / 増分 23 (うち 4 本は既に throttle 済み)。
 */

use App\Support\Http\RouteThrottleBinder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$router = Route::getFacadeRoot();

/** 解決後 middleware 列に指定クラスがあるか (テスト側 throttleCoverageHasMiddlewareClass と同じ判定)。 */
$hasMiddleware = static function (RoutingRoute $route, string $class) use ($router): bool {
    foreach ($router->gatherRouteMiddleware($route) as $entry) {
        if (is_string($entry) && is_a(Str::before($entry, ':'), $class, true)) {
            return true;
        }
    }

    return false;
};

/** route の inventory ラベル (テスト側 throttleCoverageRouteLabel と同じ規則)。 */
$label = static function (RoutingRoute $route): string {
    $name = $route->getName();
    if ($name !== null && $name !== '') {
        return $name;
    }

    return implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' /'.$route->uri();
};

$mutating = ['POST', 'PUT', 'PATCH', 'DELETE'];
$pattern = '#^(login|logout|register|password\.|user-password\.|two-factor\.|passkey\.|verification\.'
    .'|recent-auth\.|invitations\.|settings\.password\.|social\.|filament\.admin\.auth\.)#';

$current = [];
$expanded = [];

foreach (Route::getRoutes() as $route) {
    $isMutating = array_intersect($mutating, $route->methods()) !== [];
    $uri = $route->uri();
    $name = $route->getName() ?? '';

    $s1 = $isMutating && ! $hasMiddleware($route, Authenticate::class);
    $s2 = (str_starts_with($uri, 'api/') || str_starts_with($uri, 'oauth/') || str_starts_with($uri, '.well-known/oauth-'))
        && ! $hasMiddleware($route, StartSession::class);
    $s3Current = $isMutating && $name !== '' && preg_match($pattern, $name) === 1;
    $s3Expanded = $name !== '' && preg_match($pattern, $name) === 1;

    if ($s1 || $s2 || $s3Current) {
        $current[$label($route)] = true;
    }

    if ($s1 || $s2 || $s3Expanded) {
        $entries = RouteThrottleBinder::throttleEntries($router, $route);
        $expanded[$label($route)] = [
            'methods' => implode('|', array_diff($route->methods(), ['HEAD'])),
            'auth' => $hasMiddleware($route, Authenticate::class) ? 'auth' : 'PUBLIC',
            'throttle' => $entries === [] ? '-' : implode(',', $entries),
        ];
    }
}

$added = array_diff_key($expanded, $current);

echo 'APP_ENV='.$app['env'].PHP_EOL;
echo '現行母集団 (S3 に $isMutating あり): '.count($current).PHP_EOL;
echo '拡張後母集団 (S3 から $isMutating を除去): '.count($expanded).PHP_EOL;
echo '増分: '.count($added).PHP_EOL.PHP_EOL;

$alreadyThrottled = 0;
foreach ($added as $name => $info) {
    if ($info['throttle'] !== '-') {
        $alreadyThrottled++;
    }
    printf("%-62s %-6s %-7s %s\n", $name, $info['methods'], $info['auth'], $info['throttle']);
}

echo PHP_EOL;
echo '増分のうち既に throttle 済み: '.$alreadyThrottled.PHP_EOL;
echo '増分のうち分類が要る (貼る or 免除): '.(count($added) - $alreadyThrottled).PHP_EOL;
