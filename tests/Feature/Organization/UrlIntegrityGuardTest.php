<?php

declare(strict_types=1);

/*
 * URL 整合 guard (D2 不変条件): 子リソースが URL 上の親 ({organization}) に属さない場合、
 * 認可より前に 404 を返す (403 で存在を漏らさない)。
 * 親 {organization} 自体の非メンバー 404 は MembershipScopedOrganizationBinder が担う
 * (OrganizationBoundaryNotFoundTest)。ここでは「親は解決できるが子が越境」の層を固定する。
 */

test('他組織のユーザーへのメンバー操作は 404 (認可より前)', function (): void {
    [$orgA, $ownerA] = createOrganizationWithOwner('組織A');
    [, $ownerB] = createOrganizationWithOwner('組織B');

    // ownerB は orgA のメンバーではない → 404 (422 や 403 ではない)
    $this->actingAs($ownerA)
        ->patch("/organizations/{$orgA->slug}/members/{$ownerB->id}", ['role' => 'organization_member'])
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->delete("/organizations/{$orgA->slug}/members/{$ownerB->id}")
        ->assertNotFound();
});

test('権限のないメンバーでも cross-org 対象なら 403 ではなく 404', function (): void {
    [$orgA] = createOrganizationWithOwner('組織A');
    $memberA = attachOrganizationMember($orgA);
    [, $ownerB] = createOrganizationWithOwner('組織B');

    // memberA は manageMembers 権限を持たないが、cross-org 不整合が先に 404 になる
    $this->actingAs($memberA)
        ->delete("/organizations/{$orgA->slug}/members/{$ownerB->id}")
        ->assertNotFound();
});

test('存在しないユーザー ID は 404', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->delete("/organizations/{$organization->slug}/members/999999")
        ->assertNotFound();
});
