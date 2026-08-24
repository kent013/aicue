<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;

/*
 * 改名の認可 (家系裁定 AG-046)。
 *
 * ★層 2 (テナント境界 404 = binder) が層 3 (認可 403) より**前**である。
 *   binding の 404 だけでは same-org の一般メンバーによる改名を防げないので
 *   `Gate::authorize('update')` を通す。
 */

test('Owner は改名できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'owner-renamed'])
        ->assertRedirect('/organizations/owner-renamed/settings');
});

test('same-org の一般メンバーは 403 (404 ではない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    $this->actingAs($member)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'member-renamed'])
        ->assertForbidden();

    expect($organization->fresh()?->slug)->not->toBe('member-renamed');
});

test('cross-org は 404 (層 2 が層 3 より前 = 存在を漏らさない)', function (): void {
    [$organization] = createOrganizationWithOwner('他人の組織');
    [, $outsider] = createOrganizationWithOwner('自分の組織');

    $this->actingAs($outsider)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => 'stolen'])
        ->assertNotFound();
});
