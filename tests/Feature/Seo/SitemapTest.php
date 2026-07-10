<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * sitemap.xml (route: seo.sitemap) の Feature テスト。
 * 掲載集合は config('seo.sitemap_routes') 駆動 (初期値は home のみ)。
 */

it('sitemap.xml は config の公開ページのみを APP_URL 基準で配信する', function (): void {
    config(['seo.base_url' => 'https://app.example']);

    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    $body = (string) $response->getContent();
    expect($body)->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        ->toContain('<loc>https://app.example/</loc>')
        ->toContain('<changefreq>weekly</changefreq>')
        // 認証配下 / 認証画面は載せない
        ->and($body)->not->toContain('/dashboard')
        ->and($body)->not->toContain('/login');
});

it('sitemap.xml は Cache-Control (public) を返す', function (): void {
    config(['seo.base_url' => 'https://app.example']);

    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('public')
        ->toContain('max-age=3600');
});

it('sitemap_routes に追加した route が entry として描画される', function (): void {
    config([
        'seo.base_url' => 'https://app.example',
        'seo.sitemap_routes' => [
            'home' => ['changefreq' => 'weekly', 'priority' => '1.0'],
            'contact' => ['changefreq' => 'monthly', 'priority' => '0.5'],
        ],
    ]);

    $body = (string) $this->get('/sitemap.xml')->getContent();

    expect($body)->toContain('<loc>https://app.example/contact</loc>')
        ->toContain('<changefreq>monthly</changefreq>')
        ->toContain('<priority>0.5</priority>');
});

it('sitemap_routes の route 名はすべて実在する (drift guard)', function (): void {
    /** @var array<string, mixed> $routes */
    $routes = config('seo.sitemap_routes');

    expect($routes)->not->toBeEmpty();
    foreach (array_keys($routes) as $name) {
        expect(Route::has($name))->toBeTrue("seo.sitemap_routes の route '{$name}' が未登録");
    }
});
