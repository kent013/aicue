<?php

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Models\Item;
use App\Models\Project;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * プロジェクト CRUD (Project 配下リソース追加の見本)。
 * - Default Team パターン: 作成時に Service が current org の Default Team を自動割当
 * - 認可: 閲覧 = 組織メンバー / 作成 = owner・admin / 更新・削除 = owner・admin・project_admin
 */

test('一覧には current org のプロジェクトだけが表示される', function (): void {
    [$orgA, $ownerA] = createOrganizationWithOwner('組織A');
    [$orgB] = createOrganizationWithOwner('組織B');
    Project::factory()->forOrganization($orgA)->create(['name' => 'A のプロジェクト']);
    Project::factory()->forOrganization($orgB)->create(['name' => 'B のプロジェクト']);

    $this->actingAs($ownerA)->get('/projects')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Index')
            ->has('projects', 1)
            ->where('projects.0.name', 'A のプロジェクト')
            ->where('canCreate', true));
});

test('member も一覧を閲覧できる (作成導線は出さない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/projects')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Index')
            ->where('canCreate', false));
});

test('owner はプロジェクトを作成でき、Default Team に自動割当される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->post('/projects', [
        'name' => '新プロジェクト',
        'description' => '説明文',
    ]);

    /** @var Project $project */
    $project = $organization->projects()->firstOrFail();
    $response->assertRedirect("/projects/{$project->id}");
    $response->assertSessionHas('success');
    expect($project->name)->toBe('新プロジェクト');
    expect($project->description)->toBe('説明文');
    // Default Team パターン: custom_team_id は組織の Default Team
    expect($project->custom_team_id)->toBe($organization->defaultTeam()->id);
});

test('作成時に custom_team_id を POST すると 422 (missing rule = 保護キー)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->post('/projects', [
        'name' => '新プロジェクト',
        'custom_team_id' => $organization->defaultTeam()->id,
    ]);

    $response->assertSessionHasErrors('custom_team_id');
    expect($organization->projects()->count())->toBe(0);
});

test('作成時に organization_id / project_id を POST しても 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->post('/projects', [
        'name' => '新プロジェクト',
        'organization_id' => $organization->id,
        'project_id' => 1,
    ]);

    $response->assertSessionHasErrors(['organization_id', 'project_id']);
    expect($organization->projects()->count())->toBe(0);
});

test('member はプロジェクトを作成できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/projects/create')->assertForbidden();
    $this->actingAs($member)->post('/projects', ['name' => '新プロジェクト'])->assertForbidden();
    expect($organization->projects()->count())->toBe(0);
});

test('member は詳細を閲覧できる (items と canManage=false が渡る)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    $project->items()->create(['name' => 'アイテム1']);

    $this->actingAs($member)->get("/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->has('items', 1)
            ->where('items.0.name', 'アイテム1')
            ->where('canManage', false));
});

test('canManageMembers prop は org admin=true / project_admin (org Member)=false', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);
    $editor->forceFill(['current_organization_id' => $organization->id])->save();

    // org owner/admin → 管理メニューのユーザー管理導線あり
    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page->where('canManageMembers', true));

    // 編集者 (project_admin だが org は Member) → canManage=true でも members 管理は不可
    $this->actingAs($editor)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManage', true)
            ->where('canManageMembers', false));
});

test('owner はプロジェクトを更新できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)->patch("/projects/{$project->id}", [
        'name' => '更新後',
        'description' => '更新後の説明',
    ]);

    $response->assertRedirect("/projects/{$project->id}");
    $project = $project->fresh();
    expect($project->name)->toBe('更新後');
    expect($project->description)->toBe('更新後の説明');
});

test('project_admin は組織管理権限がなくてもプロジェクトを更新できる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Admin);

    $this->actingAs($member)->patch("/projects/{$project->id}", ['name' => '更新後'])
        ->assertRedirect("/projects/{$project->id}");
    expect($project->fresh()->name)->toBe('更新後');
});

test('project_member ロールでは更新できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create(['name' => '元の名前']);
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($member)->patch("/projects/{$project->id}", ['name' => '更新後'])
        ->assertForbidden();
    expect($project->fresh()->name)->toBe('元の名前');
});

test('更新時に custom_team_id を送ると 422 (所属の付け替えは受け付けない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->patch("/projects/{$project->id}", [
        'name' => '更新後',
        'custom_team_id' => 999,
    ])->assertSessionHasErrors('custom_team_id');
});

test('owner はプロジェクトを削除できる (items もカスケード削除)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $item = $project->items()->create(['name' => 'アイテム1']);

    $response = $this->actingAs($owner)->delete("/projects/{$project->id}");

    $response->assertRedirect('/projects');
    $response->assertSessionHas('success');
    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse();
    expect(Item::query()->whereKey($item->id)->exists())->toBeFalse();
});

test('member はプロジェクトを削除できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($member)->delete("/projects/{$project->id}")->assertForbidden();
    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue();
});
