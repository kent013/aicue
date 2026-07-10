<?php

declare(strict_types=1);

use App\Models\Item;
use App\Models\Project;

/*
 * URL 整合 (D2 不変条件) の 2 層確認:
 * 1. {project} が current org に属さなければ 404 (controller の inline guard)
 * 2. {item} が {project} に属さなければ 404 (routes の Route::scopeBindings() =
 *    $project->items() 経由の解決)
 * いずれも認可より前 (403 で存在を漏らさない)。
 */

test('{item} が同一組織内の別プロジェクト所属なら 404', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $itemB = Item::factory()->forProject($projectB)->create(['name' => '元の名前']);

    // projectA の URL から projectB の item は操作できない
    $this->actingAs($owner)->patch("/projects/{$projectA->id}/items/{$itemB->id}", [
        'name' => '乗っ取り',
    ])->assertNotFound();
    $this->actingAs($owner)->delete("/projects/{$projectA->id}/items/{$itemB->id}")
        ->assertNotFound();

    expect($itemB->fresh()->name)->toBe('元の名前');
});

test('他組織のプロジェクト配下への item 操作は 404 (project guard が先に効く)', function (): void {
    [, $ownerA] = createOrganizationWithOwner('組織A');
    [$orgB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($orgB)->create();
    $itemB = Item::factory()->forProject($projectB)->create(['name' => '元の名前']);

    $this->actingAs($ownerA)->post("/projects/{$projectB->id}/items", ['name' => '追加'])
        ->assertNotFound();
    $this->actingAs($ownerA)->patch("/projects/{$projectB->id}/items/{$itemB->id}", [
        'name' => '乗っ取り',
    ])->assertNotFound();
    $this->actingAs($ownerA)->delete("/projects/{$projectB->id}/items/{$itemB->id}")
        ->assertNotFound();

    expect($itemB->fresh()->name)->toBe('元の名前');
    expect($projectB->items()->count())->toBe(1);
});

test('権限のないメンバーでも整合しない {item} は 403 ではなく 404', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $itemB = Item::factory()->forProject($projectB)->create();

    // member は update 権限を持たないが、parent 不整合が先に 404 になる
    $this->actingAs($member)->patch("/projects/{$projectA->id}/items/{$itemB->id}", [
        'name' => '乗っ取り',
    ])->assertNotFound();
});

test('存在しない item ID は 404', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->delete("/projects/{$project->id}/items/999999")->assertNotFound();
});
