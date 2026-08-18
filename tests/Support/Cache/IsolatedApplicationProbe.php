<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * 第 2 のアプリを **Tests\TestCase::createApplication() と同じ結線経路**で組み立て、
 * コンテナと facade の状態を必ず元へ戻す。
 *
 * 起動 (bootstrap) 中の書き込みを実行時層が捕まえることを固定するには、
 * 起動が失敗しても走り続けられる**別のアプリ**が要る (通常のテスト用アプリへ
 * 違反する provider を足すと bootstrap 中に落ちてテスト本体へ到達しない)。
 *
 * 退避と復元の順序 (固定):
 *   退避: Container::getInstance() → Facade::getFacadeApplication()
 *   復元 (finally): Facade::clearResolvedInstances() → Facade::setFacadeApplication(退避値)
 *         → Container::setInstance(退避値) → PlainDataCacheGuard::reset()
 *
 * ★戻すのは「**第 2 アプリの解決済みインスタンスを残さず、元の Application から
 *   再解決できる状態**」であって、元の解決済みインスタンスそのものではない
 *   (facade の解決済みインスタンスは消去して遅延再解決に任せる)。
 */
final class IsolatedApplicationProbe
{
    /**
     * @template TReturn
     *
     * @param  Closure(Application): TReturn  $callback
     * @return TReturn
     */
    public static function run(Closure $callback): mixed
    {
        $container = Container::getInstance();
        $facadeApplication = Facade::getFacadeApplication();

        try {
            $app = require Application::inferBasePath().'/bootstrap/app.php';
            if (! $app instanceof Application) {
                throw new RuntimeException(
                    'bootstrap/app.php が Application を返しませんでした: '.get_debug_type($app)
                );
            }

            // ★ここが結線点。Tests\TestCase::createApplication() と**同じ関数**を
            //   bootstrap() より前に呼ぶ (CacheGuardWiringGateTest が同一性を pin する)。
            PlainDataCacheGuard::registerBeforeBootstrap($app);

            $app->register(BootTimeCacheWriteProbeProvider::class);

            $app->make(Kernel::class)->bootstrap();

            return $callback($app);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($facadeApplication);
            Container::setInstance($container);
            PlainDataCacheGuard::reset();
        }
    }
}
