<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\RecentAuthRequiredDto;
use App\Http\Resources\Auth\RecentAuthRequiredResource;
use App\Security\RecentAuthWindow;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 機微操作の前に generic recent-auth (step-up 再認証) を強制する単一ゲート。
 * alias: `recent-auth`。
 *
 * Fortify 生の `password.confirm` (password 専用・3h 窓) を置き換える。satisfier は
 * ConfirmRecentAuthController (password 再入力) と SocialAuthController の step-up intent
 * (再SSO) に集約され、SSO-only ユーザーも fail-closed で詰まずに再SSO へ誘導される。
 *
 * 判定:
 *   1. `recent_auth_at` が鮮度ウィンドウ内 (RecentAuthWindow) → 通過
 *   2. XHR (expectsJson) または Inertia の非 GET → 409 + { code, message, redirect }(no-store)。
 *      クライアント (素 fetch / recent-auth precheck) が再認証後に元操作を再送
 *   3. それ以外 (通常遷移) → 302 で recent-auth confirm 画面へ。元 URL を intended に保持
 */
final class RequireRecentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if (RecentAuthWindow::isFresh($session->get('recent_auth_at'))) {
            $response = $next($request);
            if (! $response instanceof Response) {
                throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
            }

            return $response;
        }

        $confirmUrl = route('recent-auth.confirm');

        // XHR (expectsJson) と Inertia の非 GET visit は 409 + code。クライアントが再認証後に
        // 元操作を再送する。Inertia GET は従来どおり 302 → confirm → intended GET replay が
        // 機能するため対象外。409 に x-inertia-location / x-inertia-redirect ヘッダを付けない
        // こと (Inertia core の external redirect 信号と衝突するため)。
        if ($request->expectsJson() || $this->isInertiaMutation($request)) {
            return RecentAuthRequiredResource::make(new RecentAuthRequiredDto(
                message: 'この操作には直近の再認証が必要です。',
                redirect: $confirmUrl,
            ))
                ->response()
                ->setStatusCode(409)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        // GET は fullUrl (自 origin 確定)、それ以外は遷移元が無いので referer を intended に。
        // referer はクライアント制御ヘッダで外部 URL になり得るため、same-origin のみ採用し
        // それ以外 (外部 origin / 不在) は dashboard へフォールバックする (open redirect 防止)。
        $intended = $request->isMethod('GET')
            ? $request->fullUrl()
            : $this->sameOriginRefererOrDashboard($request);
        $session->put('url.intended', $intended);

        // 非 GET の 302 fallback (非 Inertia の素フォーム POST 等) は mutation body を保持できない。
        // confirm 成功後に「もう一度操作してください」を案内するための one-shot flag
        // (サイレント喪失防止の defense-in-depth、satisfier 側が消費する)。
        if (! $request->isMethod('GET')) {
            $session->put('recent_auth.dropped_mutation', true);
        }

        return redirect()->route('recent-auth.confirm');
    }

    /**
     * Inertia protocol の mutation visit (X-Inertia ヘッダ + 非 GET)。
     * Accept は text/html のため expectsJson() では捕捉できない。
     */
    private function isInertiaMutation(Request $request): bool
    {
        return $request->hasHeader('X-Inertia') && ! $request->isMethod('GET');
    }

    private function sameOriginRefererOrDashboard(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if ($referer === null) {
            return route('dashboard');
        }

        // 完全一致 or 「origin + '/'」前置一致のみ same-origin と判定する。
        // 単純な str_starts_with($referer, $origin) だと https://app.host.evil.com を通すため、
        // 区切り '/' まで含めて比較する。
        $origin = $request->getSchemeAndHttpHost();
        if ($referer === $origin || str_starts_with($referer, $origin.'/')) {
            return $referer;
        }

        return route('dashboard');
    }
}
