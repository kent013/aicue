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

test('manuals.data.* は creator / updated_at を供給する (正常系)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->createdBy($owner)->create([
        'title' => 'メタ確認', 'updated_at' => '2026-07-10 09:30:00',
    ]);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.creator.id', $owner->id)
            ->where('manuals.data.0.creator.name', $owner->name)
            ->where('manuals.data.0.updated_at', '2026-07-10 09:30'));
});

test('sort 未指定は既定順 (created_at desc, id desc) を維持する (回帰)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $old = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-01 00:00:00']);
    $new = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-05 00:00:00']);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.id', $new->id)
            ->where('manuals.data.1.id', $old->id)
            ->where('manualFilters.sort', null)
            ->where('manualFilters.mine', false));
});

test('sort 各値で並べ替える (updated / title × asc/desc)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $a = VideoManual::factory()->forProject($project)->create([
        'title' => 'apple', 'updated_at' => '2026-07-01 00:00:00',
    ]);
    $b = VideoManual::factory()->forProject($project)->create([
        'title' => 'banana', 'updated_at' => '2026-07-02 00:00:00',
    ]);
    $c = VideoManual::factory()->forProject($project)->create([
        'title' => 'cherry', 'updated_at' => '2026-07-03 00:00:00',
    ]);

    $order = function (string $sort) use ($owner, $project): array {
        $props = $this->actingAs($owner)->get("/projects/{$project->id}?sort={$sort}")
            ->inertiaPage()['props'];

        return array_column($props['manuals']['data'], 'id');
    };

    expect($order('updated_desc'))->toBe([$c->id, $b->id, $a->id]);
    expect($order('updated_asc'))->toBe([$a->id, $b->id, $c->id]);
    expect($order('title_asc'))->toBe([$a->id, $b->id, $c->id]);
    expect($order('title_desc'))->toBe([$c->id, $b->id, $a->id]);

    $this->actingAs($owner)->get("/projects/{$project->id}?sort=updated_desc")
        ->assertInertia(fn (Assert $page) => $page->where('manualFilters.sort', 'updated_desc'));
});

test('sort allowlist 外は既定順へフォールバック (manualFilters.sort=null)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $old = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-01 00:00:00']);
    $new = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-05 00:00:00']);

    $this->actingAs($owner)->get("/projects/{$project->id}?sort=bogus")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.id', $new->id)
            ->where('manuals.data.1.id', $old->id)
            ->where('manualFilters.sort', null));
});

test('同値 updated_at でも id tie-breaker でページ境界に重複/欠落が無い', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    // 15 件すべて同一 updated_at (tie-breaker が無いとページ間で不安定になる)
    VideoManual::factory()->forProject($project)->count(15)->create(['updated_at' => '2026-07-01 00:00:00']);

    $ids = function (int $page) use ($owner, $project): array {
        $props = $this->actingAs($owner)->get("/projects/{$project->id}?sort=updated_desc&page={$page}")
            ->inertiaPage()['props'];

        return array_column($props['manuals']['data'], 'id');
    };

    $page1 = $ids(1);
    $page2 = $ids(2);

    expect($page1)->toHaveCount(10);
    expect($page2)->toHaveCount(5);
    // 排他 (重複なし) かつ全 15 件を被覆 (欠落なし)
    expect(array_intersect($page1, $page2))->toBe([]);
    expect(count(array_unique(array_merge($page1, $page2))))->toBe(15);
});

test('mine=1 は自ユーザー作成分のみに絞る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $other = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    $mine = VideoManual::factory()->forProject($project)->createdBy($owner)->create(['title' => '自作']);
    VideoManual::factory()->forProject($project)->createdBy($other)->create(['title' => '他人作']);

    $this->actingAs($owner)->get("/projects/{$project->id}?mine=1")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $mine->id)
            ->where('manualFilters.mine', true));
});

test('mine と category/status/q/sort の併用で結合絞り込みできる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $other = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    // 目標: 自作 + 該当カテゴリ + published + タイトル一致
    $target = VideoManual::factory()->forProject($project)->forCategory($category)->createdBy($owner)->create([
        'title' => 'ネジ締め', 'status' => VideoManualStatus::Published->value, 'updated_at' => '2026-07-05 00:00:00',
    ]);
    // ノイズ: 他人作 / 別カテゴリ / 別 status / 別タイトル
    VideoManual::factory()->forProject($project)->forCategory($category)->createdBy($other)->create([
        'title' => 'ネジ締め', 'status' => VideoManualStatus::Published->value,
    ]);
    VideoManual::factory()->forProject($project)->createdBy($owner)->create([
        'title' => 'ネジ締め', 'status' => VideoManualStatus::Published->value,
    ]);
    VideoManual::factory()->forProject($project)->forCategory($category)->createdBy($owner)->create([
        'title' => 'ネジ締め', 'status' => VideoManualStatus::Draft->value,
    ]);

    $this->actingAs($owner)
        ->get("/projects/{$project->id}?mine=1&category={$category->id}&status=published&q=ネジ&sort=updated_desc")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $target->id));
});
