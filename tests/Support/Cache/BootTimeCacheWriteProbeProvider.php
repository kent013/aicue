<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use stdClass;
use Throwable;

/**
 * 起動 (bootstrap) 中の書き込みを実行時層が捕まえることを固定するための見本 provider。
 *
 * `boot()` で意図的にオブジェクトをキャッシュへ入れ、**自分で例外を握り潰す**。
 * 握り潰しても accumulator に記録が残ることを
 * tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が確認する。
 * `catch` を消すと bootstrap 自体が例外になって別の理由で赤くなる (どちらでも赤い)。
 *
 * ★この provider は `IsolatedApplicationProbe` が組み立てる**第 2 のアプリ**にだけ登録する。
 *   通常のテスト用アプリへ足すと bootstrap 中に落ちてテスト本体へ到達しない。
 */
final class BootTimeCacheWriteProbeProvider extends ServiceProvider
{
    /** 起動中に意図的な違反を書き込むキー。 */
    public const string PROBE_KEY = 'cache-guard-boot-probe';

    public function boot(): void
    {
        try {
            Cache::put(self::PROBE_KEY, new stdClass, 60);
        } catch (Throwable) {
            // 意図的に握り潰す (アプリ側の try/catch fallback の再現)
        }
    }
}
