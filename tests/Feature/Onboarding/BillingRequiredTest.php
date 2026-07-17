<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 未契約 + manageBilling なし member 向け説明画面 (/billing-required。current org スコープ)。
 *
 * 403 ではなく専用ページを返すことで「行き先のない詰み」を回避する。逆に、この画面を
 * 見せる理由がない者 (利用可 / manageBilling 保持者) は離脱ガードで本来の行き先へ逃がす。
 */

/** ExpiredCheckout (plan_code 非 null + entitled でない sub) の組織 + owner。 */
function billingRequiredExpiredOrganization(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'canceled');

    return [$organization->fresh(), $owner];
}

test('current org 不在なら 404', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/billing-required')->assertNotFound();
});

test('current org に非所属なら 404 (403 で存在を漏らさない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $outsider = User::factory()->create();
    $outsider->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($outsider)->get('/billing-required')->assertNotFound();
});

test('ExpiredCheckout の一般 member には Owner 連絡先付きで 200 render される', function (): void {
    [$organization, $owner] = billingRequiredExpiredOrganization();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/billing-required')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/BillingRequired')
            ->where('organization.name', 'テスト組織')
            ->where('pageData.ownerName', $owner->name)
            ->where('pageData.ownerEmail', $owner->email)
            ->where('pageData.contactUrl', '/contact?source=onboarding'));
});

test('離脱ガード: 有効 subscription を持つ member は dashboard へ', function (): void {
    [$organization] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'active');
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/billing-required')
        ->assertRedirect(route('dashboard'));
});

test('離脱ガード: ActiveFreePlan (free_plan_code=personal) の member は dashboard へ', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill([
        'free_plan_code' => 'personal',
        'free_plan_activated_at' => now(),
        'personal_declared_at' => now(),
        'personal_declared_by_user_id' => $owner->getKey(),
    ])->save();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/billing-required')
        ->assertRedirect(route('dashboard'));
});

test('離脱ガード: manageBilling 保持者は checkout へ (自分で手続きできる)', function (): void {
    [, $owner] = billingRequiredExpiredOrganization();

    $this->actingAs($owner)->get('/billing-required')
        ->assertRedirect(route('onboarding.checkout'));
});

test('未契約 org (plan_code IS NULL) の一般 member も 200 render される — P4 の grandfathering backfill 後は ActiveFreePlan → dashboard へ変わる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    // state() は移行 OR を持たないため NoSubscription = 遮断側。まだ遮断されていない member に
    // 説明画面が見える (P3 の既知の非対称)。P3 では UI からこの画面へリンクを張らないため
    // 通常導線からは到達しない。P4 の backfill で ActiveFreePlan になり離脱ガードが dashboard
    // へ逃がす = 自然解消 (期待の更新は P4。本テストは削除せず更新する)。
    expect($organization->plan_code)->toBeNull();

    $this->actingAs($member)->get('/billing-required')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Onboarding/BillingRequired'));
});

test('Owner 不在 org でも 200 で ownerName / ownerEmail は null', function (): void {
    $organization = Organization::factory()->create();
    $organization->forceFill(['plan_code' => 'standard'])->save();
    createFakeSubscription($organization, status: 'canceled');

    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    expect($organization->users()->get()
        ->first(fn (User $u): bool => $u->organizationRole($organization) === OrganizationRole::Owner))
        ->toBeNull();

    $this->actingAs($member)->get('/billing-required')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/BillingRequired')
            ->where('pageData.ownerName', null)
            ->where('pageData.ownerEmail', null)
            // 詰みを避けるため問い合わせ導線は常に出す
            ->where('pageData.contactUrl', '/contact?source=onboarding'));
});

test('未認証は login へ', function (): void {
    $this->get('/billing-required')->assertRedirect('/login');
});
