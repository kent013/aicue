<?php

declare(strict_types=1);

/*
 * 設計用の read-only プローブ: 退会取消 route (救済経路) の resolve 済み middleware を列挙する。
 * DB には一切触れない (Router のみ)。実行: php devnotes/20260811-0146-blocked-action-context/probe-middleware.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** @var Illuminate\Routing\Router $router */
$router = $app->make('router');
$routes = $router->getRoutes();
$routes->refreshNameLookups();

foreach (['settings.account.deletion-request.destroy', 'settings.account.deletion-request.store'] as $name) {
    $route = $routes->getByName($name);
    if ($route === null) {
        echo "MISSING: {$name}\n";

        continue;
    }
    echo "=== {$name} ===\n";
    foreach ($router->gatherRouteMiddleware($route) as $middleware) {
        echo '  '.(is_string($middleware) ? $middleware : get_debug_type($middleware))."\n";
    }
}
