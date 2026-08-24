<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * 除名 / 未割当の fail-closed リグレッション (production コードは変更しない。既存の正しい挙動を不変条件化)。
 *
 * この family の層分け (本リポジトリの不変条件):
 *  - 層 2 (テナント境界) = 404: current-org が解決できない / binding が通らない
 *  - 層 3 (認可)        = 403: binding は通るが membership / role が無い
 *
 * | 状態                                   | dashboard | projects | billing | manage/users |
 * | 自然除名 (current=null)                | 200       | 404      | 404     | 404          |
 * | stale (current=除名済み org)           | 200       | 403      | 403     | 403          |
 * | 未割当 (attach 済み・current=org・role 無し) | 403   | 403      | 403     | 403          |
 *
 * 除名の証明は projects/billing/manage の 404/403 で行う (dashboard は current 未解決時に
 * no-org 設定画面 200 を出すため、除名済み org のデータでないことの確認に留める)。
 */

test('T7: 自然除名で membership/role/pivot/current が掃除され、被除名者は org 業務 route に到達できない (層2=404)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('除名テスト組織');
    $project = Project::factory()->forOrganization($organization)->create();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($owner)
        ->delete("/organizations/{$organization->slug}/members/{$member->id}")
        ->assertSessionHas('success');

    // (1) organization_user の pivot 行が不在 (organizationRole の null だけに依存しない)
    $this->assertDatabaseMissing('organization_user', [
        'organization_id' => $organization->id,
        'user_id' => $member->id,
    ]);
    // (3) 対象組織 laratrust_team_id の role_user 行が不在 (Laratrust キャッシュ reset 後に確認)
    expect($member->fresh()?->organizationRole($organization))->toBeNull();
    $this->assertDatabaseMissing('role_user', [
        'user_id' => $member->id,
        'team_id' => $organization->laratrust_team_id,
    ]);
    // (4) project_members pivot から消滅
    $this->assertDatabaseMissing('project_members', [
        'project_id' => $project->id,
        'user_id' => $member->id,
    ]);

    // (2) /manage/users (owner 閲覧) の members prop に被除名者が含まれない
    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/manage/users")
        ->assertInertia(function (AssertableInertia $page) use ($member): void {
            $page->component('Admin/Users');
            /** @var list<array{id: int}> $members */
            $members = $page->toArray()['props']['members'];
            expect(array_column($members, 'id'))->not->toContain($member->id);
        });

    // (6) 被除名者は組織 URL 配下のすべてで 404 (層 2)。組織は URL の binding だけで決まり、
    //     binder が membership でスコープするため、dashboard も例外なく存在秘匿の 404 になる。
    $removed = $member->fresh();
    $this->actingAs($removed)->get("/organizations/{$organization->slug}/dashboard")->assertNotFound();
    $this->actingAs($removed)->get("/organizations/{$organization->slug}/projects")->assertNotFound();
    $this->actingAs($removed)->get("/organizations/{$organization->slug}/billing")->assertNotFound();
    $this->actingAs($removed)->get("/organizations/{$organization->slug}/manage/users")->assertNotFound();
});

test('T7b: 除名後に組織 URL を直接叩いても層 2 (404) で止まり、層 3 へ到達しない', function (): void {
    // 組織文脈は URL だけで決まる (家系裁定 AG-037) ため、「現在組織を除名済み org へ戻す」という
    // 状態そのものが存在しない。残るのは「URL を直接叩く」経路だけで、そこは binder が
    // membership でスコープするので**認可より前に 404** になる (存在秘匿)。
    [$organization, $owner] = createOrganizationWithOwner('直叩き検証組織');
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    $this->actingAs($owner)
        ->delete("/organizations/{$organization->slug}/members/{$member->id}")
        ->assertSessionHas('success');

    $removed = $member->fresh();

    $this->actingAs($removed)->get("/organizations/{$organization->slug}/dashboard")->assertNotFound();
    $this->actingAs($removed)->get("/organizations/{$organization->slug}/projects")->assertNotFound();
    $this->actingAs($removed)->get("/organizations/{$organization->slug}/billing")->assertNotFound();
    $this->actingAs($removed)->get("/organizations/{$organization->slug}/manage/users")->assertNotFound();
});

test('T8: 未割当 (attach 済み・laratrust role 無し) は主要 route が fail-closed (層3=403)', function (): void {
    // 検証した主要 route (dashboard/projects/billing/manage-users)。全 route 保証ではない。
    [$organization] = createOrganizationWithOwner('未割当 fail-closed 組織');

    // organization_user へ attach 済みだが Laratrust role を付与しない異常行 (並行受諾レースの自然な帰結)。
    // 所属はあるので binder (層 2) は通り、拒否は role 不在の認可 (層 3) で成立する。
    $unassigned = User::factory()->create();
    $organization->users()->attach($unassigned);

    expect($unassigned->fresh()?->organizationRole($organization))->toBeNull();

    $this->actingAs($unassigned)->get("/organizations/{$organization->slug}/dashboard")->assertForbidden();
    $this->actingAs($unassigned)->get("/organizations/{$organization->slug}/projects")->assertForbidden();
    $this->actingAs($unassigned)->get("/organizations/{$organization->slug}/billing")->assertForbidden();
    $this->actingAs($unassigned)->get("/organizations/{$organization->slug}/manage/users")->assertForbidden();
});
