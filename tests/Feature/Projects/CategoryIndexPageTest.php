<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Category;
use App\Models\Project;
use App\Models\VideoManual;

/*
 * カテゴリ管理画面 (GET /projects/{project}/categories)。
 * 一覧表示のみ (追加・編集・削除・▲▼ は既存 write endpoint = CategoryCrudTest / ReorderTest が担保)。
 * 到達境界は CategoryPolicy::viewAny (= ProjectPolicy::update 委譲: org 管理者 or project_admin)。
 */

test('org Owner は 200 + Admin/Categories component を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)->get("/projects/{$project->id}/categories");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Categories')
        ->where('project.id', $project->id)
        // T071: 独自二次左メニュー(AdminMenuNav)撤去に伴い usersUrl prop は廃止 → 存在しない
        ->missing('usersUrl'));
});

test('project_admin (編集者。project update 可) も 200 で閲覧できる (viewAny≡update の回帰。usersUrl prop なし)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);
    $editor->forceFill(['current_organization_id' => $organization->id])->save();

    // CategoryPolicy::viewAny ≡ projectPolicy->update。project を update できる編集者は categories に 200 到達する。
    $response = $this->actingAs($editor)->get("/projects/{$project->id}/categories");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->missing('usersUrl'));
});

test('project_member (撮影者) は 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $shooter = attachOrganizationMember($organization);
    attachProjectMember($project, $shooter, ProjectRole::Member);
    $shooter->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($shooter)->get("/projects/{$project->id}/categories")->assertForbidden();
});

test('無関係の org Member (pivot なし) は 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get("/projects/{$project->id}/categories")->assertForbidden();
});

test('cross-org の {project} は authorize より前に 404 (存在オラクル封じ = IDOR 不変条件)', function (): void {
    [, $ownerA] = createOrganizationWithOwner('組織A');
    [$orgB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($orgB)->create();

    // ownerA は orgB の project に対し 403 ではなく 404 (存在を漏らさない)
    $this->actingAs($ownerA)->get("/projects/{$projectB->id}/categories")->assertNotFound();
});

test('categories が sort_order 順で返る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $second = Category::factory()->forProject($project)->create(['name' => '後半', 'sort_order' => 2]);
    $first = Category::factory()->forProject($project)->create(['name' => '前半', 'sort_order' => 1]);

    $response = $this->actingAs($owner)->get("/projects/{$project->id}/categories");

    $response->assertInertia(fn ($page) => $page
        ->where('categories.0.id', $first->id)
        ->where('categories.0.name', '前半')
        ->where('categories.1.id', $second->id));
});

test('画面経由の一連の操作: 追加 → 重複名 errors → reorder 反映 → 削除で manual が未分類', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    // 追加 (既存 write endpoint)
    $this->actingAs($owner)->post("/projects/{$project->id}/categories", ['name' => '準備作業'])
        ->assertSessionHas('success');
    $this->actingAs($owner)->post("/projects/{$project->id}/categories", ['name' => '仕上げ'])
        ->assertSessionHas('success');

    // 重複名は errors('name')
    $this->actingAs($owner)->post("/projects/{$project->id}/categories", ['name' => '準備作業'])
        ->assertSessionHasErrors('name');

    /** @var Category $prep */
    $prep = $project->categories()->where('name', '準備作業')->sole();
    /** @var Category $finish */
    $finish = $project->categories()->where('name', '仕上げ')->sole();

    // reorder → index props に並びが反映される
    $this->actingAs($owner)->patch("/projects/{$project->id}/categories/reorder", [
        'order' => [$finish->id, $prep->id],
    ])->assertSessionHas('success');

    $this->actingAs($owner)->get("/projects/{$project->id}/categories")
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.id', $finish->id)
            ->where('categories.1.id', $prep->id));

    // 削除 → 当該カテゴリの manual は未分類化 (FK nullOnDelete)
    $manual = VideoManual::factory()->forProject($project)->forCategory($prep)->create();
    $this->actingAs($owner)->delete("/projects/{$project->id}/categories/{$prep->id}")
        ->assertSessionHas('success');

    expect($manual->fresh()?->category_id)->toBeNull();
});
