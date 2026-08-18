<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Closure;

/**
 * 意図的な違反を起こすテストのための共通 assertion。
 *
 * ★drain を忘れるとグローバル afterEach の `flushAndFailIfStray()` が二重に落ちて
 *   **すべての負例が失敗する**。単に消すのではなく**記録内容まで assert する**
 *   (「例外だけ別経路から出た」空振りを防ぐため)。
 * ★PSR-4 は関数をオートロードしないので、global function ではなくクラスの static メソッドにする。
 */
final class CachePayloadViolationAssertions
{
    /**
     * (1) 例外が投げられること (2) accumulator にちょうど 1 件記録され期待する断片を含むこと
     * (3) drain 後に accumulator が空であること をまとめて検査する。
     *
     * @param  Closure(): mixed  $callback
     * @param  list<string>  $expectedFragments
     */
    public static function expectViolation(Closure $callback, array $expectedFragments): void
    {
        expect($callback)->toThrow(CachePayloadViolation::class);

        $drained = PlainDataCacheGuard::drainForAssertion();
        expect($drained)->toHaveCount(1);
        foreach ($expectedFragments as $fragment) {
            expect($drained[0])->toContain($fragment);
        }
        expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
    }
}
