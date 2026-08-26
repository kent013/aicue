<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;

/**
 * 「経路の一覧が組み上がった後」に処理を走らせる**唯一の実行点** (家系の正典の形)。
 *
 * ## なぜ専用の実行点が要るのか
 *
 * `php artisan route:cache` した状態では、framework の `RouteServiceProvider` が
 * **`$app->booted()` のコールバックの中で** 経路キャッシュを `require` する
 * (`loadCachedRoutes()`)。実測した provider 順序はこうである — framework の
 * `RouteServiceProvider` は `withRouting()` が booting コールバックで登録するため**最後に boot**
 * され、経路キャッシュの読み込みはさらにその中の起動完了フックへ積まれる。
 * 一方、経路へ middleware を後付けする側も起動完了フックを使うため、
 * 後付け側が先に登録されると「経路が 1 本も無い状態」で走る
 * (`loadRoutesFrom()` が cached のとき `require` を飛ばすのと同じ事情)。そこで経路名が引けないことを
 * 例外にすると、**cached 起動が丸ごと落ちる** (`php artisan route:list` も
 * `php artisan route:clear` も落ち、復旧手段まで失う。本リポジトリでは T120 として実際に起きた)。
 * **起動完了フックを入れ子にしても解決しない** — `Application::booted()` は `isBooted()` が
 * true のとき登録済みコールバックを**即時実行**するので、booted 実行中に足したコールバックは
 * 「後ろに並ぶ」のではなくその場で走る。
 *
 * ## 契約 (2 行だけ)
 *
 * - **経路が cached でないとき**: 起動完了フックで `$whenLoaded` を実行する
 *   (非 cached では `RouteServiceProvider::boot()` が経路を同期的に読むので、
 *   起動完了の時点で一覧は組み上がっている)。
 * - **経路が cached のとき**: コールバックを**一切実行しない**。後付けも検査もしない。
 *   この時点で経路の一覧はまだ空であり、仮に後で走らせても
 *   `CompiledRouteCollection` の名前引きは compiled attributes から**その場で新しい経路を
 *   生成して返す**ので、middleware を足しても捨てられる。
 *   (識別子に丸括弧を続けて書かないのは
 *   `tests/Architecture/PostBootRouteMutationInventoryTest.php` の字句走査の作法である。)
 *
 * ## cached 起動で検査を行わなくても無保護にならない理由
 *
 * `php artisan route:cache` は**経路が cached でない新しいアプリを起動**して一覧を組み上げてから
 * 直列化する (`RouteCacheCommand::getFreshApplicationRoutes()`)。つまり**後付けと
 * 起動時 fail-fast は経路キャッシュの生成時点で必ず走る**。パッケージ更新で経路名が変わっていれば
 * `route:cache` 自体が異常終了し、**無保護な経路を焼き込んだ cache は作れない**。
 * cached 起動側で追加の検査を行わないのはこのためである。
 *
 * ## 保証しないこと (誇張しない)
 *
 * - **生成より前に焼いた古い cache を配ること**は止められない (配備の責務)。
 *   本クラスが保証するのは「その cache が作られた時点で後付けが走った」ことまでである。
 * - **起動時に cache の鮮度を判定すること**はできない。本番デプロイは全ファイルを新規展開して
 *   mtime が揃うため、cache が古いソースから作られたかは起動時から**正しく判定できない**。
 * - コールバックの中身が正しいことは本クラスの主題ではない (各 binder の責務)。
 *
 * ## かつての逸脱 D19 が「移行するなら同時に解く必要がある」と列挙した 4 点の扱い
 *
 * 本アプリはかつて「cached 起動では後付けを走らせない」側を逸脱として登録していた
 * (`docs/template-divergence.md` の D19)。**2026-08-26 のオーナー裁定で正典へ移行し、
 * その登録は解消 = 台帳から削除した** (番号は再利用しないので D19 は欠番である)。
 * 当時の登録は、正典の形を「cached 起動でも後付けを効かせる形」と読んでいた。
 * 正典の実装 (`laravel-claude-template` の `App\Http\Routing\AfterRoutesLoaded`) を実読した結果、
 * **正典は cached 起動では後付けも検査も行わない**。したがって 4 点は次のように**消える**:
 *
 * 1. **容器の `routes` 束縛の張り替えの捕捉** — 不要。張り替え (`setCompiledRoutes()`) の後に
 *    走らせる必要が無いので、`rebinding()` を張らない。
 * 2. **束縛がまだ無いときに張り替えが発火しない穴** — 1 が無いので前提ごと無い。
 * 3. **経路一覧の実体ごとの冪等** — 後付けが触る一覧は「非 cached 起動で組み上がった 1 つ」だけで、
 *    実体を跨がない。同一起動内での重複付与は各 binder の冪等 (同じ alias が既にあれば足さない) が閉じる。
 * 4. **cached 起動で起動を止めると `route:list` も `route:clear` も落ちる問題** —
 *    例外設計ではなく「**実行しない**」で解く。cached 起動では例外の発生源そのものが無い。
 *
 * 経路へ middleware を後付けする処理は**必ずここを通す**こと。素の `Application::booted()` を
 * 直接使うと上記の順序事故が再発する
 * (`tests/Architecture/PostBootRouteMutationInventoryTest.php` が直呼び禁止を、
 * `tests/Unit/Support/Http/AfterRoutesLoadedTest.php` が分岐の契約を機械検査する)。
 */
final class AfterRoutesLoaded
{
    /**
     * 経路の一覧が組み上がった後に 1 度だけ `$whenLoaded` を走らせる。
     *
     * ★`Illuminate\Contracts\Foundation\Application` は `routesAreCached()` を宣言しないため
     *   `CachesRoutes` の実装であることを確かめてから呼ぶ (正典は契約の型のまま呼んでいるが、
     *   本リポジトリの静的解析はそれを通さない。**判定の意味は同じ**)。
     *   経路キャッシュの概念を持たない容器 (`CachesRoutes` でないもの) では
     *   「cached ではない」= 実行する側へ倒す。経路が無ければ後付け側が起動時に落ちるので、
     *   黙って無保護になる側へは倒れない。
     * ★cached の判定は**起動完了フックの中で**行う (登録時点では経路キャッシュの状態が
     *   まだ確定していない場面がある)。
     */
    public static function schedule(Application $app, Closure $whenLoaded): void
    {
        $app->booted(static function () use ($app, $whenLoaded): void {
            if ($app instanceof CachesRoutes && $app->routesAreCached()) {
                return;
            }

            $whenLoaded();
        });
    }
}
