<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\VideoManual;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Projects/Show に内包する動画マニュアル一覧 (manuals/categories/manualFilters props)。
 * GET クエリ (?category=&status=&q=) の絞り込みと paginate の shape を固定する。
 */

test('projects.show は manuals / categories / manualFilters を供給する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create(['name' => '準備作業']);
    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->has('manuals.data', 2)
            ->has('manuals.meta', fn (Assert $meta) => $meta
                ->where('current_page', 1)
                ->where('last_page', 1)
                ->where('per_page', 10)
                ->where('total', 2))
            ->has('categories', 1)
            ->where('categories.0.name', '準備作業')
            ->where('manualFilters.category', null)
            ->where('manualFilters.status', null)
            ->where('manualFilters.q', null));
});

test('未分類 manual は category=null で返る (フロントは「未分類」を表示)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.category', null)
            ->where('manuals.data.0.status', 'draft'));
});

test('category フィルタ (id / uncategorized sentinel) で絞り込める', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}?category={$category->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '分類済み')
            ->where('manualFilters.category', (string) $category->id));

    $this->actingAs($owner)->get("/projects/{$project->id}?category=uncategorized")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '未分類マニュアル')
            ->where('manualFilters.category', 'uncategorized'));
});

test('status フィルタで絞り込める (不正値は無視)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '下書き']);
    VideoManual::factory()->forProject($project)->create([
        'title' => '公開済み',
        'status' => VideoManualStatus::Published->value,
    ]);

    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '公開済み')
            ->where('manualFilters.status', 'published'));

    // enum に無い値は無視 (全件)
    $this->actingAs($owner)->get("/projects/{$project->id}?status=bogus")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 2)
            ->where('manualFilters.status', null));
});

test('q フィルタは title 部分一致 (LIKE メタ文字はリテラル扱い)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => 'ネジ締め作業']);
    VideoManual::factory()->forProject($project)->create(['title' => '洗浄 100% 完全版']);

    $this->actingAs($owner)->get("/projects/{$project->id}?q=ネジ")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', 'ネジ締め作業'));

    // "%" をリテラルとして検索できる (ワイルドカード化しない)
    $this->actingAs($owner)->get("/projects/{$project->id}?q=100%25")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '洗浄 100% 完全版'));
});

test('paginate は 10 件/ページで meta を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->count(12)->create();

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 10)
            ->where('manuals.meta.total', 12)
            ->where('manuals.meta.last_page', 2));

    $this->actingAs($owner)->get("/projects/{$project->id}?page=2")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 2)
            ->where('manuals.meta.current_page', 2));
});

test('別 project の manual は一覧に混ざらない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $other = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '自分のマニュアル']);
    VideoManual::factory()->forProject($other)->create(['title' => '他プロジェクトのマニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '自分のマニュアル'));
});
