<?php

declare(strict_types=1);

/*
 * llms.txt (route: seo.llms) の Feature テスト。
 * llmstxt.org 形式 (H1 = サイト名 / blockquote = 要約 / 公開ページのリンク一覧)。
 * 公開ページ集合は seo.sitemap_routes と同一ソース (sitemap とドリフトしない)。
 */

it('llms.txt を llmstxt.org 形式 (サイト名 + 要約 + 公開ページ一覧) で配信する', function (): void {
    config([
        'seo.base_url' => 'https://app.example',
        'seo.site_name' => 'Acme',
        'seo.default_description' => 'チーム向け SaaS テンプレート',
    ]);

    $response = $this->get('/llms.txt');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/plain');

    $body = (string) $response->getContent();
    expect($body)->toContain('# Acme')
        ->toContain('> チーム向け SaaS テンプレート')
        ->toContain('- [Acme](https://app.example/)')
        // 認証配下 / 認証画面は載せない
        ->and($body)->not->toContain('/dashboard')
        ->and($body)->not->toContain('/login');
});

it('default_description が空のとき blockquote 行を出さない', function (): void {
    config([
        'seo.base_url' => 'https://app.example',
        'seo.site_name' => 'Acme',
        'seo.default_description' => '',
    ]);

    $body = (string) $this->get('/llms.txt')->getContent();

    expect($body)->toContain('# Acme')
        ->and($body)->not->toContain('>');
});
