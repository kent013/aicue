<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Services\ApiKey\ApiKeyPermissionService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * HandleInertiaRequests の共有 prop currentOrganization に slug + ナビ表示用の
 * 最小権限フラグ (canManageMembers / canManageApiKeys) が role 別に載ること、
 * および cross-org 分離 (別組織で付与された権限が現在組織へ漏れない) を固定する。
 *
 * 権限は OrganizationPolicy (organizationRole = laratrust_team_id 明示) を唯一の真実源とし、
 * defense-in-depth の isMemberOf フォールバックで dangling current を秘匿する。
 */

test('owner: slug + 両権限フラグ true を共有する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('オーナー組織');

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.id', $organization->id)
            ->where('currentOrganization.slug', $organization->slug)
            ->where('currentOrganization.role', OrganizationRole::Owner->value)
            ->where('currentOrganization.canManageMembers', true)
            ->where('currentOrganization.canManageApiKeys', true));
});

test('admin: 両権限フラグ true を共有する', function (): void {
    [$organization] = createOrganizationWithOwner('管理者組織');
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.role', OrganizationRole::Admin->value)
            ->where('currentOrganization.canManageMembers', true)
            ->where('currentOrganization.canManageApiKeys', true));
});

test('権限なし member: 両権限フラグ false を共有する', function (): void {
    [$organization] = createOrganizationWithOwner('一般組織');
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.slug', $organization->slug)
            ->where('currentOrganization.role', OrganizationRole::Member->value)
            ->where('currentOrganization.canManageMembers', false)
            ->where('currentOrganization.canManageApiKeys', false));
});

test('現在組織で manage-api-keys 直接付与された member: canManageApiKeys のみ true', function (): void {
    [$organization] = createOrganizationWithOwner('直接付与組織');
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    app(ApiKeyPermissionService::class)->grant($member, $organization);

    $this->actingAs($member->fresh())->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.canManageMembers', false)
            ->where('currentOrganization.canManageApiKeys', true));
});

test('別組織でのみ manage-api-keys 付与された member: 現在組織では canManageApiKeys=false (cross-org 漏れ防止)', function (): void {
    [$orgA] = createOrganizationWithOwner('現在組織A');
    [$orgB] = createOrganizationWithOwner('別組織B');

    $member = User::factory()->create();
    $orgA->users()->attach($member);
    $member->addRole(OrganizationRole::Member->value, $orgA->laratrust_team_id);
    $orgB->users()->attach($member);
    $member->addRole(OrganizationRole::Member->value, $orgB->laratrust_team_id);
    $member->forceFill(['current_organization_id' => $orgA->id])->save();

    // manage-api-keys は別組織 B にのみ付与 (現在組織 A ではない)
    app(ApiKeyPermissionService::class)->grant($member, $orgB);

    $this->actingAs($member->fresh())->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.id', $orgA->id)
            ->where('currentOrganization.canManageApiKeys', false)
            ->where('currentOrganization.canManageMembers', false));
});

test('current_organization_id が所属外 org を指す (データドリフト): currentOrganization=null で秘匿する', function (): void {
    // 所属 0 件のユーザーは resolver が自己修復候補を持たないため current は dangling のまま残り、
    // S1 の isMemberOf フォールバックが slug/name を露出せず null に倒す (defense-in-depth)。
    $foreign = createOrganizationWithOwner('他人の組織')[0];
    $user = User::factory()->create();
    $user->forceFill(['current_organization_id' => $foreign->id])->save();

    expect($user->isMemberOf($foreign))->toBeFalse();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('currentOrganization', null));
});
