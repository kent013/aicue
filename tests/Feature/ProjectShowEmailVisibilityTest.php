<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

/**
 * Projects/Show の member email 可視性 (PII 最小化契約)。
 *
 * 契約:
 * - members[].email は**キー常在**・値のみ null。閲覧者が project を管理可能
 *   (can('update', $project) = org Owner/Admin または project_admin) なときのみ実値。
 * - フロントの単一根拠は canViewMemberEmails prop (can('update') と常に一致)。
 */

/**
 * @return array{User, User, User, Project} [orgOwner, projectMember, orgAdmin, project]
 */
function createEmailVisibilityContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();

    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();

    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $member->forceFill([
        'current_organization_id' => $organization->id,
        'email' => 'member@example.com',
    ])->save();

    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);

    return [$owner, $member, $admin, $project];
}

/**
 * members prop を配列に正規化する。
 *
 * @return list<array{id: int, name: string, email: string|null, role: string|null, implicit: bool}>
 */
function emailVisibilityRows(mixed $members): array
{
    return $members instanceof Collection ? $members->toArray() : (array) $members;
}

it('管理権限のない project member には email を出さない (キーは常在・値 null)', function (): void {
    [, $member, , $project] = createEmailVisibilityContext();

    $response = $this->actingAs($member)->get("/projects/{$project->id}");

    $response->assertOk();
    // members には暗黙メンバー (org Owner/Admin = implicit) と明示メンバー (self) の両方が
    // 含まれる。非管理者が閲覧したとき、その**全行**で email が null であることを担保する
    // (= 実装が一部の行だけ秘匿する退行を検知)。
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('Projects/Show')
        ->where('canViewMemberEmails', false)
        ->has('members')
        ->where('members', function (mixed $members): bool {
            $hasImplicit = false;
            $hasExplicit = false;
            foreach (emailVisibilityRows($members) as $row) {
                expect($row)->toHaveKey('email');
                if ($row['email'] !== null) {
                    return false;
                }
                $row['implicit'] === true ? $hasImplicit = true : $hasExplicit = true;
            }

            return $hasImplicit && $hasExplicit;
        })
    );
});

it('project_admin には email を出す', function (): void {
    [, $member, , $project] = createEmailVisibilityContext();
    $project->members()->updateExistingPivot($member->id, ['role' => ProjectRole::Admin->value]);

    $response = $this->actingAs($member)->get("/projects/{$project->id}");

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('Projects/Show')
        ->where('canViewMemberEmails', true)
        ->where('members', function (mixed $members): bool {
            return in_array('member@example.com', array_column(emailVisibilityRows($members), 'email'), true);
        })
    );
});

it('org Owner には email を出す', function (): void {
    [$owner, , , $project] = createEmailVisibilityContext();

    $response = $this->actingAs($owner)->get("/projects/{$project->id}");

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('Projects/Show')
        ->where('canViewMemberEmails', true)
        ->where('members', function (mixed $members): bool {
            return in_array('member@example.com', array_column(emailVisibilityRows($members), 'email'), true);
        })
    );
});

it('org Admin には email を出す (project 明示メンバーでなくとも can(update) 継承)', function (): void {
    [, , $admin, $project] = createEmailVisibilityContext();

    // org Admin は project の明示メンバーでなくとも can('update', $project) のため
    // email 可視 = gate が can('update') 単一根拠であることの回帰検知。
    $response = $this->actingAs($admin)->get("/projects/{$project->id}");

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('Projects/Show')
        ->where('canViewMemberEmails', true)
        ->where('members', function (mixed $members): bool {
            return in_array('member@example.com', array_column(emailVisibilityRows($members), 'email'), true);
        })
    );
});
