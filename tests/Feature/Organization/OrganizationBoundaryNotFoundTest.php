<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| org-boundary-404: 組織境界の 4 象限を代表 route 群で固定する
|--------------------------------------------------------------------------
|
|   guest                            = 302 login (auth が SubstituteBindings より priority 先行)
|   認証済み非メンバー / 不在 slug    = 404 (テナント存在秘匿、応答差分なし)
|   same-org メンバー・権限不足       = 403 (従来どおり Policy の責務、秘匿対象でない)
|   same-org メンバー・権限あり       = 200 / 2xx
|
| 非メンバー 404 は MembershipScopedOrganizationBinder (web `{organization}` binding の
| 単一関所) が担う。403 が返るようになったら binder の回帰 = Critical。
*/

it('非メンバーは org 配下 GET route で 404 (存在秘匿)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $intruder = User::factory()->create(); // どの組織にも所属させない

    $this->actingAs($intruder)
        ->get(route('organizations.settings', $organization))
        ->assertNotFound();
});

it('他組織メンバーでも対象 org 非メンバーなら 404 (クロステナント秘匿)', function (): void {
    [$organization] = createOrganizationWithOwner();
    [, $outsider] = createOrganizationWithOwner('別組織'); // 別組織の Owner

    $this->actingAs($outsider)
        ->get(route('organizations.settings', $organization))
        ->assertNotFound();
});

it('不在 slug は非メンバーと同一の 404 (存在の応答差分なし)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create();

    $nonMemberResponse = $this->actingAs($user)
        ->get(route('organizations.settings', $organization));
    $missingResponse = $this->actingAs($user)
        ->get('/organizations/no-such-org/settings');

    $nonMemberResponse->assertNotFound();
    $missingResponse->assertNotFound();
});

it('非メンバーは書き込み route (update) でも 404', function (): void {
    [$organization] = createOrganizationWithOwner();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->patch(route('organizations.update', $organization), ['name' => 'Hijacked'])
        ->assertNotFound();

    expect($organization->fresh()->name)->not->toBe('Hijacked');
});

it('guest は 302 login (binding 前に auth が走る)', function (): void {
    [$organization] = createOrganizationWithOwner();

    $this->get(route('organizations.settings', $organization))
        ->assertRedirect(route('login'));
});

it('メンバー (Owner) は GET route に正常アクセスできる (200)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get(route('organizations.settings', $organization))
        ->assertOk();
});

it('メンバー (Owner) は update で正常更新できる (302)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->from(route('organizations.settings', $organization))
        ->patch(route('organizations.update', $organization), ['name' => '改名した組織'])
        ->assertRedirect(route('organizations.settings', $organization));

    expect($organization->fresh()->name)->toBe('改名した組織');
});

it('same-org 権限不足は 403 を維持 (秘匿対象でない): Member の organizations.update', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    $this->actingAs($member)
        ->patch(route('organizations.update', $organization), ['name' => 'Should Fail'])
        ->assertForbidden();
});

it('same-org 権限不足は 403 を維持 (秘匿対象でない): Member の invitations.store', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    $this->actingAs($member)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'someone@example.com',
            'role' => OrganizationRole::Admin->value,
        ])
        ->assertForbidden();
});
