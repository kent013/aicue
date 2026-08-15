<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 認証済みリクエストの web 応答に `no-store` を保証する baseline middleware。
 *
 * 目的: ログアウト後のブラウザ「戻る」で認証済み画面 (メンバー一覧等の PII) が
 * 再表示されるのを防ぐ。あわせて disk / proxy cache への認証済み応答の残留も禁じる。
 *
 * **保存禁止ヘッダは「戻る」用の一時保存 (bfcache) への格納を禁じる指示ではない。**
 * 格納するか・いつ捨てるかはブラウザの実装判断であり、このヘッダだけで復元を止められる
 * 保証はどのブラウザについても持っていない。ブラウザごとの観測と一次情報の日付は
 * docs/supported-browsers.md が正本である。
 * したがって本 middleware は復元経路 B を単独では塞げず、クライアント側の
 * bfcache 秘匿・再検証 (resources/js/lib/bfcache-guard.ts +
 * セッション世代の印 App\Support\Auth\SessionEpoch) と **セットで** 主便益を達成する。
 *
 * さらに Inertia SPA のクライアント履歴復元 (popstate) はサーバへリクエストが飛ばないため
 * 本 middleware も bfcache guard も発火しない。その経路は Inertia 公式機構
 * (bootstrap/app.php の Inertia\Middleware\EncryptHistory +
 * App\Http\Responses\Fortify\LogoutResponse の Inertia::clearHistory()) が担当する
 * (bug-hunt F-4-01)。**3 経路 × 3 枚の網の全体像は docs/supported-browsers.md が正本**。
 *
 * 適用判定は route 列挙ではなく「認証済みか」で行う (path 列挙は一般認証画面を
 * 取りこぼす)。guest / 公開ページ (login・LP・SEO) は対象外のままにし bfcache /
 * 共有キャッシュの恩恵を維持する。認証済み画面は Inertia SPA でアプリ内の戻る/進むは
 * client-side navigation のため bfcache 喪失による UX 後退はない。
 */
final class NoStoreCacheHeadersForAuthenticatedPages
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // logout POST は $next 通過後に guard 上の user が null になるため、
        // リクエスト時点の認証状態を先に捕捉する (= logout redirect も対象に含める)。
        $wasAuthenticated = $this->isAuthenticated($request);

        $response = $next($request);

        // リクエスト時点 or 応答時点のどちらかで認証済みなら付与対象
        // (login POST 応答 = 応答時点で認証済み、も保護側に倒す)。
        if (! $wasAuthenticated && ! $this->isAuthenticated($request)) {
            return $response;
        }

        // 既に no-store を持つ応答 (recent-auth 409 / 2FA 409 / 署名 URL redirect 等、
        // 内側で明示されたより厳格な値) は書き換えず維持する。
        // directive が縮む方向の上書きをしない。
        if ($response->headers->hasCacheControlDirective('no-store')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * 本 middleware の対象は session-backed な web 認証画面。session を持たない
     * リクエスト (routes/web.php の stateless block: SEO/robots/公開ページは
     * StartSession を withoutMiddleware 済) は stateless 公開配信であり対象外。
     */
    private function isAuthenticated(Request $request): bool
    {
        return $request->hasSession() && $request->user() !== null;
    }
}
