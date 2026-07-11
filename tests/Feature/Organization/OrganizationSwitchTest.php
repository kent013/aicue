<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;

/*
 * 組織切替 (users.current_organization_id の更新)。
 * {organization} は MembershipScopedOrganizationBinder が membership スコープで解決する。
 */

test('所属している組織へ切り替えられる', function (): void {
    [$organizationA, $user] = createOrganizationWithOwner('組織A');
    $organizationB = Organization::factory()->create();
    $organizationB->users()->attach($user);
    $user->addRole(OrganizationRole::Member->value, $organizationB->laratrust_team_id);

    expect($user->current_organization_id)->toBe($organizationA->id);

    $response = $this->actingAs($user)->post("/organizations/{$organizationB->id}/switch");

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('success');
    expect($user->fresh()->current_organization_id)->toBe($organizationB->id);
});

test('所属していない組織へは切り替えられない (存在秘匿で 404)', function (): void {
    [$organizationA, $user] = createOrganizationWithOwner('組織A');
    $other = Organization::factory()->create();

    $response = $this->actingAs($user)->post("/organizations/{$other->id}/switch");

    $response->assertNotFound();
    expect($user->fresh()->current_organization_id)->toBe($organizationA->id);
});

test('新規組織を作成すると provisioning が走り current が切り替わる', function (): void {
    [, $user] = createOrganizationWithOwner('既存組織');

    $response = $this->actingAs($user)->post('/organizations', ['name' => '新しい組織']);

    $user = $user->fresh();
    /** @var Organization $created */
    $created = $user->currentOrganization;
    $response->assertRedirect("/organizations/{$created->slug}/settings");
    expect($created->name)->toBe('新しい組織');
    // 組織作成のあらゆる経路で Default Team が生成される (不変条件)
    expect($created->customTeams()->where('is_default', true)->count())->toBe(1);
    expect($user->organizationRole($created))->toBe(OrganizationRole::Owner);
});

test('current organization が未設定でも所属組織の設定へ slug でアクセスできる', function (): void {
    [$organization, $user] = createOrganizationWithOwner();
    $user->forceFill(['current_organization_id' => null])->save();

    // 組織管理系ルートは current に依存しない (binder が membership で解決する)
    $response = $this->actingAs($user)->get("/organizations/{$organization->slug}/settings");

    $response->assertOk();
});

test('組織設定画面はメンバー一覧と自分のロールを返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('設定組織');
    attachOrganizationMember($organization, OrganizationRole::Member);

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/settings");

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->component('Organizations/Settings')
            ->where('organization.name', '設定組織')
            ->where('organization.slug', $organization->slug)
            ->where('currentUserRole', OrganizationRole::Owner->value)
            ->has('members', 2)
            // メンバー管理はユーザー管理画面へ移設済み: settings は最小 shape (id/name) のみ
            ->missing('invitations')
            ->missing('members.0.email'),
    );
});

test('非メンバーには組織設定の存在自体を開示しない (404)', function (): void {
    [$organization] = createOrganizationWithOwner();
    [, $outsider] = createOrganizationWithOwner('別組織');
    // current_organization_id を直接他組織に向けても binder (membership スコープ) が 404 に倒す
    $outsider->forceFill(['current_organization_id' => $organization->id])->save();

    $response = $this->actingAs($outsider)->get("/organizations/{$organization->slug}/settings");

    $response->assertNotFound();
});
