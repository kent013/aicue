<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Project;

/*
 * プロジェクトメンバー管理 (project_members pivot の追加・削除)。
 * 追加対象 (payload user_id) の cross-org は 403、削除対象 (URL {user}) の cross-org は
 * 認可より前に 404 (NestedRouteIdorDefenseTest の分類と対応)。
 */

test('owner はプロジェクトメンバーを追加できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)->post("/projects/{$project->id}/members", [
        'user_id' => $member->id,
        'role' => ProjectRole::Member->value,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($project->memberRole($member))->toBe(ProjectRole::Member);
});

test('既存メンバーへの store 再実行はロール更新になる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($owner)->post("/projects/{$project->id}/members", [
        'user_id' => $member->id,
        'role' => ProjectRole::Admin->value,
    ])->assertSessionHas('success');

    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
    // 重複行にならない (syncWithoutDetaching)
    expect($project->members()->whereKey($member->id)->count())->toBe(1);
});

test('他組織のユーザーは追加できない (cross-org は 403)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('組織A');
    [, $outsider] = createOrganizationWithOwner('組織B');
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/members", [
        'user_id' => $outsider->id,
        'role' => ProjectRole::Member->value,
    ])->assertForbidden();

    expect($project->members()->count())->toBe(0);
});

test('管理権限のない project member はメンバーを追加できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $other = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($member)->post("/projects/{$project->id}/members", [
        'user_id' => $other->id,
        'role' => ProjectRole::Member->value,
    ])->assertForbidden();
});

test('owner はプロジェクトメンバーを削除できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);

    $response = $this->actingAs($owner)->delete("/projects/{$project->id}/members/{$member->id}");

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($project->members()->whereKey($member->id)->exists())->toBeFalse();
    // 組織メンバーシップは残る (プロジェクトからの除外のみ)
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});

test('他組織ユーザーの削除 URL は認可より前に 404 (存在を漏らさない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('組織A');
    [, $outsider] = createOrganizationWithOwner('組織B');
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)
        ->delete("/projects/{$project->id}/members/{$outsider->id}")
        ->assertNotFound();
});

test('他組織の project へのメンバー操作は 404 ({project} ∈ current org guard)', function (): void {
    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($organizationB)->create();
    $memberA = attachOrganizationMember($organizationA);

    $this->actingAs($ownerA)->post("/projects/{$projectB->id}/members", [
        'user_id' => $memberA->id,
        'role' => ProjectRole::Member->value,
    ])->assertNotFound();
});
