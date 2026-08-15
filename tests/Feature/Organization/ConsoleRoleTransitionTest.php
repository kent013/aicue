<?php

declare(strict_types=1);

use App\Enums\AdminConsoleRole;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Validation\ValidationException;

/*
 * ロール遷移コマンド (applyConsoleRole) の最終状態契約 (概念設計 D2(b)):
 * - Admin:   org Admin + org 配下 project pivot detach (stale 掃除)
 * - Editor:  org Member + Default Project pivot role=project_admin
 * - Shooter: org Member + Default Project pivot role=project_member
 * 1 トランザクションで保証し、中間状態を残さない。
 * removeMember の pivot 掃除 (org 配下限定 = cross-org 不変条件) もここで固定する。
 */

/**
 * @return array{Organization, User, Project} [organization, owner, defaultProject]
 */
function createOrgWithDefaultProject(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    return [$organization, $owner, $project];
}

test('editor → shooter: pivot role が project_member に更新され org ロールは Member のまま', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Admin);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Shooter, null);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Member);
});

test('shooter → admin: org Admin へ昇格し pivot は detach される (stale 掃除)', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Admin, null);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Admin);
    expect($project->memberRole($member))->toBeNull();
});

test('admin → editor: org Member へ降格し pivot project_admin が付与される', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization, OrganizationRole::Admin);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor, null);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
});

test('未割当 (org Member + pivot なし) → editor: pivot が付与される', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor, null);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
});

test('editor/shooter コマンドは Default Project 不在なら ValidationException (role)', function (AdminConsoleRole $role): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, $role, null))
        ->toThrow(ValidationException::class);
    // org ロールは変更されない (1 tx = 中間状態を残さない)
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
})->with([
    'editor' => [AdminConsoleRole::Editor],
    'shooter' => [AdminConsoleRole::Shooter],
]);

test('endpoint 経由: Default Project 不在の editor コマンドは error bag (サイレント成功でない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => AdminConsoleRole::Editor->value,
    ]);

    // 検証エラーで拒否され、成功トースト (success flash) は出ない = サイレント成功の回帰ネット
    $response->assertSessionHasErrors('role');
    $response->assertSessionMissing('success');
    // org ロールは Member のまま (部分適用なし)
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});

test('endpoint 経由: editor コマンドで org ロールと pivot が 1 操作で揃う', function (): void {
    [$organization, $owner, $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => AdminConsoleRole::Editor->value,
    ]);

    $response->assertSessionHas('success');
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
});

test('最終 Owner への admin コマンドは changeRole の最終 Owner 保護を継承する', function (): void {
    [$organization, $owner, $project] = createOrgWithDefaultProject();
    attachProjectMember($project, $owner, ProjectRole::Admin);

    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $owner, AdminConsoleRole::Admin, null))
        ->toThrow(ValidationException::class);
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
    // ロール変更が拒否されたら pivot 掃除にも到達しない (最終状態が部分適用されない)
    expect($project->memberRole($owner))->toBe(ProjectRole::Admin);
});

test('非メンバー (organization_user 非 attach) への適用は ValidationException', function (): void {
    [$organization] = createOrgWithDefaultProject();
    $outsider = User::factory()->create();

    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $outsider, AdminConsoleRole::Shooter, null))
        ->toThrow(ValidationException::class);
    expect($organization->users()->whereKey($outsider->getKey())->exists())->toBeFalse();
});

test('修復経路: attach 済み + Laratrust ロール未付与の異常行へ shooter コマンドで正規化できる', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    // 異常行を再現: attach のみでロール未付与 (表示状態は「未割当」)
    $broken = User::factory()->create();
    $organization->users()->attach($broken);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $broken, AdminConsoleRole::Shooter, null);

    expect($broken->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($broken))->toBe(ProjectRole::Member);
});

test('同値コマンドは冪等 (editor → editor で pivot / org ロール不変)', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Admin);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor, null);

    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
});

test('admin コマンドは org 配下の全 project の stale pivot を掃除する (別 org の pivot は維持)', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    $second = Project::factory()->forOrganization($organization)->create();
    [$otherOrg] = createOrganizationWithOwner('別組織');
    $otherProject = Project::factory()->forOrganization($otherOrg)->create();

    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Admin);
    attachProjectMember($second, $member, ProjectRole::Member);
    // 別 org にも所属させる (cross-org 掃除をしないことの fixture)
    $otherOrg->users()->attach($member);
    $member->addRole(OrganizationRole::Member->value, $otherOrg->laratrust_team_id);
    attachProjectMember($otherProject, $member, ProjectRole::Member);

    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Admin, null);

    expect($project->memberRole($member))->toBeNull();
    expect($second->memberRole($member))->toBeNull();
    expect($otherProject->memberRole($member))->toBe(ProjectRole::Member);
});

test('removeMember は org 配下 project の pivot を掃除し、別 org の pivot は維持する', function (): void {
    [$organization, , $project] = createOrgWithDefaultProject();
    [$otherOrg] = createOrganizationWithOwner('別組織');
    $otherProject = Project::factory()->forOrganization($otherOrg)->create();

    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);
    $otherOrg->users()->attach($member);
    $member->addRole(OrganizationRole::Member->value, $otherOrg->laratrust_team_id);
    attachProjectMember($otherProject, $member, ProjectRole::Admin);

    app(OrganizationMembershipService::class)->removeMember($organization, $member, null);

    expect($organization->users()->whereKey($member->getKey())->exists())->toBeFalse();
    expect($project->memberRole($member))->toBeNull();
    expect($otherProject->memberRole($member))->toBe(ProjectRole::Admin);
});
