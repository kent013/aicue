<?php

declare(strict_types=1);

use App\Support\Seo\SeoMeta;
use App\Support\Seo\SeoUrl;

/*
 * SeoMeta: 不変 DTO。default() は config 起点 + base_url (Host ヘッダ非依存) で組み立てる。
 */

beforeEach(function (): void {
    config([
        'seo.base_url' => 'https://app.example',
        'seo.site_name' => 'Acme',
        'seo.default_title' => 'Acme',
        'seo.title_separator' => ' | ',
        'seo.default_description' => '既定の説明文',
        'seo.og_default_image' => '/images/og-default.png',
        'seo.twitter_card' => 'summary_large_image',
    ]);
});

it('default() は絶対 canonical / og:image を持つ既定メタを組み立てる', function (): void {
    $meta = SeoMeta::default(new SeoUrl('https://app.example'), '/');

    expect($meta->canonical)->toBe('https://app.example/')
        ->and($meta->ogImage)->toBe('https://app.example/images/og-default.png')
        ->and($meta->ogType)->toBe('website')
        ->and($meta->twitterCard)->toBe('summary_large_image')
        ->and($meta->title)->toBe('Acme')
        ->and($meta->description)->toBe('既定の説明文');
});

it('withTitle はセパレータ + サイト名を合成する', function (): void {
    $meta = SeoMeta::default(new SeoUrl('https://app.example'), '/pricing')
        ->withTitle('料金プラン');

    expect($meta->title)->toBe('料金プラン | Acme');
});

it('withTitle でサイト名と同名なら二重化しない', function (): void {
    $meta = SeoMeta::default(new SeoUrl('https://app.example'), '/')
        ->withTitle('Acme');

    expect($meta->title)->toBe('Acme');
});

it('withDescription / withJsonLd は他フィールドを保持する', function (): void {
    $meta = SeoMeta::default(new SeoUrl('https://app.example'), '/')
        ->withDescription('説明文');

    expect($meta->description)->toBe('説明文')
        ->and($meta->canonical)->toBe('https://app.example/')
        ->and($meta->jsonLd)->toBe([]);

    $withJsonLd = $meta->withJsonLd([['@type' => 'WebSite']]);
    expect($withJsonLd->jsonLd)->toBe([['@type' => 'WebSite']])
        ->and($withJsonLd->description)->toBe('説明文');
});
