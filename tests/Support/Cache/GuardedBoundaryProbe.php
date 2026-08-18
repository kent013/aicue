<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;

/**
 * 境界迂回が hard fail することを固定するための**唯一の**呼び出し元。
 *
 * ★受け皿を `Illuminate\Cache\Repository` 型の**引数**で受けるのが load-bearing —
 *   静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) は型宣言から受け手名を作るため、
 *   ローカル変数へ代入する書き方だと L4 の自己テスト目録が実測 0 件になって exact-fit が落ちる。
 * ★境界 API を呼ぶ自己テストは**このファイルにだけ**置く (L4f が置き場所を名指しで固定する)。
 */
final class GuardedBoundaryProbe
{
    // ★`@return never` は付けない。引数の native 型は**通常の** Illuminate\Cache\Repository で、
    //   通常の Repository の tags() は値を返し得る。「guard 付きを渡したときに例外になる」ことは
    //   tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が保証するのであって、
    //   静的なメソッド契約ではない。

    public static function callTags(Repository $cache): void
    {
        $cache->tags(['t']);
    }

    public static function callSetStore(Repository $cache): void
    {
        $cache->setStore(new ArrayStore);
    }

    public static function callUnknownMethod(Repository $cache): void
    {
        $cache->guardProbeUnknownMethod();
    }

    /**
     * macro を登録して**使う**。guard の `__call()` が例外を投げるので、
     * **`finally` で必ず登録を消す** — 消さないと global afterEach の macro 検査が
     * MACRO_REGISTERED を記録し、意図的負例が二重に失敗する。
     * 境界 API の呼び出しはこのファイルにしか置けないので、
     * テスト本体の finally から `flushMacros()` を呼ぶ形にはできない。
     */
    public static function callMacro(Repository $cache): void
    {
        Repository::macro('guardProbeMacro', fn (): bool => true);

        try {
            $cache->guardProbeMacro();
        } finally {
            Repository::flushMacros();
        }
    }

    /**
     * macro を**登録するだけ**で使わない (flush の残存 macro 検出用)。
     * 呼び出し側のテストが `flushAndFailIfStray()` を明示的に呼び、
     * MACRO_REGISTERED の記録と既定への復元を確認する。
     */
    public static function registerMacroWithoutUsing(): void
    {
        Repository::macro('guardProbeResidualMacro', fn (): bool => true);
    }

    /**
     * 独自 creator が `CacheManager::repository()` を通らないことの実証用。
     *
     * ★登録も解決も**引数の manager** に対して行う。facade へ登録して引数から解決すると、
     *   facade root と引数が別インスタンスだったときに「extend の前提」ではなく
     *   別インスタンスの問題で落ちる。CacheManager は静的層の受け手型なので
     *   L4 の検出力は保たれる。
     */
    public static function resolveCustomDriver(CacheManager $manager): mixed
    {
        config()->set('cache.stores.guard-probe', ['driver' => 'guard-probe']);

        $manager->extend('guard-probe', fn (): Repository => new Repository(new ArrayStore));

        return $manager->store('guard-probe');
    }
}
