<?php

declare(strict_types=1);

use App\Support\Seo\JsonLd;

/*
 * JsonLd: schema.org ノード builder。価格は nullable 引数で、
 * null のとき offers を出さない (誤った structured data を出さない)。
 */

it('organization ノードを生成する', function (): void {
    $node = JsonLd::organization('Acme', 'https://app.example', 'https://app.example/images/logo.svg');

    expect($node)->toBe([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'Acme',
        'url' => 'https://app.example',
        'logo' => 'https://app.example/images/logo.svg',
    ]);
});

it('website ノードを生成する', function (): void {
    $node = JsonLd::website('Acme', 'https://app.example');

    expect($node)->toBe([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Acme',
        'url' => 'https://app.example',
    ]);
});

it('softwareApplication は価格 null なら offers を出さない', function (): void {
    $node = JsonLd::softwareApplication('Acme', 'https://app.example', '説明文', null);

    expect($node['@type'])->toBe('SoftwareApplication')
        ->and($node['applicationCategory'])->toBe('BusinessApplication')
        ->and($node)->not->toHaveKey('offers');
});

it('softwareApplication は価格ありなら AggregateOffer を付す', function (): void {
    $node = JsonLd::softwareApplication('Acme', 'https://app.example', '説明文', 980);

    expect($node['offers'])->toBe([
        '@type' => 'AggregateOffer',
        'priceCurrency' => 'JPY',
        'lowPrice' => 980,
    ]);
});
