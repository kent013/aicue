<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * ログアウト応答 (Fortify contract bind)。
 *
 * Fortify 既定との違いは 2 点:
 *
 * 1. **`Inertia::clearHistory()` を呼ぶ**。ログアウトは Inertia の SPA visit
 *    (`AppLayout.svelte` の `router.post('/logout')`) で完結し、以降の「戻る」も
 *    `popstate` で完結するため、サーバの no-store baseline も
 *    `bfcache-guard.ts` (pagehide/pageshow) も発火しない。Inertia のクライアント履歴に
 *    残った認証済みページが PII 込みで復元される (bug-hunt F-4-01)。
 *    `clearHistory()` は `sessionStorage` の暗号鍵を捨てさせ、履歴エントリを復号不能にする。
 *    復号に失敗した Inertia は**コンポーネントを描画しないまま**サーバへ再問い合わせし、
 *    未認証なので `/login` へ倒れる。暗号化の有効化は
 *    `bootstrap/app.php` の `Inertia\Middleware\EncryptHistory` (web グループ) が担う。
 *
 * 2. **着地を `route('home')` に固定する** (`Fortify::redirects('logout')` を経由しない)。
 *    `clearHistory` フラグは session に積まれ「**次の Inertia 応答**」でしか消費されない
 *    (`Inertia\Response::__construct` の `session()->pull`)。着地が非 Inertia 応答になると
 *    フラグが宙に浮き、防御が**静かに**消える。設定 1 つで壊れる経路を残さない。
 *    着地 `/` は `HomeController` = `Inertia::render('Welcome')`。
 *    **この route を非 Inertia 化してはならない** (契約。Feature テストが固定する)。
 *
 * `wantsJson()` の 204 分岐は Fortify 既定と同値のまま残す (Inertia visit は
 * `X-Inertia` + Accept: text/html のため常に redirect 側を通る)。
 * `clearHistory()` は **両分岐の前に無条件で**呼ぶ。`X-Inertia` の有無で分岐しない:
 *   1. 非 Inertia の XHR ログアウトでも、そのタブには Inertia の暗号化履歴が残っている
 *      (実例: tests/Browser/AuthenticatedPageBfcacheTest.php の bfcacheLogoutInBrowser() は
 *      Inertia 画面から fetch('/logout', { Accept: 'application/json' }) でログアウトする)。
 *      分岐すると**履歴が復号可能なまま残り F-4-01 が再発する**。
 *   2. 防御の成立条件をクライアント種別に依存させない (条件分岐で不変条件を弱めない)。
 *
 * **無条件実行は必要条件であって十分条件ではない。** `clearHistory()` がやるのは
 * session にフラグを積むことだけで、`sessionStorage` の鍵が実際に消えるのは
 * **クライアントが `clearHistory: true` を含む Inertia page を適用した瞬間**
 * (`page.set()` 冒頭の `history.clear()`)。
 * 204 を受けて画面遷移しないままブラウザバックすると、鍵は生きており履歴は復号できる。
 * 経路 C が保証するのは
 * 「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」に限られる
 * (受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
 *
 * このアプリでは実運用上その条件を満たす: `/logout` を叩く導線は
 * `AppLayout.svelte` (通常画面のユーザーメニュー) と `pages/Auth/VerifyEmail.svelte`
 * (メール認証待ち画面の離脱導線) の 2 箇所で、**いずれも `router.post('/logout')` =
 * Inertia visit**。302 を XHR が追従し、**正常完了時に**着地の Inertia page を適用する。
 * JSON 204 経路はリポジトリ内では Browser テストの補助 (経路 B の再現) にしか使われていない。
 * **ログアウト導線を非 Inertia 経路で新設すると経路 C の保証条件が崩れる**。
 * この「一本である」不変条件は
 * `tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定する。
 *
 * なお「Inertia 応答を一度も描画しないまま再ログイン」した場合はフラグが持ち越されるが
 * (`session()->regenerate()` はデータを引き継ぐ)、その場合に失われるのは
 * ログイン前 (guest) の履歴エントリの復号可能性だけで無害
 * (以降のエントリは新しい鍵で暗号化される)。
 *
 * 呼ばれる順序: `AuthenticatedSessionController::destroy()` が
 * `guard->logout()` → `session()->invalidate()` → `session()->regenerateToken()` を終えた**後**に
 * 本クラスが解決され、`toResponse()` はさらに後 (Router の Responsable 解決時) に走る。
 * よって `clearHistory()` の session 書き込みは invalidate 後の新しい session に載り、
 * 着地の Inertia 応答まで確実に届く。
 */
final class LogoutResponse implements LogoutResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        // 認証済みページの Inertia 履歴 (暗号化済み) を復号不能にする。
        Inertia::clearHistory();

        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->route('home');
    }
}
