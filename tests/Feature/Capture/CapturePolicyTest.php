<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Policies\ProjectPolicy;
use App\Policies\TakePolicy;

/*
 * 撮影権限 (施策7): ProjectPolicy::capture + TakePolicy (親委譲)。
 * org owner/admin・project_admin・project_member = 可 / 非 project member の org member = 不可 /
 * 他 org = 不可 (cross-org 不変条件)。
 */

/**
 * @return array{Organization, User, Project, Take}
 */
function capturePolicyContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();

    return [$organization, $owner, $project, $take];
}

test('org owner は capture 可 (TakePolicy 全 ability)', function (): void {
    [, $owner, $project, $take] = capturePolicyContext();
    $policy = app(TakePolicy::class);

    expect($policy->create($owner, $project))->toBeTrue();
    expect($policy->update($owner, $take))->toBeTrue();
    expect($policy->delete($owner, $take))->toBeTrue();
    expect($policy->adopt($owner, $take))->toBeTrue();
    expect($policy->markDownloaded($owner, $take))->toBeTrue();
});

test('org admin / project_admin / project_member は capture 可', function (): void {
    [$organization, , $project, $take] = capturePolicyContext();
    $policy = app(TakePolicy::class);

    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    expect($policy->create($admin, $project))->toBeTrue();

    $projectAdmin = attachOrganizationMember($organization);
    attachProjectMember($project, $projectAdmin, ProjectRole::Admin);
    expect($policy->create($projectAdmin, $project))->toBeTrue();
    expect($policy->adopt($projectAdmin, $take))->toBeTrue();

    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);
    expect($policy->create($member, $project))->toBeTrue();
    expect($policy->adopt($member, $take))->toBeTrue(); // 撮影者 = adopt 可 (doc/10 §10.5)
});

test('非 project member の org member は capture 不可 (読み取りは可)', function (): void {
    [$organization, , $project, $take] = capturePolicyContext();
    $orgMember = attachOrganizationMember($organization);

    expect(app(TakePolicy::class)->create($orgMember, $project))->toBeFalse();
    expect(app(TakePolicy::class)->update($orgMember, $take))->toBeFalse();
    expect(app(ProjectPolicy::class)->view($orgMember, $project))->toBeTrue();
});

test('他 org のユーザは capture 不可 (cross-org 不変条件)', function (): void {
    [, , $project, $take] = capturePolicyContext();
    [, $otherOwner] = createOrganizationWithOwner();

    expect(app(TakePolicy::class)->create($otherOwner, $project))->toBeFalse();
    expect(app(TakePolicy::class)->adopt($otherOwner, $take))->toBeFalse();
});
