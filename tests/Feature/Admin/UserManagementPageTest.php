<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;

/*
 * 管理メニュー > ユーザー管理 (GET /manage/users)。
 * 読み取り専用画面 (書き込みは既存 organizations.* endpoint)。
 * PII (email) の可視性契約: manageMembers 権限者しか画面自体に到達できない (403 境界)。
 */

test('org Owner は 200 + Admin/Users component で members/invitations shape を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->editorInvitation()
        ->create(['email' => 'pending-editor@example.com']);

    $response = $this->actingAs($owner)->get('/manage/users');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Users')
        ->where('organizationSlug', $organization->slug)
        ->where('members.0.roleState', 'owner')
        ->where('members.0.isSelf', true)
        ->where('invitations.0.email', 'pending-editor@example.com')
        ->where('invitations.0.roleState', 'editor')
        ->where('hasDefaultProject', false)
        ->where('categoriesUrl', null));
});

test('org Admin も閲覧できる (200)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($admin)->get('/manage/users')->assertOk();
});

test('org Member (編集者 = project_admin でも org は Member) は 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);
    $editor->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($editor)->get('/manage/users')->assertForbidden();
});

test('未ログインは login へ redirect される', function (): void {
    $this->get('/manage/users')->assertRedirect('/login');
});

test('roleState 導出: owner/admin/editor/shooter/unassigned の 5 状態が rows に正しく出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);
    $shooter = attachOrganizationMember($organization);
    attachProjectMember($project, $shooter, ProjectRole::Member);
    $unassigned = attachOrganizationMember($organization);
    // Laratrust ロール未付与の異常行 (attach のみ) も「未割当」として表示される
    $broken = User::factory()->create();
    $organization->users()->attach($broken);

    $response = $this->actingAs($owner)->get('/manage/users');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($owner, $admin, $editor, $shooter, $unassigned, $broken): void {
        /** @var list<array{id: int, roleState: string}> $members */
        $members = $page->toArray()['props']['members'];
        $states = [];
        foreach ($members as $row) {
            $states[$row['id']] = $row['roleState'];
        }
        expect($states[$owner->id])->toBe('owner');
        expect($states[$admin->id])->toBe('admin');
        expect($states[$editor->id])->toBe('editor');
        expect($states[$shooter->id])->toBe('shooter');
        expect($states[$unassigned->id])->toBe('unassigned');
        expect($states[$broken->id])->toBe('unassigned');
    });
});

test('categoriesUrl: project があり org admin なら URL・project 不在なら null', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/manage/users')
        ->assertInertia(fn ($page) => $page->where('categoriesUrl', null)->where('hasDefaultProject', false));

    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/manage/users')
        ->assertInertia(fn ($page) => $page
            ->where('hasDefaultProject', true)
            ->where('categoriesUrl', route('projects.categories.index', $project)));
});

test('招待一覧は active のみ (失効・受諾済・取消済は出ない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'active@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->expired()->create(['email' => 'expired@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->accepted()->create(['email' => 'accepted@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->revoked()->create(['email' => 'revoked@example.com']);

    $response = $this->actingAs($owner)->get('/manage/users');

    $response->assertInertia(fn ($page) => $page
        ->count('invitations', 1)
        ->where('invitations.0.email', 'active@example.com')
        // 旧招待 (project_role なし) は未割当語彙で表示される
        ->where('invitations.0.roleState', 'unassigned'));
});

test('current org 未設定 (組織未所属状態) は 404', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/manage/users')->assertNotFound();
});
