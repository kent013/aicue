<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

// 法的スタブ (/terms /privacy /commerce-disclosure) は文面未確定のプレースホルダのため
// noindex を二重防御 (blade の <meta robots> + NoIndex middleware の X-Robots-Tag) する。
// /pricing は公開 Inertia 雛形 (index させる)。

it('serves each legal stub with noindex double-defense', function (string $path): void {
    $response = $this->get($path);

    $response->assertOk();
    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex');
    expect((string) $response->getContent())->toContain('name="robots" content="noindex"');
})->with([
    '/terms',
    '/privacy',
    '/commerce-disclosure',
]);

it('renders the public pricing page as an indexable Inertia stub', function (): void {
    $response = $this->get('/pricing');

    $response->assertOk();
    // 公開ページなので noindex を付けない。
    expect($response->headers->get('X-Robots-Tag'))->toBeNull();

    $response->assertInertia(fn (Assert $page) => $page->component('Pricing'));
});
