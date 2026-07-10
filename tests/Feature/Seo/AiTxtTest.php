<?php

declare(strict_types=1);

use App\Support\Seo\CrawlPolicy;

/*
 * ai.txt (route: seo.ai) の Feature テスト。
 * Disallow 集合は robots.txt と同じ CrawlPolicy 単一ソース。
 */

it('ai.txt の Disallow 集合が CrawlPolicy と一致する', function (): void {
    $response = $this->get('/ai.txt');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/plain');

    $body = (string) $response->getContent();
    expect($body)->toContain('User-agent: *');

    foreach ((new CrawlPolicy)->disallowedPaths() as $path) {
        expect($body)->toContain('Disallow: '.$path);
    }
});
