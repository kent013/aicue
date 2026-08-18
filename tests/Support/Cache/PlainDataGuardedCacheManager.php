<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Arr;

/**
 * すべての cache driver を PlainDataGuardedRepository で包むテスト用 CacheManager。
 *
 * vendor の組み込み driver 生成 (`createArrayDriver()` 等) はいずれも `repository()` を
 * 通るため、ここ 1 箇所の override で array / database / file いずれにも guard が効く。
 * `Cache::extend()` の独自 creator は `repository()` を通らない
 * (tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が実証する)。
 * よって静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php の L4) が
 * `Cache::extend()` を **通常経路 0 件 + GuardedBoundaryProbe の自己テストの exact-fit** で
 * pin して口を塞いでいる。
 *
 * **本クラスは Illuminate\Contracts\Cache\Store を参照してよい唯一のサイトである**
 * (vendor 互換シグネチャの要求)。`$store` は
 * `new PlainDataGuardedRepository($store, ...)` の第 1 引数以外に現れてはならず、
 * その構造条件は同 gate の L4c が機械検査する (store を外へ流出させると受け皿を迂回できる)。
 */
final class PlainDataGuardedCacheManager extends CacheManager
{
    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $config
     * @return PlainDataGuardedRepository
     */
    public function repository(Store $store, array $config = [])
    {
        $repository = new PlainDataGuardedRepository($store, Arr::only($config, ['store']));

        // vendor CacheManager::repository() と同じ event dispatcher 設定を再現する。
        if ($config['events'] ?? true) {
            $this->setEventDispatcher($repository);
        }

        return $repository;
    }
}
