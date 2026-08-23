<?php

declare(strict_types=1);

use App\Services\Organization\OrganizationProvisioningService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * /settings の削除前警告 props (accountDeletionBlockers)。
 * 「退会をブロックしている組織」と「次の一手 (action)」を表示時点のスナップショットで返す
 * (最終判定は削除時にサーバーがロック下で再評価する)。
 */

test('唯一オーナーで他メンバーが残る組織は accountDeletionBlockers に移譲 action で現れる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization); // 孤児化する残存メンバー

    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->has('accountDeletionBlockers', 1)
            ->where('accountDeletionBlockers.0.slug', $organization->slug)
            ->where('accountDeletionBlockers.0.name', $organization->name)
            ->where('accountDeletionBlockers.0.actions', ['transfer_ownership']));
});

test('課金中の現在組織は accountDeletionBlockers に解約 action で現れる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    createFakeSubscription($organization, status: 'active');

    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->has('accountDeletionBlockers', 1)
            ->where('accountDeletionBlockers.0.slug', $organization->slug)
            ->where('accountDeletionBlockers.0.actions', ['open_billing']));
});

test('所属が複数でも課金中の組織の解約 action はその組織の課金画面へ直接向く', function (): void {
    // 組織文脈は URL だけで決まるので「いま見ている組織かどうか」で action は変わらない
    // (切替を挟む一手は保持列・切替 endpoint の撤去とともに概念ごと消えた)。
    [, $owner] = createOrganizationWithOwner();
    $other = app(OrganizationProvisioningService::class)->provision($owner, '別組織');
    createFakeSubscription($other, status: 'active');

    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->has('accountDeletionBlockers', 1)
            ->where('accountDeletionBlockers.0.slug', $other->slug)
            ->where('accountDeletionBlockers.0.actions', ['open_billing']));
});

test('ブロック要因が無いユーザーは accountDeletionBlockers が空', function (): void {
    [, $owner] = createOrganizationWithOwner(); // owner 1 人・他メンバー無し・課金なし

    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->has('accountDeletionBlockers', 0));
});
