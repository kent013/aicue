<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * クローラ差異に強くするため `X-Robots-Tag: noindex` を付与する。
 * blade / Inertia ページ側の <meta name="robots"> に加えた二重防御。
 * 文面未確定の法的スタブ等、検索露出させたくない公開 route に付ける。
 */
class NoIndex
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
