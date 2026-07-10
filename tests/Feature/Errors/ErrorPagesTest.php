<?php

declare(strict_types=1);

// 自己完結エラーページ: Vite/Inertia/DB が壊れた 500 経路でも確実に描画できるよう、
// error blade は @vite / build asset / Inertia に依存せず inline CSS で自己完結すること。

it('renders every customer error view self-contained (no build/vite/inertia deps)', function (string $view): void {
    $html = view($view)->render();

    expect($html)->toContain('<style>')            // inline CSS で自己完結
        ->toContain('name="robots" content="noindex"')
        ->not->toContain('/build/')                // ビルド済み asset に依存しない
        ->not->toContain('@vite')
        ->not->toContain('data-page');             // Inertia マウントに依存しない
})->with([
    'errors.401',
    'errors.403',
    'errors.404',
    'errors.419',
    'errors.429',
    'errors.500',
    'errors.503',
]);

it('serves the custom 404 page for unknown routes', function (): void {
    $response = $this->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())
        ->toContain('ページが見つかりません')
        ->toContain('name="robots" content="noindex"')
        ->not->toContain('/build/');
});

it('renders the admin error layout with a neutral operator tone (no customer branding)', function (): void {
    $html = view('errors.admin.4xx', ['status' => 404, 'adminPath' => 'admin'])->render();

    expect($html)->toContain('<style>')
        ->toContain('管理パネルに戻る')
        ->toContain('name="robots" content="noindex"')
        ->toContain('href="/admin"');
});
