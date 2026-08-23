<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\VideoManual;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * F-05: 動画マニュアル関連 4 画面が固有の <title> を供給する回帰テスト。
 * - create は静的 app_titles 経路 / show・edit・撮影 show は controller の setPrivateTitle
 *   (動的固有名 = マニュアル名) 経路。いずれも noindex は維持する (認証配下)。
 * - Inertia 共有 prop `title` はサーバ描画 <title> と同一 (SPA 遷移の title 追従 SoT)。
 * URL は route 名解決で構築し、route 名変更時に app_titles キーの取り残しを検知する。
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

it('projects.manuals.create は静的 app_titles で固有 title を出す (noindex 維持)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $html = (string) $this->actingAs($owner)
        ->get(route('projects.manuals.create', [$organization->slug, $project]))
        ->getContent();

    expect($html)->toContain('<title>動画マニュアルの作成 | Acme</title>')
        ->toContain('<meta name="robots" content="noindex">');
});

it('projects.manuals.show はマニュアル名の動的固有 title を出す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['title' => 'ネジ締め作業']);

    $html = (string) $this->actingAs($owner)
        ->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->getContent();

    expect($html)->toContain('<title>ネジ締め作業 | Acme</title>')
        ->toContain('<meta name="robots" content="noindex">');
});

it('projects.manuals.edit は「<マニュアル名> の編集」を title に出す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['title' => 'ネジ締め作業']);

    $html = (string) $this->actingAs($owner)
        ->get(route('projects.manuals.edit', [$organization->slug, $project, $manual]))
        ->getContent();

    expect($html)->toContain('<title>ネジ締め作業 の編集 | Acme</title>');
});

it('capture.manuals.show は「<マニュアル名> の撮影」を title に出す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => 'ready',
        'title' => 'ネジ締め作業',
    ]);

    $html = (string) $this->actingAs($owner)
        ->get(route('capture.manuals.show', [$organization->slug, $project, $manual]))
        ->getContent();

    expect($html)->toContain('<title>ネジ締め作業 の撮影 | Acme</title>');
});

it('Inertia 共有 prop title はサーバ描画 <title> と同一文字列 (show / edit / 撮影 show)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => 'ready',
        'title' => 'ネジ締め作業',
    ]);

    $this->actingAs($owner)->get(route('projects.manuals.show', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manuals/Show')
            ->where('title', 'ネジ締め作業 | Acme'));

    $this->actingAs($owner)->get(route('projects.manuals.edit', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manuals/Edit')
            ->where('title', 'ネジ締め作業 の編集 | Acme'));

    $this->actingAs($owner)->get(route('capture.manuals.show', [$organization->slug, $project, $manual]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Capture/Show')
            ->where('title', 'ネジ締め作業 の撮影 | Acme'));
});
