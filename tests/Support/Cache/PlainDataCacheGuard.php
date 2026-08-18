<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * キャッシュ素データ規約の**実行時層**。テスト実行中のキャッシュ書き込みを受け皿の側で
 * 捕まえ、保管先へ渡す**前の値**を再帰検査する (家系の裁定 AG-151 = 正典 v2 の要素 2)。
 *
 * ## 2 層のうちの実行時層である
 *
 * 静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) が保証するのは
 * 「申告なしに書き込み経路を増やせない」ことだけで、目録の payload 欄は**人間の申告**である。
 * 本 guard が保証するのは「**テストが実行した書き込みの値が実際に素データである**」ことである。
 * 受け皿を包んで値を見るので、**直列化を一度も経由しない array store でも同じように発火する**。
 *
 * ## 結線はアプリ起動の**前**
 *
 * 結線点は `Tests\TestCase::createApplication()` の `bootstrap()` 直前である
 * (`registerBeforeBootstrap()`)。Pest の beforeEach では遅い — 起動 (bootstrap) 中の
 * 書き込みは、vendor 由来だと静的層の走査根 (app / routes / database / tests) にも
 * 入らないため、結線が遅れると**2 層とも沈黙する穴**になる。
 * `Illuminate\Container\Container::extend()` は binding がまだ無くても登録でき、
 * `CacheServiceProvider::register()` の `singleton('cache', …)` は extenders を消さない
 * (`bind()` の `dropStaleInstances()` が消すのは instances と aliases だけ) ので、
 * `cache` の初回解決時に必ず guard 付き manager になる。
 *
 * ## 違反は「その場で例外」と「accumulator への記録」の両方
 *
 * アプリ側の `catch (Throwable)` (準拠実装 `FxRateService` が読み書きを握り潰す形を持つ) で
 * 例外が消えても、afterEach の `flushAndFailIfStray()` で必ず赤くなる
 * (既存の `StrayHttpRequestGuard` / `StrayLlmCallGuard` と同じ設計)。
 *
 * ## 保証しないもの (**正本はここ**。AGENTS.md / docs には写さない)
 *
 * - `bootstrap/app.php` を require し終える前に走るコードからの書き込み
 *   (結線はその直後なので、起動中 = bootstrap の書き込みは**対象に入る**)
 * - **`getStore()` 経由**で保管先へ直接書く形。vendor 自身が正常系で `getStore()` を呼ぶため
 *   実行時には落とせない (`Illuminate\Cache\RateLimiter::withoutSerializationOrCompression()` /
 *   `Repository::flushLocks()` / スケジューラの排他)。ここを塞ぐのは**静的層 (L4) だけ**であり、
 *   **vendor が `getStore()` 経由で書く値は 2 層とも見えない**
 * - **保管先へ素通しさせた排他 2 語彙 (`lock` / `restoreLock`) の先**
 *   (`PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS`。排他は payload を運ばない、が根拠)
 * - **走査根の外で宣言された第三者 `Store` 実装**を直接生成する / 独自のコンテナ束縛で得る経路
 * - テストが 1 度も踏まない経路 (実行時層は実行されないものを見ない)
 * - `--parallel` の worker をまたいだ違反の集約 (accumulator はプロセス内 static)
 * - macro を**同一テスト内で登録し、使わずに、`flushMacros()` で消す**形
 *   (使えば `__call()` が落とし、残せば flush の macro 検査が落とすが、
 *    使わずに消された登録はどちらにも現れない)
 */
final class PlainDataCacheGuard
{
    /** @var list<string> */
    private static array $violations = [];

    /** guard が実際に値を検査した回数 (空振り検知用)。 */
    private static int $inspected = 0;

    /**
     * アプリ生成の直後・`bootstrap()` の**前**に呼ぶ。
     *
     * 順序は load-bearing である。
     *  1. accumulator と計測値を初期化する (前テストが異常終了して afterEach が走らなかった
     *     場合の残骸をここで消す)
     *  2. `Repository::$macros` を検査して既定へ戻す (残骸があれば違反として記録してから)
     *  3. `cache` の extender を登録する
     *
     * ★1 と 2 を Pest の beforeEach へ置いてはならない。結線が bootstrap 前に入る以上、
     *   **起動中に記録された違反が beforeEach の初期化で消える**。provider が例外を握り潰した
     *   場合、accumulator の記録が唯一の証拠である。
     */
    public static function registerBeforeBootstrap(Application $app): void
    {
        self::$violations = [];
        self::$inspected = 0;
        self::pinMacros();

        $app->extend('cache', function (mixed $manager, Application $app): PlainDataGuardedCacheManager {
            // ★受け取った実体が**素の** CacheManager ちょうどでなければ落とす。
            //   独自 creator の登録口 (Cache::extend()) は静的層 L4 が 0 件で pin しているので、
            //   引き継ぐべき状態は無い。想定外の実体を黙って捨てない。
            if (! $manager instanceof CacheManager || $manager::class !== CacheManager::class) {
                throw new RuntimeException(
                    'cache binding が想定外の実体でした: '.get_debug_type($manager).'。'
                    .'PlainDataCacheGuard の結線前提 (素の Illuminate\Cache\CacheManager) が崩れている。'
                );
            }

            return new PlainDataGuardedCacheManager($app);
        });
    }

    /**
     * 結線が効いていることの確認 (Pest の beforeEach)。**accumulator には触らない**。
     */
    public static function assertInstalled(Application $app): void
    {
        $manager = $app->make('cache');
        if (! $manager instanceof PlainDataGuardedCacheManager) {
            throw new RuntimeException('キャッシュ guard が結線されていません: '.get_debug_type($manager));
        }

        // ★RateLimiter は起動中に cache を解決する (AppServiceProvider::boot() が
        //   RateLimiter::for(...) を多数登録するため必ず解決される)。したがって
        //   「起動前に結線できていた」ことの証拠になる。**解決されていなければ前提が崩れたので落とす**。
        if (! $app->resolved(RateLimiter::class)) {
            throw new RuntimeException(
                'RateLimiter が起動中に解決されていません。起動前結線の前提 '
                .'(AppServiceProvider::boot() の名前付き制限登録) が崩れている。'
            );
        }

        // **読むだけで書き換えない**。プロパティが無ければ ReflectionException = その場で失敗。
        $repository = (new ReflectionProperty(RateLimiter::class, 'cache'))
            ->getValue($app->make(RateLimiter::class));

        if (! $repository instanceof PlainDataGuardedRepository) {
            throw new RuntimeException(
                'RateLimiter が guard 付きでない受け皿を握っています: '.get_debug_type($repository)
            );
        }
    }

    /**
     * 書き込まれる値を検査する。違反は accumulator に記録し、**その場でも例外**を投げる。
     */
    public static function inspect(string $method, string $key, mixed $value): void
    {
        self::$inspected++;

        $violations = PlainDataInspector::violations($value);
        if ($violations === []) {
            return;
        }

        self::$violations[] = "{$method}('{$key}'): ".implode(' / ', $violations);

        throw CachePayloadViolation::forWrite($method, $key, $violations);
    }

    /**
     * 受け皿の境界を迂回した呼び出しを記録して例外にする。
     */
    public static function reportBoundary(string $operation, string $detail): never
    {
        self::$violations[] = "BOUNDARY_BYPASS({$operation}): {$detail}";

        throw CachePayloadViolation::forBoundary($operation, $detail);
    }

    /**
     * Pest の afterEach。残存 macro を検査して記録し、accumulator に記録があれば fail させる。
     */
    public static function flushAndFailIfStray(): void
    {
        try {
            self::pinMacros();

            if (self::$violations === []) {
                return;
            }

            throw new RuntimeException(
                'Plain-data cache violation detected during test execution. '
                .'キャッシュに入れてよいのは素のデータだけ (AGENTS.md セキュリティ不変条件 11 / '
                .'家系の裁定 AG-107・AG-151)。'.PHP_EOL.self::summarize(self::$violations)
            );
        } finally {
            self::reset();
        }
    }

    /** accumulator と計測値を消し、macro を**記録せずに**既定へ戻す。 */
    public static function reset(): void
    {
        self::$violations = [];
        self::$inspected = 0;
        self::restoreMacros();
    }

    /**
     * 意図的に違反を起こすテスト用の drain (`StrayLlmCallGuard` と同じ)。
     *
     * @return list<string>
     */
    public static function drainForAssertion(): array
    {
        $drained = self::$violations;
        self::$violations = [];

        return $drained;
    }

    /** guard が実際に値を見た回数 (空振り検知)。 */
    public static function inspectedCount(): int
    {
        return self::$inspected;
    }

    /**
     * `Repository::$macros` を検査して記録し、既定へ戻す。
     */
    private static function pinMacros(): void
    {
        $macros = self::readMacros();
        if ($macros !== []) {
            self::$violations[] = 'MACRO_REGISTERED('
                .implode(', ', array_map(strval(...), array_keys($macros))).')';
        }

        self::restoreMacros();
    }

    /** 記録せず既定へ戻すだけ (reset() から呼ぶ。flush の直後に二重記録しない)。 */
    private static function restoreMacros(): void
    {
        self::macrosProperty()->setValue(null, []);
    }

    /** @return array<array-key, mixed> */
    private static function readMacros(): array
    {
        $macros = self::macrosProperty()->getValue();
        if (! is_array($macros)) {
            throw new RuntimeException('Repository::$macros が配列ではありません: '.get_debug_type($macros));
        }

        return $macros;
    }

    private static function macrosProperty(): ReflectionProperty
    {
        $reflection = new ReflectionClass(Repository::class);
        if (! $reflection->hasProperty('macros')) {
            throw new RuntimeException(
                'Illuminate\Cache\Repository::$macros が存在しません。macro 経由の迂回 pin が'
                .'空振りしている。vendor を読み直して pin を作り直すこと。'
            );
        }

        return $reflection->getProperty('macros');
    }

    /**
     * @param  list<string>  $violations
     */
    private static function summarize(array $violations): string
    {
        return implode(PHP_EOL, array_map(
            static fn (string $violation, int $index): string => '  ['.($index + 1).'] '.$violation,
            $violations,
            array_keys($violations),
        ));
    }
}
