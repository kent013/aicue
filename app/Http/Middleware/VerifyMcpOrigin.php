<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Webmozart\Assert\Assert;

/**
 * MCP エンドポイントの Origin allowlist 検証 (DNS rebinding / CSRF 系対策)。
 *
 * - Origin 提示時: config('mcp.allowed_origins') に一致しなければ 403
 * - allowlist が空: fail-closed で全拒否 (env 未設定での事故的全開放を防ぐ)
 * - production で bare `*`: 拒否 (非 production のみ `*` で全許可できる)
 * - Origin 欠落時: config('mcp.strict_transport') に従う
 *   (true = 403 / false = 非ブラウザクライアントとして通す)
 */
final class VerifyMcpOrigin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if (! is_string($origin) || $origin === '') {
            if (config()->boolean('mcp.strict_transport', true)) {
                throw new AccessDeniedHttpException('Origin header is required for MCP requests.');
            }

            // 非 strict: Origin を送らないクライアント (CLI 等) を通す
            $response = $next($request);
            Assert::isInstanceOf($response, Response::class);

            return $response;
        }

        $allowed = $this->resolveAllowedOrigins();
        $isProduction = app()->environment('production');

        if ($allowed === []) {
            // production で env 未設定 → 常に 403 (fail-closed)。
            // 非 production でも空は許可しない (誤設定事故防止)。
            throw new AccessDeniedHttpException('MCP origin allowlist is not configured.');
        }

        if ($isProduction && in_array('*', $allowed, true)) {
            // production では `*` 単独許可を拒否する
            throw new AccessDeniedHttpException('Wildcard origin is not permitted in production.');
        }

        if (! in_array('*', $allowed, true) && ! in_array($origin, $allowed, true)) {
            throw new AccessDeniedHttpException('Origin not allowed for MCP requests.');
        }

        $response = $next($request);
        Assert::isInstanceOf($response, Response::class);

        return $response;
    }

    /** @return list<string> */
    private function resolveAllowedOrigins(): array
    {
        /** @var mixed $configured */
        $configured = config('mcp.allowed_origins', []);
        $allowed = [];
        if (is_array($configured)) {
            foreach ($configured as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $allowed[] = $entry;
                }
            }
        } elseif (is_string($configured)) {
            foreach (explode(',', $configured) as $entry) {
                $entry = trim($entry);
                if ($entry !== '') {
                    $allowed[] = $entry;
                }
            }
        }

        return $allowed;
    }
}
