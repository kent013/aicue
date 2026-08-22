<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * 識別名と**同じ位置**に現れる静的セグメントを route 表から抽出する走査器
 * (家系裁定 AG-039 / 不変条件 I10)。
 *
 * ★判定は**位置**で行う。語の一致だけで拾うと `settings` / `api-keys` / `invitations`
 *   のような**第 3 セグメント以降**の語まで予約語にしてしまい、正当な組織名が取れなくなる。
 * ★識別名の位置は `/organizations/` の直下 (**第 2 セグメント**) である。
 * ★母集団が空なら fail する (走査根の改名・prefix 変更で空振りしても気付ける)。
 * ★**未解決は落とす**: uri が動的に組まれていて第 2 セグメントを判定できない形は
 *   例外にする (無言で候補から外さない)。実際には `Route::getRoutes()` が返す uri は
 *   確定文字列なので、この分岐は「将来 uri の表現が変わったとき」の fail-closed である。
 *
 * ★**保証しないもの**: 見るのは登録済み route 表だけである。`route:cache` 生成前後で
 *   表が変わる形 (実行時に条件付きで登録される route) は、その条件が偽のときには見えない。
 */
final class OrganizationSlugRouteScanner
{
    private const string PREFIX = 'organizations/';

    /**
     * `/organizations/` 直下の**静的**セグメントを昇順で返す。
     *
     * @return list<string>
     */
    public static function staticSecondSegments(): array
    {
        $population = 0;
        $segments = [];

        foreach (Route::getRoutes() as $route) {
            $uri = self::uriOf($route);
            if (! str_starts_with($uri, self::PREFIX)) {
                continue;
            }
            $population++;

            $parts = explode('/', $uri);
            if (! array_key_exists(1, $parts)) {
                continue; // `organizations` 単体 (POST /organizations)。第 2 セグメントが無い
            }
            $second = $parts[1];
            if ($second === '') {
                continue;
            }
            if (str_starts_with($second, '{')) {
                continue; // 識別名そのもの
            }
            $segments[] = $second;
        }

        if ($population === 0) {
            throw new RuntimeException(
                '走査根が空です: uri が "'.self::PREFIX.'" で始まる route が 1 本もありません'
                .' (prefix の変更・group の解体で走査が壊れていないか確認すること)'
            );
        }

        $segments = array_values(array_unique($segments));
        sort($segments);

        return $segments;
    }

    /** uri を取り出す (解決できない形は fail-closed)。 */
    private static function uriOf(RoutingRoute $route): string
    {
        $uri = $route->uri();
        if ($uri === '') {
            throw new RuntimeException('route の uri を解決できませんでした: '.($route->getName() ?? '(no name)'));
        }

        return $uri;
    }
}
