<?php

declare(strict_types=1);

use App\Models\Project;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SEO ヘッド合成の end-to-end:
 * - full 分類 (home): controller 供給メタ → canonical / og / JSON-LD をサーバ描画
 * - SEO 非対象 (認証配下 / ゲスト auth 画面): noindex + per-page title のみ (メタ漏れ防止)
 * - Inertia 共有 prop `title` はサーバ描画 <title> と同一 (SPA 遷移の title 追従の SoT)
 */

beforeEach(function (): void {
    config([
        'seo.base_url' => 'https://app.example',
        'seo.site_name' => 'Acme',
        'seo.default_title' => 'Acme',
        'seo.title_separator' => ' | ',
        'seo.default_description' => '既定の説明文',
    ]);
});

it('home は完全な SEO ヘッド (canonical / og / JSON-LD) をサーバ描画する', function (): void {
    $response = $this->get('/');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('<title>Acme</title>')
        ->toContain('<meta property="og:title" content="Acme">')
        ->toContain('<link rel="canonical" href="https://app.example/">')
        ->toContain('<meta property="og:image"')
        ->toContain('"@type":"Organization"')
        ->toContain('"@type":"WebSite"')
        ->toContain('"@type":"SoftwareApplication"')
        // full 分類は index させる (noindex を出さない)
        ->and($html)->not->toContain('noindex');
});

it('home の SoftwareApplication は価格未確定 (null) のため offers を出さない', function (): void {
    $html = (string) $this->get('/')->getContent();

    expect($html)->not->toContain('AggregateOffer');
});

it('認証配下ページは noindex + per-page title のみで canonical / og を漏らさない', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $html = (string) $this->actingAs($owner)->get('/dashboard')->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex">')
        ->toContain('<title>ダッシュボード | Acme</title>')
        ->and($html)->not->toContain('<link rel="canonical"')
        ->and($html)->not->toContain('property="og:url"');
});

it('ゲスト向け認証画面 (login) も noindex + per-page title になる', function (): void {
    $html = (string) $this->get('/login')->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex">')
        ->toContain('<title>ログイン | Acme</title>');
});

it('app_titles 未登録の route はサイト名のみの title になる (noindex 維持)', function (): void {
    config(['seo.app_titles' => []]);
    [, $owner] = createOrganizationWithOwner();

    $html = (string) $this->actingAs($owner)->get('/dashboard')->getContent();

    expect($html)->toContain('<title>Acme</title>')
        ->toContain('<meta name="robots" content="noindex">');
});

it('動的固有名 (projects.show の setPrivateTitle) が app_titles 既定より優先される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create(['name' => 'My Project']);

    $html = (string) $this->actingAs($owner)->get("/projects/{$project->id}")->getContent();

    expect($html)->toContain('<title>My Project | Acme</title>')
        ->toContain('<meta name="robots" content="noindex">');
});

it('Inertia 共有 prop title はサーバ描画 <title> と同一文字列を供給する', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('title', 'ダッシュボード | Acme'));
});

it('home の共有 prop title も controller 供給メタの完成 title と一致する', function (): void {
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('title', 'Acme'));
});
