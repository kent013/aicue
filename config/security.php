<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security Headers
|--------------------------------------------------------------------------
| SecurityHeaders middleware が参照する。値は env 駆動で、production の
| 必須値は `php artisan production:preflight` が検査する。
*/

return [

    'hsts' => [
        // HTTPS 配信が確立してから有効化する。max-age は段階的に伸ばす (本番最終値: 31536000)
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', false),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 300),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        // hstspreload.org への提出は max-age >= 31536000 + includeSubDomains が前提。
        // 慣熟後に max-age を伸ばしてから有効化する (rollback 困難なので最後に上げる knob)
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],

    /*
    | Permissions-Policy: Powerful Features API の利用面を縮小する。
    | 値は参照アプリ共通の header contract 文字列。payment=(self "https://js.stripe.com")
    | は Stripe payment surface (Cashier) 互換のための allowlist。
    | env 上書き可。null / 空文字でヘッダ非送出 (opt-out, env による一時 rollback)。
    */
    'permissions_policy' => env(
        'SECURITY_PERMISSIONS_POLICY',
        'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
    ),

    /*
    | 撮影 document route 専用の Permissions-Policy override。撮影 recorder (CameraRecorder) が
    | getUserMedia({ video, audio: true }) を要求するため camera/microphone を (self) に緩める
    | (同一オリジン PWA のみ許可 = v1 スコープ)。geolocation / payment 等の他 directive は baseline
    | と同一に保つ。env 上書き可。null / 空文字でヘッダ非送出 (opt-out, env による一時 rollback)。
    */
    'capture_permissions_policy' => env(
        'SECURITY_CAPTURE_PERMISSIONS_POLICY',
        'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")',
    ),

    /*
    | capture 用 Permissions-Policy を適用する route 名 allowlist (least-privilege)。
    | Permissions-Policy は document 単位に効くため、recorder を描画する撮影 document route のみ
    | 緩和し、他の capture HTML document (一覧等) や未解決 404 は baseline (厳格値) のままにする。
    | 将来撮影画面が増えたら本 allowlist へ明示追加する (追加はレビュー対象になる)。
    */
    'capture_permissions_policy_routes' => ['capture.manuals.show'],

    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),
        /*
        | CSP は directive 連想配列 (directive => value) から middleware が組み立てる。
        | 外部サービスを導入するときは該当 directive に host-source を追記する
        | (下のコメントアウト例を参照)。
        |
        | 注意: form-action は「form 送信後のリダイレクト先」にも適用される (Chrome)。
        | SSO 開始を form POST にすると IdP へのリダイレクトがブロックされるため、
        | SSO 開始導線は GET の anchor にしている (App\Http\Controllers\Auth\SocialAuthController)。
        | form POST で外部に遷移する必要が生じたら form-action にそのドメインを追加すること。
        */
        'directives' => [
            'default-src' => "'self'",
            // 既定は strict。GTM を実際に読み込むとき (production + GTM_CONTAINER_ID の二重ゲート)
            // だけ SecurityHeaders が下の gtm_directives を該当 directive にマージして緩める。
            // これにより GTM を使わない既定テンプレの XSS baseline を緩めない。
            // Stripe.js 導入例: script-src 末尾に " https://js.stripe.com https://checkout.stripe.com" を追記。
            'script-src' => "'self'",
            'style-src' => "'self' 'unsafe-inline' https://fonts.googleapis.com",
            'font-src' => "'self' https://fonts.gstatic.com",
            'img-src' => "'self' data:",
            // connect-src / frame-src は既定では default-src ('self') にフォールバックさせ、
            // GTM 有効時のみ gtm_directives で明示追加する。
            'form-action' => "'self'",
            'frame-ancestors' => "'none'",
            'base-uri' => "'self'",
            'object-src' => "'none'",
        ],

        /*
        | GTM/GA4 用の CSP overlay。GTM を実際に描画する条件 (App\Support\GoogleTagManager::isEnabled()
        | = production かつ container_id 非空) が成立するときだけ SecurityHeaders が
        | 上記 directives にマージする (既存キーは上書き、connect-src / frame-src は追加)。
        | GTM の inline bootstrap snippet 用に script-src へ 'unsafe-inline' を足すが、
        | この緩和は GTM 有効時に限定され、既定 (GTM off) の script-src は 'self' のまま。
        */
        'gtm_directives' => [
            'script-src' => "'self' 'unsafe-inline' https://www.googletagmanager.com",
            'img-src' => "'self' data: https://www.googletagmanager.com https://*.google-analytics.com https://*.googletagmanager.com",
            'connect-src' => "'self' https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com",
            'frame-src' => "'self' https://www.googletagmanager.com",
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth metadata endpoint (`.well-known/oauth-*`) 用ヘッダ subset
    |--------------------------------------------------------------------------
    |
    | 公開 JSON metadata endpoint は web の baseline (CSP / X-Frame-Options /
    | Permissions-Policy) とは性質が異なるため、最小限のヘッダだけ与える:
    |
    | - X-Content-Type-Options: nosniff — JSON を script として誤解釈させない
    | - Referrer-Policy: no-referrer   — 後続クリック時の URL 漏洩抑止
    | - Strict-Transport-Security      — `metadata_hsts_enabled=true` 時のみ付与
    |
    | CSP / X-Frame-Options は browser 直叩き想定外の JSON endpoint のため意図的に
    | 付けず、不要な header で client / proxy 互換性を狭めない。
    */
    'metadata_headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
    ],
    'metadata_hsts_enabled' => (bool) env('METADATA_HSTS_ENABLED', false),

    // HTTP → HTTPS の app 層 308 リダイレクト (Q9)。
    // LB (ALB 等) が HTTPS 終端とリダイレクトを担う構成では false にする。
    'force_https_redirect' => (bool) env('FORCE_HTTPS_REDIRECT', false),

];
