<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * vendor が登録した named route へ throttle middleware を後付けする binder。
 *
 * ★位置づけ: 「貼る仕組みの 3 段優先順」(docs/app-integration-guide.md §7b) の**第 3 段**。
 *   第 1 段 = 自前 route の定義に直接書く / 第 2 段 = package の設定で貼る
 *   (`config/fortify.php` の `limiters` 等)。**第 2 段でも貼れない vendor route 専用**であり、
 *   設定で貼れるものをここへ持ってこない。
 *
 * ★実行タイミングは {@see AfterRoutesLoaded::schedule()} に委ねる (家系の正典の形)。
 *   素の `Application::booted()` を直接使うと cached routes の読み込みより先に走り、
 *   経路が 1 本も無い状態で fail-fast が誤爆して **route:cache 済みの起動が丸ごと落ちる**
 *   (`php artisan route:list` も `route:clear` も落ちる = T120 の事故)。
 *
 * ★route:cache との関係 (契約):
 *   - `php artisan route:cache` は `route:clear` 後に**アプリを再 bootstrap** して route を
 *     直列化するため、その再 bootstrap で本後付けが完全に走り、throttle は cache へ焼き込まれる。
 *     route 名が消えていればここで**デプロイが止まる** (fail-fast はここで効く)。
 *     ⇒ **無保護な route を焼き込んだ cache は作れない。**
 *   - **cached 起動では後付けも検査も行わない** (実行点が呼ばないため到達しない)。
 *     機序と「それでも fail-secure が失われない理由」は {@see AfterRoutesLoaded} が正本。
 *
 * ★判定は文字列の完全一致にしない:
 *   実効 middleware の entry は `{class}:{params}` 形式で出る。
 *   class 部は cache driver によって ThrottleRequests / ThrottleRequestsWithRedis の
 *   どちらにもなりうる (後者は前者を継承)。class 部は is_a() で、params 部は
 *   limiter 名の完全一致で比較する。
 *
 * ★付与は冪等: 同一 bootstrap 内で同じ (route, limiter) を複数回渡しても 1 本のままにする。
 */
final class RouteThrottleBinder
{
    /** named limiter 名の形式。 */
    private const NAMED_LIMITER_PATTERN = '/^[a-z][a-z0-9-]*$/';

    /** inline throttle (`{max},{decay}`) の形式。 */
    private const INLINE_LIMITER_PATTERN = '/^\d+,\d+$/';

    /**
     * 経路の一覧が組み上がった後に named route 群へ throttle を後付けする (登録の唯一の入口)。
     *
     * ★**素の `Application::booted()` を直接使わない**。cached routes は framework の
     *   `RouteServiceProvider` が起動完了フックの中で `require` するため、順序を誤ると
     *   「経路が 1 本も無い状態」で fail-fast が誤爆して cached 起動が落ちる。
     *   実行タイミングの判断は {@see AfterRoutesLoaded} が単独で持つ
     *   (機序・実測・「cached 起動で検査しなくても無保護にならない理由」は同クラスの docblock)。
     *
     * ★skip が穴にならない根拠 (fail-fast は失われない):
     *   `php artisan route:cache` は `route:clear` してから**アプリを再 bootstrap** して
     *   route を直列化する。その再 bootstrap は cache 無しなので本後付けが完全に走り、
     *   route 名が消えていればそこで**デプロイが止まる**。付与済みの throttle は
     *   そのまま cache へ焼き込まれる。CI (テスト) も cache 無しで走るため、
     *   目録検査 (ThrottleCoverageInventoryTest) の deny-by-default も素通りしない。
     *
     * ★{@see attachAll} へ渡す cached 判定は**同じ事実の二度目の確認**である。
     *   実行点が cached 起動では呼ばないので、この配線から真になることは無い。
     *   それでも渡すのは、binder を直接呼ぶ経路 (単体検査・将来の別の呼び出し元) でも
     *   「cached な一覧には触らない」契約が成立するようにするためである。
     *
     * ★残るリスク (誇張しない):
     *   守れないのは「**生成より前に焼いた古い cache のまま起動する**」場合だけである。
     *   その cache は古い付与状態を保持しているため、`php artisan route:cache` を
     *   **毎デプロイ再生成する**ことが本機構の前提条件になる
     *   (docs/app-integration-guide.md §7c にも運用要件として明記)。
     *
     * @param  array<string, string>  $routes  route 名 => limiter (named 名 or `{max},{decay}`)
     */
    public static function attachOnBooted(Application $app, array $routes): void
    {
        AfterRoutesLoaded::schedule($app, static function () use ($app, $routes): void {
            self::attachAll(
                $app->make(Router::class),
                $routes,
                $app instanceof CachesRoutes && $app->routesAreCached(),
            );
        });
    }

    /**
     * named route 群へ throttle を後付けする (`$routesAreCached` なら何もしない)。
     *
     * skip 判定を引数で受けることで、判定と後付けの両方を純粋関数として検証できる
     * ({@see attachOnBooted} が実アプリの状態を渡す唯一の配線点)。
     *
     * @param  array<string, string>  $routes  route 名 => limiter
     */
    public static function attachAll(Router $router, array $routes, bool $routesAreCached): void
    {
        if ($routesAreCached) {
            return; // 後付けは route:cache 生成時に焼き込み済み
        }

        foreach ($routes as $name => $limiter) {
            self::attachByName($router, $name, $limiter);
        }
    }

    /**
     * named route へ `throttle:{$limiter}` を冪等に後付けする。
     *
     * @param  string  $routeName  Fortify / Cashier 等が登録した route 名
     * @param  string  $limiter  named limiter 名 または `{max},{decay}` 形式
     *
     * @throws RuntimeException route が引けない / 別の throttle が既に付いている / 2 本以上ある
     */
    public static function attachByName(Router $router, string $routeName, string $limiter): void
    {
        // ★期待値の検証を最初に行う (route 解決や既存 entry の有無に依存させない)。
        //   ここを後回しにすると「初回呼び出しでは `6,1,9` のような不正形式を素通しする」
        //   非対称な穴になる。
        self::assertValidLimiter($limiter, "throttle の期待値 [{$limiter}] (route [{$routeName}])");

        $routes = $router->getRoutes();
        // fluent な ->name() 付与は name index に遅延反映されるため明示 refresh
        // (FortifyServiceProvider::attachRecentAuthToSensitiveRoutes と同じ前提)
        $routes->refreshNameLookups();

        $route = $routes->getByName($routeName);
        if (! $route instanceof Route) {
            throw new RuntimeException(
                "throttle を後付けすべき route [{$routeName}] が見つかりません。"
                .'vendor package が update で route 名を変えた可能性があります。'
                .'無防備なまま公開される事故を防ぐため fail-fast で起動を止めます。',
            );
        }

        $entries = self::routeThrottleEntries($router, $route);
        if ($entries === []) {
            $route->middleware('throttle:'.$limiter);

            // ★memoization の破棄が必須:
            //   Route::gatherMiddleware() は結果を $computedMiddleware に memoize し、
            //   dispatch 時の Router::gatherRouteMiddleware() もこの値を読む。
            //   直前の throttleEntries() が memo を温めてしまうため、破棄しないと
            //   「middleware() には載っているのに実行されない throttle」= 無音の無防備になる。
            $route->computedMiddleware = null;

            return;
        }

        if (count($entries) === 1) {
            // 既存 entry 側の params も形式検証する (想定外の throttle を素通ししない)
            $parsed = self::parseThrottleEntry($entries[0], "route [{$routeName}] の既存 throttle [{$entries[0]}]");
            if ($parsed['params'] === $limiter) {
                // 期待どおりの throttle が既にある = 冪等 no-op
                // (同一 bootstrap 内での重複呼び出し / 既に route 定義側で貼られている場合)
                return;
            }
        }

        throw new RuntimeException(
            "route [{$routeName}] に想定外の throttle が付いています: ".implode(', ', $entries)
            .' (期待: throttle:'.$limiter.')。二重付与は実効上限を半減させるため起動を止めます。',
        );
    }

    /**
     * 実効 middleware 列 (controller middleware 込み) のうち throttle entry を返す。
     *
     * 目録検査 (ThrottleCoverageInventoryTest) が使う**完全な**判定点。
     * `Route::gatherMiddleware()` は controller を container から解決するため、
     * **boot 中に呼んではならない** ({@see routeThrottleEntries} を使うこと)。
     *
     * @return list<string> `{class}:{params}` 形式の entry (params なしなら class のみ)
     */
    public static function throttleEntries(Router $router, Route $route): array
    {
        return self::filterThrottleEntries($router->gatherRouteMiddleware($route));
    }

    /**
     * route 自身 (group 展開込み) の middleware のうち throttle entry を返す。
     *
     * ★controller middleware を見ない理由 (boot 中の副作用を避ける):
     *   `Route::gatherMiddleware()` は controller middleware を集めるために
     *   **controller を container から解決する**。boot 中にこれを行うと、
     *   controller が constructor injection で要求する request scope の singleton
     *   (`StatefulGuard` → `session.store` 等) が boot 時点で確定してしまい、
     *   その後の設定変更・request 生成に追随しなくなる
     *   (実測: Fortify の ConfirmablePasswordController が StatefulGuard を要求する)。
     *
     * ★見落としが穴にならない根拠:
     *   controller middleware が throttle を足していた場合、本 binder は二重付与になるが、
     *   目録検査 ({@see throttleEntries} を使う ThrottleCoverageInventoryTest) が
     *   「throttle 2 本以上」として必ず fail させる。
     *
     * @return list<string>
     */
    public static function routeThrottleEntries(Router $router, Route $route): array
    {
        return self::filterThrottleEntries(
            $router->resolveMiddleware($route->middleware(), $route->excludedMiddleware()),
        );
    }

    /**
     * 解決済み middleware 列から throttle entry だけを取り出す。
     *
     * @param  iterable<mixed>  $resolved
     * @return list<string>
     */
    private static function filterThrottleEntries(iterable $resolved): array
    {
        $entries = [];

        foreach ($resolved as $middleware) {
            // 解決後の列には Closure middleware も混ざりうる (throttle ではない)
            if (is_string($middleware) && self::isThrottleEntry($middleware)) {
                $entries[] = $middleware;
            }
        }

        return $entries;
    }

    /** entry の class 部が throttle middleware か。 */
    public static function isThrottleEntry(string $middlewareEntry): bool
    {
        $class = Str::before($middlewareEntry, ':'); // class 名に ':' は含まれない

        return is_a($class, ThrottleRequests::class, true);
    }

    /**
     * throttle entry を class 部 / params 部に分解し、params の形式まで検証する。
     *
     * @return array{class: string, params: string}
     *
     * @throws RuntimeException params が named / inline のどちらの形式にも一致しない場合
     */
    private static function parseThrottleEntry(string $entry, string $context): array
    {
        $class = Str::before($entry, ':');
        // ★`:` を含まない entry (パラメータなし throttle) は params = '' になり、
        //   assertValidLimiter が必ず例外側へ落とす (意図どおり)。
        $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';

        self::assertValidLimiter($params, $context);

        return ['class' => $class, 'params' => $params];
    }

    /**
     * limiter 指定の形式を検証する (開発時ミス / 想定外 throttle の検出)。
     *
     * @throws RuntimeException named / inline のどちらの形式にも一致しない場合
     */
    private static function assertValidLimiter(string $limiter, string $context): void
    {
        if (preg_match(self::NAMED_LIMITER_PATTERN, $limiter) === 1) {
            return;
        }
        if (preg_match(self::INLINE_LIMITER_PATTERN, $limiter) === 1) {
            return;
        }

        throw new RuntimeException(
            $context.' が throttle の許容形式に一致しません。'
            .'named limiter 名 (`[a-z][a-z0-9-]*`) か inline 形式 (`{max},{decay}`) のいずれかで指定してください。'
            .'想定外の形式を素通しすると、意図しない上限のまま公開される事故になります。',
        );
    }
}
