<?php

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Models\Item;
use App\Models\Project;

/*
 * Item CRUD (Project 配下サンプルリソースの見本)。
 * - 親 FK (project_id) は URL から導出し relation 経由で代入する (payload では 422)
 * - 認可は ItemPolicy → ProjectPolicy 委譲 (作成・更新・削除 = プロジェクトを操作できる人)
 */

test('owner はアイテムを追加できる (project_id は URL から導出)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)
        ->from("/projects/{$project->id}")
        ->post("/projects/{$project->id}/items", [
            'name' => '新しいアイテム',
            'note' => 'メモ',
        ]);

    $response->assertRedirect("/projects/{$project->id}");
    $response->assertSessionHas('success');
    /** @var Item $item */
    $item = $project->items()->firstOrFail();
    expect($item->name)->toBe('新しいアイテム');
    expect($item->note)->toBe('メモ');
    expect($item->project_id)->toBe($project->id);
});

test('project_id を POST すると 422 (missing rule = 保護キー)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)->post("/projects/{$project->id}/items", [
        'name' => '新しいアイテム',
        'project_id' => $project->id,
    ]);

    $response->assertSessionHasErrors('project_id');
    expect($project->items()->count())->toBe(0);
});

test('更新時に project_id を送ると 422 (親の付け替えは受け付けない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $item = Item::factory()->forProject($project)->create();

    $this->actingAs($owner)->patch("/projects/{$project->id}/items/{$item->id}", [
        'name' => '更新後',
        'project_id' => 999,
    ])->assertSessionHasErrors('project_id');
});

test('owner はアイテムを更新できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $item = Item::factory()->forProject($project)->create();

    $response = $this->actingAs($owner)->patch("/projects/{$project->id}/items/{$item->id}", [
        'name' => '更新後',
        'note' => '更新後のメモ',
    ]);

    $response->assertSessionHas('success');
    $item = $item->fresh();
    expect($item->name)->toBe('更新後');
    expect($item->note)->toBe('更新後のメモ');
});

test('owner はアイテムを削除できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $item = Item::factory()->forProject($project)->create();

    $response = $this->actingAs($owner)->delete("/projects/{$project->id}/items/{$item->id}");

    $response->assertSessionHas('success');
    expect(Item::query()->whereKey($item->id)->exists())->toBeFalse();
});

test('project_admin はアイテムを追加・更新・削除できる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Admin);

    $this->actingAs($member)->post("/projects/{$project->id}/items", ['name' => '追加'])
        ->assertSessionHas('success');
    /** @var Item $item */
    $item = $project->items()->firstOrFail();

    $this->actingAs($member)->patch("/projects/{$project->id}/items/{$item->id}", ['name' => '更新'])
        ->assertSessionHas('success');
    expect($item->fresh()->name)->toBe('更新');

    $this->actingAs($member)->delete("/projects/{$project->id}/items/{$item->id}")
        ->assertSessionHas('success');
    expect($project->items()->count())->toBe(0);
});

test('閲覧のみの member はアイテムを操作できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    $item = Item::factory()->forProject($project)->create(['name' => '元の名前']);

    $this->actingAs($member)->post("/projects/{$project->id}/items", ['name' => '追加'])
        ->assertForbidden();
    $this->actingAs($member)->patch("/projects/{$project->id}/items/{$item->id}", ['name' => '更新'])
        ->assertForbidden();
    $this->actingAs($member)->delete("/projects/{$project->id}/items/{$item->id}")
        ->assertForbidden();

    expect($item->fresh()->name)->toBe('元の名前');
    expect($project->items()->count())->toBe(1);
});

test('name は必須 (バリデーション)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/items", ['name' => ''])
        ->assertSessionHasErrors('name');
});
