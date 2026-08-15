<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    'redirect_domains' => [
        '*',
        // 'https://example.com',
        // 'http://localhost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        // 'claude',
        // 'cursor',
        // 'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | MCP Allowed Origins (テンプレート追加)
    |--------------------------------------------------------------------------
    |
    | /api/v1/mcp に対する Origin header allowlist。VerifyMcpOrigin が検証する。
    | env (MCP_ALLOWED_ORIGINS) は CSV で指定する。
    |
    | fail-closed 設計:
    | - production で未設定 → 空配列 (middleware が全 request を 403)
    | - production で bare `*` → middleware が拒否
    | - 非 production は dev 向けデフォルト allowlist
    |
    | 注: config boot 時点では app() が使えないため env('APP_ENV') を直接参照する。
    */

    'allowed_origins' => (function (): array {
        $value = env('MCP_ALLOWED_ORIGINS');
        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));
        }

        if (env('APP_ENV') === 'production') {
            return [];
        }

        return [
            'http://localhost',
            'http://127.0.0.1',
            'https://claude.ai',
        ];
    })(),

    /*
    |--------------------------------------------------------------------------
    | MCP Strict Transport (テンプレート追加)
    |--------------------------------------------------------------------------
    |
    | Origin header を送らないリクエストの扱い (VerifyMcpOrigin)。
    | true  = Origin 欠落を 403 で拒否 (ブラウザ前提の厳格運用)
    | false = Origin 欠落を許可 (Origin を送らない CLI / デスクトップクライアント向け)
    |
    */

    'strict_transport' => (bool) env('MCP_STRICT_TRANSPORT', true),

];
