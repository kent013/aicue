<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Services\ApiKey\ApiKeyPermissionService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * sidebar visibility contract:
 * HandleInertiaRequests の共有 prop currentOrganization に slug + ナビ表示用の
 * 最小権限フラグ (canManageMembers / canManageApiKeys) が role 別に載ること、
 * および cross-org 分離 (別組織で付与された権限が現在組織へ漏れない) を固定する。
 * 左サイドバー (templates/AppLayout) の org 導線可視条件はこの shared prop に依存するため、
 * 本テストが UI 可視条件の回帰を検知する契約テストを兼ねる (将来の shape 破壊を止める)。
 *
 * 権限は OrganizationPolicy (organizationRole = laratrust_team_id 明示) を唯一の真実源とする。
 * 組織文脈は **URL の binding からのみ**導出する (家系裁定 AG-037)。
 * したがって組織 route 以外では必ず null であり、「所属している組織のどれか」を裏口で選ばない。
 */

test('未認証: currentOrganization / auth.user とも null を共有する (sidebar visibility contract)', function (): void {
    // ゲスト到達 Inertia ページ (Fortify loginView = Auth/Login) で shared prop の未認証 shape を固定。
    // サイドバーはこの null を見て nav / メニュー / ベルを一切描画しない。
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization', null)
            ->where('auth.user', null));
});

test('owner: slug + 両権限フラグ true を共有する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('オーナー組織');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
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

    $this->actingAs($admin)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.role', OrganizationRole::Admin->value)
            ->where('currentOrganization.canManageMembers', true)
            ->where('currentOrganization.canManageApiKeys', true));
});

test('権限なし member: 両権限フラグ false を共有する', function (): void {
    [$organization] = createOrganizationWithOwner('一般組織');
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    $this->actingAs($member)->get("/organizations/{$organization->slug}/dashboard")
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
    app(ApiKeyPermissionService::class)->grant($member, $organization);

    $this->actingAs($member->fresh())->get("/organizations/{$organization->slug}/dashboard")
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

    // manage-api-keys は別組織 B にのみ付与 (現在組織 A ではない)
    app(ApiKeyPermissionService::class)->grant($member, $orgB);

    $this->actingAs($member->fresh())->get("/organizations/{$orgA->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.id', $orgA->id)
            ->where('currentOrganization.canManageApiKeys', false)
            ->where('currentOrganization.canManageMembers', false));
});

test('非所属の組織 URL は 404 (共有 prop 以前にテナント境界で落ちる)', function (): void {
    $foreign = createOrganizationWithOwner('他人の組織')[0];
    $user = User::factory()->create();

    expect($user->isMemberOf($foreign))->toBeFalse();

    $this->actingAs($user)->get("/organizations/{$foreign->slug}/dashboard")->assertNotFound();
});

test('組織 route 以外では currentOrganization が必ず null (裏口で選ばない)', function (): void {
    [, $owner] = createOrganizationWithOwner('所属している組織');

    $this->actingAs($owner)->get('/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('currentOrganization', null));
});
