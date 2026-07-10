<?php

declare(strict_types=1);

use App\Support\Seo\SeoMeta;
use App\Support\Seo\SeoRenderer;
use App\Support\Seo\SeoUrl;

/*
 * SeoRenderer: エスケープ (HTML / JSON) 一元化。render() = 公開ページの完全ヘッド、
 * renderPrivate() = SEO 非対象ページの title + noindex のみ。
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
        'seo.locale' => 'ja_JP',
    ]);
});

/** @param list<array<string, mixed>> $jsonLd */
function renderSeoMeta(array $jsonLd = []): string
{
    $meta = SeoMeta::default(new SeoUrl('https://app.example'), '/')
        ->withTitle('Acme')
        ->withJsonLd($jsonLd);

    return (new SeoRenderer)->render($meta, 'home')->toHtml();
}

it('title / description / canonical / og / twitter を描画する', function (): void {
    $html = renderSeoMeta();

    expect($html)->toContain('<title>Acme</title>')
        ->toContain('<meta name="description" content="既定の説明文">')
        ->toContain('<link rel="canonical" href="https://app.example/">')
        ->toContain('<meta property="og:title" content="Acme">')
        ->toContain('<meta property="og:url" content="https://app.example/">')
        ->toContain('<meta property="og:image" content="https://app.example/images/og-default.png">')
        ->toContain('<meta property="og:site_name" content="Acme">')
        ->toContain('<meta property="og:locale" content="ja_JP">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">');
});

it('title の HTML はエスケープされる', function (): void {
    $meta = SeoMeta::default(new SeoUrl('https://app.example'), '/')
        ->withTitle('<script>alert(1)</script>');

    $html = (new SeoRenderer)->render($meta)->toHtml();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('正常な JSON-LD ノードは script タグで描画する', function (): void {
    $html = renderSeoMeta([['@type' => 'WebSite', 'name' => 'Acme']]);

    expect($html)->toContain('<script type="application/ld+json">')
        ->toContain('"@type":"WebSite"');
});

it('JSON-LD 内の </script> 注入は JSON_HEX_TAG で無害化される', function (): void {
    $html = renderSeoMeta([['@type' => 'WebSite', 'name' => '</script><script>alert(1)</script>']]);

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and($html)->toContain('<');
});

it('非 production では不正ノードで JsonException を投げる (欠損の検知)', function (): void {
    config(['app.env' => 'testing']);
    renderSeoMeta([['@type' => 'WebSite', 'bad' => "\xB1\x31"]]);
})->throws(JsonException::class);

it('production では不正ノードのみ skip して本体は描画する (可用性維持)', function (): void {
    config(['app.env' => 'production']);
    $html = renderSeoMeta([['@type' => 'WebSite', 'bad' => "\xB1\x31"]]);

    expect($html)->toContain('<title>Acme</title>')
        ->and($html)->not->toContain('application/ld+json');
});

it('renderPrivate は fragment なしでサイト名 title + noindex のみ描画する', function (): void {
    $html = (new SeoRenderer)->renderPrivate()->toHtml();

    expect($html)->toContain('<title>Acme</title>')
        ->toContain('<meta name="robots" content="noindex">')
        ->and($html)->not->toContain('<link rel="canonical"')
        ->and($html)->not->toContain('og:');
});

it('renderPrivate は fragment 付きで {fragment} | site + noindex を描画する', function (): void {
    $html = (new SeoRenderer)->renderPrivate('プロジェクト')->toHtml();

    expect($html)->toContain('<title>プロジェクト | Acme</title>')
        ->toContain('<meta name="robots" content="noindex">');
});

it('renderPrivate は fragment をエスケープする (title への生 HTML 注入不可)', function (): void {
    $html = (new SeoRenderer)->renderPrivate('<script>x</script>')->toHtml();

    expect($html)->not->toContain('<script>x</script>')
        ->and($html)->toContain('&lt;script&gt;');
});
