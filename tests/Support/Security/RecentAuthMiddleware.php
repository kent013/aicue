<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use App\Http\Middleware\RequireRecentAuth;
use Illuminate\Routing\Route as RoutingRoute;

/**
 * route に recent-auth (step-up) middleware が付いているかの**唯一の判定点**。
 *
 * RecentAuthRouteTest (allowlist 型) と TwoFactorStepUpInventoryTest (deny-by-default 目録型)
 * の 2 つの gate が同じ述語を使う。判定を各テストに複製すると、片方だけ堅牢化されて
 * 「一方は付いていると言い、他方は付いていないと言う」ドリフトが起きる。
 */
final class RecentAuthMiddleware
{
    /**
     * 実効 middleware 列に含まれる recent-auth 系 entry の**種類数**を返す。
     *
     * ★数えるのは「種類」であって「登録回数」ではない (誇張しない):
     *   `Route::gatherMiddleware()` は `Router::uniqueMiddleware()` を通すため、
     *   **同一文字列**の二重登録は framework が畳んで 1 本になる (実査: Laravel 12
     *   `Routing/Router::uniqueMiddleware()` が値をキーに `$seen` で除去)。
     *   したがって同一 alias の重複は**実行時に観測できず、振る舞いにも差が出ない**。
     *   観測できない差分に gate を置くのは偽陽性を生むだけなので、そこは検査しない。
     *
     * ★検査する価値があるのは **別種の recent-auth が同居する**状態である。
     *   例: `recent-auth` (無条件 step-up) と `recent-auth.on-email-change` (条件付き) が
     *   同一 route に付くと、意図が矛盾した二重ゲートになる (どちらが真の契約か読めない)。
     *   これは別文字列なので dedup されず、ここで 2 と数えられる。
     *
     * 受理する entry を**厳密に**限定する (`recent-authentication` のような将来の別 alias を
     * 巻き込んで数えないため):
     *   - `recent-auth` 完全一致
     *   - `recent-auth:` 前方一致 (パラメータ付き)
     *   - `recent-auth.` 前方一致 (`recent-auth.on-email-change` 等の派生 alias)
     *   - `RequireRecentAuth::class` 完全一致
     */
    public static function countAttachedKinds(RoutingRoute $route): int
    {
        $count = 0;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if ($middleware === RequireRecentAuth::class
                || $middleware === 'recent-auth'
                || str_starts_with($middleware, 'recent-auth:')
                || str_starts_with($middleware, 'recent-auth.')) {
                $count++;
            }
        }

        return $count;
    }

    /** 1 種類以上付いているか (allowlist 型 gate 用の薄いラッパ。既存の意味を変えない)。 */
    public static function isAttached(RoutingRoute $route): bool
    {
        return self::countAttachedKinds($route) > 0;
    }
}
