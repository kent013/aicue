<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP → HTTPS の app 層リダイレクト (308 = メソッド維持)。
 *
 * `FORCE_HTTPS_REDIRECT=true` で有効化 (Q9 決定)。
 * ALB 等の LB が HTTPS 終端とリダイレクトを担う構成では不要 (false のまま)。
 * trustProxies 設定済みなら $request->isSecure() は X-Forwarded-Proto を見る。
 */
class RedirectToHttps
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config()->boolean('security.force_https_redirect') && ! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 308);
        }

        return $next($request);
    }
}
