<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Manual\CategoryService;

/*
 * Category reorder (専用操作)。
 * - 送信 id 集合 = 当該 project の Category 集合 (distinct・過不足なし) でなければ 422
 * - 並び順は送信順に 0..N-1 で再採番される
 * - 並行制御: Project 行ロック下で create/reorder が直列化される (末尾採番の単調増加で近似検証)
 */

/** @return array{Project, list<Category>} */
function createProjectWithCategories(Organization $organization, int $count): array
{
    $project = Project::factory()->forOrganization($organization)->create();
    $categories = [];
    foreach (range(1, $count) as $i) {
        $category = $project->categories()->make(['name' => "カテゴリ{$i}"]);
        $category->forceFill(['sort_order' => $i])->save();
        $categories[] = $category;
    }

    return [$project, $categories];
}

test('reorder は送信順に sort_order を再採番する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$project, [$c1, $c2, $c3]] = createProjectWithCategories($organization, 3);

    $response = $this->actingAs($owner)->patch("/projects/{$project->id}/categories/reorder", [
        'order' => [$c3->id, $c1->id, $c2->id],
    ]);

    $response->assertSessionHas('success');
    expect($c3->fresh()?->sort_order)->toBe(0);
    expect($c1->fresh()?->sort_order)->toBe(1);
    expect($c2->fresh()?->sort_order)->toBe(2);
});

test('集合不一致 (欠落・重複・余剰) は 422 で並びは変わらない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$project, [$c1, $c2, $c3]] = createProjectWithCategories($organization, 3);
    $otherProject = Project::factory()->forOrganization($organization)->create();
    $foreign = Category::factory()->forProject($otherProject)->create();

    // 欠落
    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/reorder", [
        'order' => [$c1->id, $c2->id],
    ])->assertSessionHasErrors('order');

    // 重複
    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/reorder", [
        'order' => [$c1->id, $c1->id, $c2->id],
    ])->assertSessionHasErrors('order');

    // 余剰 (他 project の category id 混入)
    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/reorder", [
        'order' => [$c1->id, $c2->id, $c3->id, $foreign->id],
    ])->assertSessionHasErrors('order');

    expect($c1->fresh()?->sort_order)->toBe(1);
    expect($c2->fresh()?->sort_order)->toBe(2);
    expect($c3->fresh()?->sort_order)->toBe(3);
});

test('order は必須の配列 (形式バリデーション)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/reorder", [])
        ->assertSessionHasErrors('order');
    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/reorder", [
        'order' => ['abc'],
    ])->assertSessionHasErrors('order.0');
});

test('0 件 project の reorder (空配列) は no-op で成功する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    // order: [] は required に落ちるため、集合一致の空 no-op は Service 直で検証する
    app(CategoryService::class)->reorder($project, []);
    expect($project->categories()->count())->toBe(0);
});

test('reorder 後の create は末尾 (max+1) に採番される (Project 行ロック直列化の不変)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$project, [$c1, $c2, $c3]] = createProjectWithCategories($organization, 3);

    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/reorder", [
        'order' => [$c3->id, $c2->id, $c1->id],
    ])->assertSessionHas('success');

    // reorder 後の最大 sort_order は 2 → 新規 create は 3 (単調増加)
    $this->actingAs($owner)->post("/projects/{$project->id}/categories", ['name' => '追加分'])
        ->assertSessionHas('success');
    /** @var Category $created */
    $created = $project->categories()->where('name', '追加分')->firstOrFail();
    expect($created->sort_order)->toBe(3);

    // 並びは全体で一意 (重複しない)
    $orders = $project->categories()->pluck('sort_order')->all();
    expect(count($orders))->toBe(count(array_unique($orders)));
});

test('Service の create/reorder は transaction + Project 行ロックを通る (直列化点の存在)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $service = app(CategoryService::class);

    // 同一 project へ連続 create しても採番が衝突しない (ロック後の max 再計算)
    $first = $service->create($project, 'A');
    $second = $service->create($project, 'B');
    expect($first->sort_order)->toBe(1);
    expect($second->sort_order)->toBe(2);

    // reorder → create の交互操作でも単調増加を維持する
    $service->reorder($project, [$second->id, $first->id]);
    $third = $service->create($project, 'C');
    expect($third->sort_order)->toBe(2);
    $orders = $project->categories()->orderBy('sort_order')->pluck('sort_order')->all();
    expect($orders)->toBe([0, 1, 2]);
});
