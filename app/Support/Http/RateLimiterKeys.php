<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * named limiter のキー `{レーン}:{種別}:{値}` を組む唯一の入口 (actor か IP で数えるレーン用)。
 *
 * ★存在理由: 「認証済みなら actor / 未認証なら IP」という同じ分岐を 8 個の limiter closure に
 *   ベタ書きすると、レーン名の typo・分岐の取り違え・null 扱いの差異が入り込む。
 *   キー規約 (`{レーン}:{種別}:{値}`) の実装点を 1 つにする。
 *
 * ★`is_scalar()` を使わない理由: `getAuthIdentifier()` の契約は `int|string|null` であり、
 *   `is_scalar()` は `bool` / `float` まで通してしまう (`true` が `:user:1` へ潰れる)。
 *   契約どおり `is_int()` / `is_string()` で明示的に絞り込む。
 *
 * ★lane を enum にしない: `RateLimiter::for()` の第 1 引数は
 *   `Tests\Support\RateLimiterRegistrationScanner` の要求で**リテラル文字列**でなければならず
 *   (解析できない登録は `RateLimiterKeyConventionTest` の unresolved 検査が fail させる)、
 *   enum を入れると「`for()` にはリテラル / helper には enum」の二重管理になる。
 *
 * ★これは**数える単位**を決めるだけで、認可でも認証でもない。
 */
final class RateLimiterKeys
{
    /** 未認証で IP も取れないときの終端値 (キーを空にしない)。 */
    private const UNKNOWN_IP = 'unknown';

    /**
     * 認証済みなら `{lane}:user:{id}`、未認証なら `{lane}:ip:{ip}`。
     *
     * throttle middleware は route によっては auth より後に走る (現行の priority list では
     * `AuthenticatesRequests` → `ThrottleRequests`)。したがって auth 必須 route では
     * user 分岐しか通らないが、**auth を持たない route でも同じ helper が使える**ように
     * IP 分岐を常に持つ (priority list への依存を単一障害点にしない)。
     *
     * @param  non-empty-string  $lane
     * @return non-empty-string
     */
    public static function actorOrIp(Request $request, string $lane): string
    {
        $identifier = $request->user()?->getAuthIdentifier();

        if (is_int($identifier)) {
            return $lane.':user:'.$identifier;
        }

        if (is_string($identifier) && $identifier !== '') {
            return $lane.':user:'.$identifier;
        }

        $ip = $request->ip();

        return $lane.':ip:'.($ip === null || $ip === '' ? self::UNKNOWN_IP : $ip);
    }
}
