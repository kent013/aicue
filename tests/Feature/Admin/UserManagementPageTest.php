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
    OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'pending-member@example.com', 'role' => OrganizationRole::Member->value]);

    $response = $this->actingAs($owner)->get('/manage/users');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Users')
        ->where('organizationSlug', $organization->slug)
        ->where('members.0.roleState', 'owner')
        ->where('members.0.isSelf', true)
        ->where('invitations.0.email', 'pending-member@example.com')
        ->where('invitations.0.role', OrganizationRole::Member->value)
        ->where('invitations.0.roleLabel', 'メンバー')
        ->where('hasDefaultProject', false)
        // T071: 独自二次左メニュー(AdminMenuNav)撤去に伴い categoriesUrl prop は廃止 → 存在しない
        ->missing('categoriesUrl'));
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

// CTA 導線の到達性 (T067): ユーザー管理注記の「プロジェクトを作成」リンクが指す
// projects.create に、ユーザー管理を見られる owner/admin は到達でき、見られない member は
// 到達できない (403) ことを HTTP レベルで固定する。CTA が 403 で詰まらない不変条件を守る。
test('CTA 導線: Owner/Admin は projects.create に到達できる (200)', function (): void {
    // createOrganizationWithOwner は無償プラン (plan_code null) = 課金ゲート通過
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($owner)->get('/projects/create')->assertOk();
    $this->actingAs($admin)->get('/projects/create')->assertOk();
});

test('CTA 導線: org Member は projects.create で 403 (権限境界が非退化)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/projects/create')->assertForbidden();
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

test('categoriesUrl prop は撤去済み (T071: カテゴリ導線はプロジェクト詳細へ移設)。hasDefaultProject は維持', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    // AdminMenuNav 撤去に伴い categoriesUrl prop は存在しない (project 有無に関わらず)
    $this->actingAs($owner)->get('/manage/users')
        ->assertInertia(fn ($page) => $page->missing('categoriesUrl')->where('hasDefaultProject', false));

    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get('/manage/users')
        ->assertInertia(fn ($page) => $page
            ->where('hasDefaultProject', true)
            ->missing('categoriesUrl'));
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
        // 招待は org ロールで表示される (役割付き招待は AG-079 で撤去)
        ->where('invitations.0.role', OrganizationRole::Member->value)
        ->where('invitations.0.roleLabel', 'メンバー'));
});

test('current org 未設定 (組織未所属状態) は 404', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/manage/users')->assertNotFound();
});
