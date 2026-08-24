<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Onboarding\IntendedPlanResolver;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * P7: Onboarding/Checkout の ?plan= canonical 303 + session 反映 + リロード耐性。
 *
 * `?plan=` はユーザー入力のため PlanCode allowlist に照合し、未知値・Enterprise は
 * 安全側 (= 意図なし) に倒す。org-scoped key は「不在は no-op」= リロードで消えない。
 */

/** 未契約 (free_plan_code NULL) 組織 + manageBilling 保持 owner。 */
function unsubscribedOrgWithBillingOwner(): array
{
    return createOrganizationWithOwner(grandfatherFreePlan: false);
}

test('?plan=standard は org-scoped session に積んで canonical URL へ 303 する', function (): void {
    [$organization, $owner] = unsubscribedOrgWithBillingOwner();

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout?plan=standard");

    $response->assertStatus(303);
    $response->assertRedirect(route('onboarding.checkout', ['organization' => $organization->slug]));
    expect(session(IntendedPlanResolver::orgKey($organization)))->toBe('standard');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Checkout')
            ->where('pageData.intendedPlanCode', 'standard'));
});

test('plan なしのリロードでは preselect が消えない (org-scoped no-op 規約)', function (): void {
    [$organization, $owner] = unsubscribedOrgWithBillingOwner();
    session([IntendedPlanResolver::orgKey($organization) => 'starter']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', 'starter'));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', 'starter'));

    expect(session(IntendedPlanResolver::orgKey($organization)))->toBe('starter');
});

test('?plan=enterprise は preselect されず org-scoped session も消える', function (): void {
    [$organization, $owner] = unsubscribedOrgWithBillingOwner();
    session([IntendedPlanResolver::orgKey($organization) => 'standard']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout?plan=enterprise")
        ->assertStatus(303);

    expect(session(IntendedPlanResolver::orgKey($organization)))->toBeNull();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', null));
});

test('?plan=foo (未知値) も 303 のうえ session を消す', function (): void {
    [$organization, $owner] = unsubscribedOrgWithBillingOwner();
    session([IntendedPlanResolver::orgKey($organization) => 'standard']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout?plan=foo")
        ->assertStatus(303);

    expect(session(IntendedPlanResolver::orgKey($organization)))->toBeNull();
});

test('org-scoped session は組織ごとに独立している (A の意図が B に漏れない)', function (): void {
    [$orgA, $owner] = unsubscribedOrgWithBillingOwner();
    $orgB = Organization::factory()->create();
    session([IntendedPlanResolver::orgKey($orgA) => 'standard']);

    $this->actingAs($owner)->get("/organizations/{$orgA->slug}/onboarding/checkout")
        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', 'standard'));

    expect(session(IntendedPlanResolver::orgKey($orgB)))->toBeNull();
});

test('session が改ざんされ enterprise が入っていても peek が null 化する (防御)', function (): void {
    [$organization, $owner] = unsubscribedOrgWithBillingOwner();
    session([IntendedPlanResolver::orgKey($organization) => 'enterprise']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', null));
});

test('intended plan なしの通常描画では intendedPlanCode が null', function (): void {
    [$organization, $owner] = unsubscribedOrgWithBillingOwner();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Checkout')
            ->where('pageData.intendedPlanCode', null));
});
