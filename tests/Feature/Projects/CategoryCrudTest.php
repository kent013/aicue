<?php

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Models\Category;
use App\Models\Project;
use App\Models\VideoManual;

/*
 * Category CRUD (Project 配下の動画マニュアル分類)。
 * - 親 FK (project_id) は URL から導出し relation 経由で代入する (payload では 422)
 * - sort_order は Service 専有 (payload から受けない = rules 外で無視)
 * - name は project 内ユニーク (Request unique + Service ロック後再検査)
 * - 認可: 編集者 (owner/admin/project_admin) = write 可、撮影者 (project_member) = 閲覧のみ
 */

test('owner はカテゴリを追加できる (sort_order は末尾採番)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)
        ->from("/projects/{$project->id}")
        ->post("/projects/{$project->id}/categories", ['name' => '準備作業']);

    $response->assertRedirect("/projects/{$project->id}");
    $response->assertSessionHas('success');
    /** @var Category $category */
    $category = $project->categories()->firstOrFail();
    expect($category->name)->toBe('準備作業');
    expect($category->project_id)->toBe($project->id);
    expect($category->sort_order)->toBe(1);

    // 2 件目は末尾 (max+1) に採番される
    $this->actingAs($owner)->post("/projects/{$project->id}/categories", ['name' => '仕上げ']);
    /** @var Category $second */
    $second = $project->categories()->where('name', '仕上げ')->firstOrFail();
    expect($second->sort_order)->toBe(2);
});

test('project_id を POST すると 422 (missing rule = 保護キー)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/categories", [
        'name' => '準備作業',
        'project_id' => $project->id,
    ])->assertSessionHasErrors('project_id');

    expect($project->categories()->count())->toBe(0);
});

test('sort_order を POST しても無視される (rules 外 = Service 専有)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/categories", [
        'name' => '準備作業',
        'sort_order' => 99,
    ])->assertSessionHas('success');

    /** @var Category $category */
    $category = $project->categories()->firstOrFail();
    expect($category->sort_order)->toBe(1);
});

test('同一 project 内の同名カテゴリは 422 (別 project の同名は許容)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $other = Project::factory()->forOrganization($organization)->create();
    Category::factory()->forProject($project)->create(['name' => '準備作業']);

    // 同一 project 内は Request unique で 422
    $this->actingAs($owner)->post("/projects/{$project->id}/categories", ['name' => '準備作業'])
        ->assertSessionHasErrors('name');
    expect($project->categories()->count())->toBe(1);

    // 別 project は同名を許容する
    $this->actingAs($owner)->post("/projects/{$other->id}/categories", ['name' => '準備作業'])
        ->assertSessionHas('success');
    expect($other->categories()->count())->toBe(1);
});

test('owner はカテゴリ名を変更できる (他カテゴリと同名は 422・自分自身は許容)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create(['name' => '準備作業']);
    Category::factory()->forProject($project)->create(['name' => '仕上げ']);

    // 他カテゴリと同名 → 422
    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/{$category->id}", [
        'name' => '仕上げ',
    ])->assertSessionHasErrors('name');

    // 自分自身と同名 (変更なし) は許容 (ignore)
    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/{$category->id}", [
        'name' => '準備作業',
    ])->assertSessionHas('success');

    // 通常の rename
    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/{$category->id}", [
        'name' => '検品',
    ])->assertSessionHas('success');
    expect($category->fresh()?->name)->toBe('検品');
});

test('owner はカテゴリを削除でき、所属 manual は未分類化される (nullOnDelete)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    $manual = VideoManual::factory()->forProject($project)->forCategory($category)->create();

    $response = $this->actingAs($owner)
        ->delete("/projects/{$project->id}/categories/{$category->id}");

    $response->assertSessionHas('success');
    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse();
    expect($manual->fresh()?->category_id)->toBeNull();
    expect(VideoManual::query()->whereKey($manual->id)->exists())->toBeTrue();
});

test('project_admin (編集者) はカテゴリを追加・更新・削除できる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Admin);

    $this->actingAs($member)->post("/projects/{$project->id}/categories", ['name' => '追加'])
        ->assertSessionHas('success');
    /** @var Category $category */
    $category = $project->categories()->firstOrFail();

    $this->actingAs($member)->patch("/projects/{$project->id}/categories/{$category->id}", ['name' => '更新'])
        ->assertSessionHas('success');
    expect($category->fresh()?->name)->toBe('更新');

    $this->actingAs($member)->delete("/projects/{$project->id}/categories/{$category->id}")
        ->assertSessionHas('success');
    expect($project->categories()->count())->toBe(0);
});

test('project_member (撮影者) はカテゴリを操作できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);
    $category = Category::factory()->forProject($project)->create(['name' => '元の名前']);

    $this->actingAs($member)->post("/projects/{$project->id}/categories", ['name' => '追加'])
        ->assertForbidden();
    $this->actingAs($member)->patch("/projects/{$project->id}/categories/{$category->id}", ['name' => '更新'])
        ->assertForbidden();
    $this->actingAs($member)->delete("/projects/{$project->id}/categories/{$category->id}")
        ->assertForbidden();
    $this->actingAs($member)->patch("/projects/{$project->id}/categories/reorder", ['order' => [$category->id]])
        ->assertForbidden();

    expect($category->fresh()?->name)->toBe('元の名前');
    expect($project->categories()->count())->toBe(1);

    // 撮影者もプロジェクト詳細 (一覧内包) は閲覧できる
    $this->actingAs($member)->get("/projects/{$project->id}")->assertOk();
});

test('cross-org の project 配下カテゴリ操作は 404 (存在を漏らさない)', function (): void {
    [, $ownerA] = createOrganizationWithOwner('組織A');
    [$orgB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($orgB)->create();
    $categoryB = Category::factory()->forProject($projectB)->create(['name' => '元の名前']);

    $this->actingAs($ownerA)->post("/projects/{$projectB->id}/categories", ['name' => '追加'])
        ->assertNotFound();
    $this->actingAs($ownerA)->patch("/projects/{$projectB->id}/categories/{$categoryB->id}", ['name' => '更新'])
        ->assertNotFound();
    $this->actingAs($ownerA)->delete("/projects/{$projectB->id}/categories/{$categoryB->id}")
        ->assertNotFound();

    expect($categoryB->fresh()?->name)->toBe('元の名前');
});

test('cross-project の {category} は 404 (scopeBindings)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create(['name' => '元の名前']);

    // {category} が {project} に属さない → routing 層で 404 (認可より前)
    $this->actingAs($owner)->patch("/projects/{$projectA->id}/categories/{$categoryB->id}", ['name' => '更新'])
        ->assertNotFound();
    $this->actingAs($owner)->delete("/projects/{$projectA->id}/categories/{$categoryB->id}")
        ->assertNotFound();

    expect($categoryB->fresh()?->name)->toBe('元の名前');
    expect(Category::query()->whereKey($categoryB->id)->exists())->toBeTrue();
});

test('name は必須・50 文字以内 (バリデーション)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/categories", ['name' => ''])
        ->assertSessionHasErrors('name');
    $this->actingAs($owner)->post("/projects/{$project->id}/categories", [
        'name' => str_repeat('あ', 51),
    ])->assertSessionHasErrors('name');
});
