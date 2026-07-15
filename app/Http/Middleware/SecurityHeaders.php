<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\GoogleTagManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * baseline セキュリティヘッダ (config/security.php 駆動)。
 * Vite dev server を使う local では CSP を緩和せず、必要なら env で上書きする。
 *
 * `.well-known/oauth-*` (OAuth/MCP discovery metadata) は client が programmatic
 * fetch する JSON のため、フル baseline (CSP / X-Frame-Options / Permissions-Policy)
 * は当てず、nosniff + no-referrer の最小 subset のみ適用して early return する。
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // OAuth/MCP discovery metadata は最小 subset のみ (config: security.metadata_headers)
        if ($request->is('.well-known/oauth-*')) {
            return $this->applyMetadataSubset($response);
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions-Policy は常時送出。撮影 document route (config allowlist に一致) のみ
        // camera/microphone を (self) に緩めた capture 用値を送る。null / 空文字は opt-out (非送出)。
        $permissionsPolicy = $this->resolvePermissionsPolicy($request);
        if (is_string($permissionsPolicy) && $permissionsPolicy !== '') {
            $response->headers->set('Permissions-Policy', $permissionsPolicy);
        }

        if (config()->boolean('security.hsts.enabled')) {
            $response->headers->set('Strict-Transport-Security', $this->buildHstsValue());
        }

        if (config()->boolean('security.csp.enabled')) {
            $directives = config()->array('security.csp.directives');

            // GTM を実際に描画する条件下 (production + container_id) でのみ GTM/GA4 用の
            // host-source overlay を該当 directive にマージする (既定は strict のまま)。
            if (GoogleTagManager::isEnabled()) {
                $directives = array_merge($directives, config()->array('security.csp.gtm_directives'));
            }

            $parts = [];
            foreach ($directives as $directive => $value) {
                if (is_string($directive) && is_string($value)) {
                    $parts[] = trim($directive.' '.$value);
                }
            }
            if ($parts !== []) {
                $response->headers->set('Content-Security-Policy', implode('; ', $parts));
            }
        }

        return $response;
    }

    /**
     * 送出する Permissions-Policy 値を決める。
     *
     * 撮影 document route (security.capture_permissions_policy_routes の allowlist に一致) では
     * camera/microphone を (self) に緩めた capture 用値 (security.capture_permissions_policy) を、
     * それ以外は baseline 値 (security.permissions_policy) を返す。null / 空文字は呼び出し側で
     * opt-out (非送出) として扱う。allowlist が空、または route 未解決 (例: /app 配下の 404) では
     * routeIs が false となり baseline に落ちる (fail-secure)。
     */
    private function resolvePermissionsPolicy(Request $request): ?string
    {
        /** @var list<string> $captureRoutes */
        $captureRoutes = array_values(array_filter(
            config()->array('security.capture_permissions_policy_routes'),
            is_string(...),
        ));

        $key = ($captureRoutes !== [] && $request->routeIs(...$captureRoutes))
            ? 'security.capture_permissions_policy'
            : 'security.permissions_policy';

        $value = config($key);

        return is_string($value) ? $value : null;
    }

    /**
     * metadata endpoint (`.well-known/oauth-*`) 向けの最小 subset を当てる。
     *
     * CSP / X-Frame-Options / Permissions-Policy は付けない (JSON discovery には
     * 不要・不適切)。HSTS は `security.metadata_hsts_enabled` が true のときのみ
     * baseline と同じ値を当てる (dev/staging での誤適用を防ぐため別 knob)。
     */
    private function applyMetadataSubset(Response $response): Response
    {
        /** @var array<mixed, mixed> $headers */
        $headers = config()->array('security.metadata_headers');
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $response->headers->set($name, $value);
            }
        }

        if (config()->boolean('security.metadata_hsts_enabled')) {
            $response->headers->set('Strict-Transport-Security', $this->buildHstsValue());
        }

        return $response;
    }

    /**
     * Strict-Transport-Security 値を config 駆動で組み立てる。
     * includeSubDomains / preload directive は各 knob が true のとき順に付与する。
     */
    private function buildHstsValue(): string
    {
        $value = 'max-age='.config()->integer('security.hsts.max_age');
        if (config()->boolean('security.hsts.include_subdomains')) {
            $value .= '; includeSubDomains';
        }
        if (config()->boolean('security.hsts.preload')) {
            $value .= '; preload';
        }

        return $value;
    }
}
