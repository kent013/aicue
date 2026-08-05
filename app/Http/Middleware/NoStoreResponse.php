<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 応答に `no-store` を無条件で保証する middleware。alias: `no-store`。
 *
 * NoStoreCacheHeadersForAuthenticatedPages は「認証済みか」で対象を決めるため、
 * **guest route** は対象外になる。WebAuthn の challenge (random_bytes(32)) を載せる
 * `passkey.login-options` は guest route であり、キャッシュされると challenge の
 * 使い回しやログイン導線の破綻を招くため個別に保証する。
 *
 * 既に `no-store` を持つ応答は書き換えない (directive が縮む方向の上書きをしない)。
 * 付与対象は tests/Architecture/PasskeyRouteProtectionTest が固定する。
 */
final class NoStoreResponse
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->headers->hasCacheControlDirective('no-store')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
