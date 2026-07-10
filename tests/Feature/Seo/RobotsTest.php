<?php

declare(strict_types=1);

use App\Support\Seo\CrawlPolicy;
use Illuminate\Support\Facades\File;

/*
 * robots.txt (route: seo.robots) の Feature テスト。
 * Disallow 集合は CrawlPolicy が単一ソース (ai.txt とドリフトしない)。
 * Sitemap 行は SeoUrl (= APP_URL 正本) 基準で Host ヘッダ非依存。
 */

it('robots.txt を CrawlPolicy の Disallow 集合 + Sitemap 行で配信する', function (): void {
    config(['seo.base_url' => 'https://app.example']);

    $response = $this->get('/robots.txt');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/plain');

    $body = (string) $response->getContent();
    expect($body)->toContain('User-agent: *')
        ->toContain('Sitemap: https://app.example/sitemap.xml');

    foreach ((new CrawlPolicy)->disallowedPaths() as $path) {
        expect($body)->toContain('Disallow: '.$path);
    }
});

it('robots.txt は Cache-Control (public) を返す', function (): void {
    config(['seo.base_url' => 'https://app.example']);

    $response = $this->get('/robots.txt');

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('public')
        ->toContain('max-age=3600');
});

it('静的 public/robots.txt を同梱しない (route が shadow されない)', function (): void {
    expect(File::exists(public_path('robots.txt')))->toBeFalse();
});
