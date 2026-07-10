<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Webmozart\Assert\Assert;

/**
 * MCP Streamable HTTP の前提条件を強制する。
 * - POST method のみ
 * - Accept: application/json を含む
 * - Content-Type: application/json
 */
final class EnforceMcpTransport
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            throw new BadRequestHttpException('MCP requires POST.');
        }

        $accept = (string) $request->header('Accept');
        if (! str_contains($accept, 'application/json')) {
            throw new BadRequestHttpException('Accept must include application/json.');
        }

        if (! $request->isJson()) {
            throw new BadRequestHttpException('Content-Type must be application/json.');
        }

        $response = $next($request);
        Assert::isInstanceOf($response, Response::class);

        return $response;
    }
}
