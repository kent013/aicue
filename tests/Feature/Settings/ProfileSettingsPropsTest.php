<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('唯一オーナーは /settings で soleOwnedOrganizations に該当組織を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization); // 孤児化する残存メンバー

    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->has('soleOwnedOrganizations', 1)
            ->where('soleOwnedOrganizations.0.slug', $organization->slug)
            ->where('soleOwnedOrganizations.0.name', $organization->name));
});

test('孤児化リスクが無いユーザーは soleOwnedOrganizations が空', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(); // owner 1 人・他メンバー無し

    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->has('soleOwnedOrganizations', 0));
});
