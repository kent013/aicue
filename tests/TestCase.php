<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Override;
use RuntimeException;
use Tests\Support\Cache\PlainDataCacheGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase 後に DatabaseSeeder (Role 等の参照データ) を流す。
     */
    protected bool $seed = true;

    /**
     * アプリを生成する。**bootstrap の直前**にキャッシュ guard を結線するために override する。
     *
     * ★Pest の beforeEach では遅い。起動 (bootstrap) 中の書き込みは、vendor 由来だと
     *   静的層の走査根 (app / routes / database / tests) にも入らないため、
     *   結線が遅れると 2 層とも沈黙する穴になる。
     *
     * ★本体は vendor (Illuminate\Foundation\Testing\TestCase::createApplication()) の
     *   写しであり、**guard の結線 1 行と戻り値の fail-closed 確認だけを足している**。
     *   vendor 側が変わったら tests/Architecture/CacheGuardWiringGateTest.php の
     *   W5 / W5b (期待 token 列の完全一致) が赤くなるので、そのとき写し直す。
     */
    #[Override]
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        if (! $app instanceof Application) {
            throw new RuntimeException('bootstrap/app.php が Application を返しませんでした');
        }

        PlainDataCacheGuard::registerBeforeBootstrap($app);

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
