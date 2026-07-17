<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\TicketPricingService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 課金オンボーディングの Plan 選択画面 (/onboarding/checkout。current org スコープ)。
 *
 * 入口ガードは 2 式のみを読む (新しい述語を発明しない):
 *   1. BillingAccess::hasActiveAccess() → billing.index へ
 *   2. Gate::allows('manageBilling')    → 不成立なら onboarding.billing-required へ
 * 判定順序 (hasActiveAccess → manageBilling) は「契約済み non-manager が誤って
 * billing-required に飛ばない」ための load-bearing な順序。
 */

/** ExpiredCheckout (plan_code 非 null + entitled でない sub) の組織 + owner。 */
function expiredCheckoutOrganizationWithOwner(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'canceled');

    return [$organization->fresh(), $owner];
}

test('current org 不在なら 404 (組織の有無を露出しない)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/onboarding/checkout')->assertNotFound();
});

test('current org に非所属なら 404 (403 で存在を漏らさない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    // current_organization_id が退会後も残存する不整合を再現する
    $outsider = User::factory()->create();
    $outsider->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($outsider)->get('/onboarding/checkout')->assertNotFound();
});

test('ExpiredCheckout + manageBilling は Plan 選択画面を 200 で描画する', function (): void {
    [, $owner] = expiredCheckoutOrganizationWithOwner();

    $this->actingAs($owner)->get('/onboarding/checkout')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Checkout')
            ->where('organization.name', 'テスト組織')
            // is_active=true ∧ code ∈ {personal,starter,standard,business} のみ。
            // legacy free 行は code 集合外 / business は Plan 行が無いため出ない。
            ->has('pageData.plans', 3)
            ->where('pageData.plans.0.code', 'personal')   // sort_order 昇順
            ->where('pageData.plans.0.currentBaseAmount', null) // base price 不在 = 無料表示契約
            ->where('pageData.plans.1.code', 'starter')
            ->where('pageData.plans.1.currentBaseAmount', 980)
            ->where('pageData.plans.2.code', 'standard')
            ->where('pageData.plans.2.currentBaseAmount', 4980)
            ->where('pageData.recommendedPlanCode', 'standard')
            ->where('pageData.defaultPlanCode', 'starter')
            ->where('pageData.contactUrl', '/contact?source=onboarding')
            ->where('pageData.signupGrantTickets', app(TicketPricingService::class)->signupGrantTickets())
            // personal は is_active=true のため eligibility は常に非 null
            ->where('pageData.personalEligibility.eligible', true)
            ->where('pageData.personalEligibility.reason', null));
});

test('ExpiredCheckout + manageBilling なし member は billing-required へ redirect (判定順序の固定)', function (): void {
    [$organization] = expiredCheckoutOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/onboarding/checkout')
        ->assertRedirect(route('onboarding.billing-required'));
});

test('Subscribed は manageBilling でも billing.index へ redirect', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'active');

    $this->actingAs($owner)->get('/onboarding/checkout')
        ->assertRedirect(route('billing.index'));
});

test('Subscribed の non-manager member は billing-required ではなく billing.index へ (判定順序の固定)', function (): void {
    [$organization] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'active');
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/onboarding/checkout')
        ->assertRedirect(route('billing.index'));
});

test('ActiveFreePlan (free_plan_code=personal) は billing.index へ redirect', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['plan_code' => 'standard'])->save(); // 移行 OR を経由しないことの明示
    contractPaidPlan($organization, status: 'canceled');
    $organization->forceFill([
        'free_plan_code' => 'personal',
        'free_plan_activated_at' => now(),
        'personal_declared_at' => now(),
        'personal_declared_by_user_id' => $owner->getKey(),
    ])->save();

    $this->actingAs($owner)->get('/onboarding/checkout')
        ->assertRedirect(route('billing.index'));
});

test('未契約 org (plan_code IS NULL) は移行 OR により billing.index へ redirect する — P4 の移行 OR 削除で 200 render へ変わる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    expect($organization->plan_code)->toBeNull()
        ->and($organization->free_plan_code)->toBeNull();

    // P3 の事実: hasActiveAccess() の移行 OR (plan_code === null) が true を返すため
    // checkout は画面として到達しない。P4 で OR の 1 行を消すと 200 render へ反転する
    // (期待の更新は P4 のテスト計画。本テストは削除せず更新する)。
    $this->actingAs($owner)->get('/onboarding/checkout')
        ->assertRedirect(route('billing.index'));
});

test('is_active=false に落とした Plan は pageData.plans に出ない (露出規則の固定)', function (): void {
    [, $owner] = expiredCheckoutOrganizationWithOwner();
    Plan::query()->where('code', 'standard')->update(['is_active' => false]);

    $this->actingAs($owner)->get('/onboarding/checkout')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pageData.plans', 2)
            ->where('pageData.plans.0.code', 'personal')
            ->where('pageData.plans.1.code', 'starter'));
});

test('personal 選択不可の理由はサーバー確定文言で props に載る', function (): void {
    [$organization, $owner] = expiredCheckoutOrganizationWithOwner();
    // 同一 declarer の別 free personal org を作り AlreadyHasFreePersonalOrg を成立させる
    Organization::factory()->freePersonal($owner)->create();
    $owner->addRole(OrganizationRole::Owner->value, $organization->laratrust_team_id);

    $this->actingAs($owner)->get('/onboarding/checkout')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pageData.personalEligibility.eligible', false)
            ->where('pageData.personalEligibility.reason', 'already_has_free_personal_org')
            ->where('pageData.personalEligibility.reasonLabel', '既にパーソナルプラン（無料）の組織をお持ちのため、この組織では選択できません。'));
});

test('未認証は login へ', function (): void {
    $this->get('/onboarding/checkout')->assertRedirect('/login');
});
