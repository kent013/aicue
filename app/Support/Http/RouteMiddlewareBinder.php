<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use RuntimeException;

/**
 * vendor が登録した named route へ **middleware alias** を後付けする binder。
 *
 * ★責務境界 (統合しない): {@see RouteThrottleBinder} は **limiter の形式検証・二重付与検出**
 *   という固有責務を持つ throttle 後付け専用の binder であり、こちらは
 *   **任意の alias を spec の列の順に冪等に足すだけ**である。
 *   **「throttle は必ず向こう」ではない**: 本 binder も `passkey.destroy` へ
 *   `throttle:passkeys` を付ける (既存の付与内容・付与順を 1 つも変えないため
 *   passkey 系は 1 route の alias 列として **throttle → recent-auth → 手段保持** の順で
 *   まとめて扱う必要があり、throttle だけ別 binder へ切り出すと順序契約が割れる)。
 *   本 binder にとって `throttle:passkeys` は**検証対象ではないただの alias 文字列**である。
 *   両者は route:cache との契約 (下記) を共有する。
 *
 * ★**2 つの事象を混ぜないこと** (ここが本 binder の存在理由):
 *
 *   1. **生成時** (`php artisan route:cache` の実行中)
 *      `RouteCacheCommand::handle()` は先頭で `route:clear` してから
 *      `getFreshApplicationRoutes()` で **cache 無しのアプリを再 bootstrap** する。
 *      そこでは `loadRoutesFrom()` が `require` を通すため本後付けが**完全に走り**、
 *      付与済み middleware がそのまま cache へ**焼き込まれる**。
 *      route 名が消えていれば**ここでデプロイが止まる**。
 *      ★fail-fast そのものは「**本後付けが実際に走る起動すべて**」= route cache が無い起動
 *        (ローカル開発起動・テスト・`route:cache` 生成時の再 bootstrap) で効く。
 *        `route:cache` 生成時だけに効くわけではない (等号で結ばない)。
 *        ただし**本番デプロイで意味を持つ地点はここ**である —
 *        cached 運用の本番では、ここで止まらなければサービス投入まで誰も気づかない。
 *
 *   2. **起動時** (route cache がある状態でのリクエスト処理 / artisan)
 *      本後付けは **1 本も走らない** ({@see AfterRoutesLoaded::schedule()} が呼ばないため)。
 *      仮に走らせても効かない。理由は 2 つあり、片方だけでも成立する:
 *        (a) `ServiceProvider::loadRoutesFrom()` は `routesAreCached()` のとき `require` を
 *            飛ばす。Fortify / laravel-passkeys はこれを使うため、**この callback が走る
 *            時点では対象 named route が 1 本も登録されていない**
 *            (compiled routes は後で読まれるので「route が永久に存在しない」の意味ではない。
 *            ここを誤読すると次の担当がまた別の誤った結論に着く)。
 *        (b) 仮に触れていても、framework の `RouteServiceProvider` が本 callback より**後**の
 *            app-booted で compiled routes を読み、`Router::setCompiledRoutes()` が
 *            route collection を**新品へ丸ごと差し替える**ため捨てられる。
 *      よって cached 起動では**後付けも検査も行わない**。
 *      **ここで例外を投げてはならない** (`route:list` が必ず落ちる = T120 の事故)。
 *      実行タイミングの判断は {@see AfterRoutesLoaded} が単独で持つ (家系の正典の形)。
 *
 *   ⇒ **cached 起動での保護を持っているのは cache の中身である**。生成時に必ず後付けが走る以上、
 *     **無保護な route を焼き込んだ cache は作れない**。守れないのは「生成より前に焼いた
 *     古い cache のまま起動する」場合だけであり、したがって
 *     **`php artisan route:cache` を毎デプロイ再生成することが本機構の前提条件**になる。
 *     古い route cache は古い付与状態のまま起動し、**無音で保護が外れる**
 *     (実測: 剥がした cache では 2FA 秘密 GET が 409 でなく 200 を返す)。
 *     運用契約の正本は `docs/app-integration-guide.md` §7c。
 *
 * ★よくある誤読の否定 (この記述を消さないこと):
 *   `CompiledRouteCollection::getByName()` が Route instance を `nameCache` へ memoize し、
 *   `match()` がその `getByName()` を通るのは**事実**である。しかしそれは
 *   「**compiled collection が読まれた後に** getByName して書き換えた場合」の話であり、
 *   本 callback はその前に走って**別の collection** を見ているため前提が成立しない。
 *   「nameCache があるから cached 起動でも後付けが効く」とは書かない。
 */
final class RouteMiddlewareBinder
{
    /**
     * 経路の一覧が組み上がった後に named route 群へ middleware alias を後付けする
     * (登録の唯一の入口)。
     *
     * ★**素の `Application::booted()` を直接使わない**。実行タイミングは
     *   {@see AfterRoutesLoaded::schedule()} が単独で決める (素の起動完了フックは
     *   cached routes の読み込みより先に走るため、route:cache 済みの起動を壊す余地を残す)。
     *   ★{@see attachAll} へ渡す cached 判定は**同じ事実の二度目の確認**である。
     *   実行点が cached 起動では呼ばないので、この配線から真になることは無い。
     *   それでも渡すのは、binder を直接呼ぶ経路 (単体検査) でも
     *   「cached な一覧には resolver すら呼ばない」契約が成立するようにするためである。
     *
     * ★spec を **resolver (callable) で受け、ここでは呼ばない**。理由は 2 つ:
     *   1. spec の構築 (呼び出し側の feature flag 判定を含む) を `boot()` の時点へ
     *      前倒し評価しないため。resolver は **booted callback の中でだけ**評価される。
     *   2. **cached 起動では resolver 自体を実行しない**ため。resolver をここで呼んで
     *      配列にしてから渡す形にすると、将来 resolver が route collection を覗く実装に
     *      なったとき early return の**前**に落ちる = T120 の再導入になる。
     *      ★ただし「型で不可能」なわけではない (`$specs = $specResolver();` してから
     *      `static fn () => $specs` を渡す退行は型検査を通る)。**誇張しない**。
     *      この配線が前倒し評価へ退行していないことは
     *      `RouteMiddlewareBinderTest` の配線テスト (`routes.cached` を true に束ねて
     *      「呼ばれたら throw する resolver」を渡す) が**振る舞いで**固定する。
     *
     * @param  callable(): array<string, list<string>>  $specResolver
     *                                                                 route 名 => 付与する middleware alias の列
     */
    public static function attachOnBooted(Application $app, callable $specResolver): void
    {
        AfterRoutesLoaded::schedule($app, static function () use ($app, $specResolver): void {
            self::attachAll(
                $app->make(Router::class),
                $specResolver,
                $app instanceof CachesRoutes && $app->routesAreCached(),
            );
        });
    }

    /**
     * named route 群へ middleware alias を後付けする (`$routesAreCached` なら何もしない)。
     *
     * skip 判定を**引数で受ける**ことで、判定と後付けの両方を純粋関数として検証できる
     * ({@see attachOnBooted} が実アプリの状態を渡す唯一の配線点)。
     *
     * ★`RouteThrottleBinder::attachAll()` は spec を **array** で受けるが、こちらは
     *   **resolver** で受ける。揃っていないのは意図である — あちらの spec は副作用の無い
     *   定数表で cached 起動でも評価してよいが、こちらは feature flag 判定を含み、
     *   「cached では resolver すら呼ばない」ことを保証したいため。
     *
     * @param  callable(): array<string, list<string>>  $specResolver
     */
    public static function attachAll(Router $router, callable $specResolver, bool $routesAreCached): void
    {
        if ($routesAreCached) {
            // 後付けは route:cache 生成時に焼き込み済み。
            // ★**resolver を呼ぶ前に**返すこと。route 解決はもちろん spec の構築にも
            //   到達させない (到達させると T120 型の事故を再導入する余地が残る)。
            return;
        }

        $routes = $router->getRoutes();
        // fluent な ->name() 付与は name index に遅延反映されるため明示 refresh
        $routes->refreshNameLookups();

        foreach ($specResolver() as $name => $aliases) {
            self::attachByName($routes, $name, $aliases);
        }
    }

    /**
     * named route へ middleware alias 群を冪等に後付けする。
     *
     * @param  list<string>  $aliases
     *
     * @throws RuntimeException route が引けない場合 (= 無防備なまま公開される事故を止める)
     */
    public static function attachByName(RouteCollectionInterface $routes, string $routeName, array $aliases): void
    {
        $route = $routes->getByName($routeName);
        if (! $route instanceof Route) {
            throw new RuntimeException(
                "middleware を後付けすべき route [{$routeName}] が見つかりません。"
                .'vendor package が update で route 名を変えたか、feature flag が無効化された'
                .'可能性があります (無効化が正しいなら呼び出し側の spec から外すこと)。'
                .'無防備なまま公開される事故を防ぐため fail-fast で起動を止めます。',
            );
        }

        $changed = false;
        foreach ($aliases as $alias) {
            if (in_array($alias, $route->middleware(), true)) {
                continue; // 冪等 (同一 bootstrap 内の重複呼び出し / 既に定義側で貼られている)
            }

            $route->middleware($alias);
            $changed = true;
        }

        if ($changed) {
            // ★memo の破棄 (RouteThrottleBinder と同じ作法)。
            //   **本経路では現時点 no-op である**ことを実読で確認している
            //   (ここは gatherMiddleware() を呼ばないため $computedMiddleware は温まらない)。
            //   それでも置くのは、この memo を取りこぼしたときの壊れ方が
            //   「middleware() には載るのに dispatch では実行されない = 無音の無防備」であり、
            //   本 binder が潰そうとしている失敗形そのものだから。
            $route->computedMiddleware = null;
        }
    }
}
