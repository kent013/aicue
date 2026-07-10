<?php

declare(strict_types=1);

// GTM snippet は「production かつ GTM_CONTAINER_ID 非空」の二重ゲートでのみ描画する。
// App\Support\Environment が config('app.env') を直読するため、テストで env を上書きできる。

it('renders GTM snippet on home in production when container id is set', function (): void {
    config([
        'app.env' => 'production',
        'services.google_tag_manager.container_id' => 'GTM-TEST',
    ]);

    $html = (string) $this->get('/')->getContent();

    expect($html)->toContain('googletagmanager.com/gtm.js')
        ->toContain('GTM-TEST')
        ->toContain('googletagmanager.com/ns.html?id=GTM-TEST');
});

it('does not render GTM snippet outside production', function (): void {
    config([
        'app.env' => 'testing',
        'services.google_tag_manager.container_id' => 'GTM-TEST',
    ]);

    $html = (string) $this->get('/')->getContent();

    expect($html)->not->toContain('googletagmanager.com/gtm.js');
});

it('does not render GTM snippet in production when container id is unset', function (): void {
    config([
        'app.env' => 'production',
        'services.google_tag_manager.container_id' => null,
    ]);

    $response = $this->get('/');
    $response->assertOk();

    expect((string) $response->getContent())->not->toContain('googletagmanager.com/gtm.js');
});
