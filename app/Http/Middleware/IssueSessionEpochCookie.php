<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\SessionEpoch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * セッション世代の印を「画面側から読める cookie」として応答に載せる。
 *
 * 用途は 1 つだけで、bfcache から復元された画面が **通信を待たずに**
 * 「世代が変わっている」と気づけるようにすることである。
 * 開示 (秘匿の解除) の根拠にはならない — 開示はプローブ応答だけが決める
 * (resources/js/lib/bfcache-guard.ts / SessionStatusController)。
 *
 * 契約:
 *   - `$next` の**後**に、応答時点のセッション ID から導出する
 *     (ログイン・ログアウトでのセッション ID 再生成を同じ応答で拾うため)。
 *   - **未認証でも発行し、削除しない**。必ず現セッション由来の値で上書きする
 *     (「印が無い」状態を作らない = 画面側が欠落と失効を取り違えない)。
 *   - HttpOnly にしない (画面側が読む値であるため)。
 *     **セッション ID そのものではない**ので、読めても乗っ取りには使えない。
 *   - **属性は session cookie と同じものを使う**。`CookieJar` の既定は
 *     `Illuminate\Cookie\CookieServiceProvider` が session config の
 *     path / domain / secure / same_site から設定しているので、
 *     ここで自前に組み立てず `Cookie::make()` の既定へ委ねる
 *     (自前で組むと framework の規則から静かにずれる)。
 *   - 暗号化の除外登録 (bootstrap/app.php) が必須。外すと画面側は復号できない
 *     文字列を読み、常に不一致 = 復元のたびに読み直しになる (静かな劣化)。
 *     この配線は Feature テストが平文値そのもので固定する。
 */
final class IssueSessionEpochCookie
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // セッション ID の再生成 (ログイン・ログアウト) を同じ応答で拾うため、
        // 印は **$next の後** に導出する。
        $epoch = SessionEpoch::current($request);
        if ($epoch === null) {
            return $response;
        }

        $response->headers->setCookie(
            // 第 3 引数 0 = ブラウザセッション限り (印は「いまの世代」の写しであり保存する値ではない)。
            // path / domain / secure / sameSite は渡さない = session cookie と同じ既定が入る。
            Cookie::make(SessionEpoch::COOKIE_NAME, $epoch, 0, httpOnly: false),
        );

        return $response;
    }
}
