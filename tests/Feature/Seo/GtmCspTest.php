<?php

declare(strict_types=1);

// CSP (config/security.php) は既定では strict (script-src 'self')。GTM を実際に読み込む
// 条件 (production + GTM_CONTAINER_ID の二重ゲート) のときだけ GTM/GA4 の host-source と
// 'unsafe-inline' を該当 directive にマージする。既定テンプレの XSS baseline は緩めない。

it('keeps a strict CSP baseline when GTM is disabled (default template)', function (): void {
    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

    expect($csp)->not->toBe('');

    // 既定は strict: script-src に 'unsafe-inline' / GTM ホストを持たない。
    expect($csp)->toContain("script-src 'self';")
        ->not->toContain('googletagmanager.com')
        ->not->toContain('google-analytics.com');

    // baseline directive が保持されている。
    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("object-src 'none'");
});

it('relaxes CSP for GTM/GA4 only when GTM is enabled (production + container id)', function (): void {
    config([
        'app.env' => 'production',
        'services.google_tag_manager.container_id' => 'GTM-TEST',
    ]);

    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

    // GTM script + GA4 connect。
    expect($csp)->toContain('script-src')
        ->toContain("'unsafe-inline'")
        ->toContain('https://www.googletagmanager.com')
        ->toContain('https://www.google-analytics.com')
        ->toContain('https://*.analytics.google.com');

    // frame-src は GTM noscript iframe、img-src は GTM/GA4 beacon を許可する。
    expect($csp)->toMatch('/frame-src[^;]*https:\/\/www\.googletagmanager\.com/');
    expect($csp)->toMatch('/img-src[^;]*https:\/\/www\.googletagmanager\.com/');
    expect($csp)->toMatch('/img-src[^;]*https:\/\/\*\.google-analytics\.com/');

    // baseline directive は保持されている。
    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("object-src 'none'");
});
