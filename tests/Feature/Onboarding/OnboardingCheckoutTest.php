<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\TicketPricingService;
use Carbon\CarbonImmutable;
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
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'canceled');

    return [$organization->fresh(), $owner];
}

test('非所属の組織 URL は 404 (組織の有無を露出しない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/organizations/{$organization->slug}/onboarding/checkout")->assertNotFound();
});

test('URL 上の組織に非所属なら 404 (403 で存在を漏らさない)', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get("/organizations/{$organization->slug}/onboarding/checkout")->assertNotFound();
});

test('ExpiredCheckout + manageBilling は Plan 選択画面を 200 で描画する', function (): void {
    [$organization, $owner] = expiredCheckoutOrganizationWithOwner();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
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

    $this->actingAs($member)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('onboarding.billing-required', ['organization' => $organization->slug]));
});

test('Subscribed は manageBilling でも billing.index へ redirect', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'active');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('billing.index', ['organization' => $organization->slug]));
});

// #2 [期待更新 Q-2-01]: 契約済み (paid) の非管理メンバーは、自分で操作できない
// billing.index ではなく業務入口 dashboard へ寄せる。判定順序 (hasActiveAccess →
// manageBilling) は不変で、分岐先だけを manageBilling 能力で切り替える。
test('Subscribed の non-manager member は billing.index ではなく dashboard へ (Q-2-01)', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'active');
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('app.entry'));
});

test('Subscribed の manageBilling 保持 owner は billing.index へ (Q-2-01 で不変)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization, status: 'active');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('billing.index', ['organization' => $organization->slug]));
});

/** ActiveFreePlan (free_plan_code=personal) の組織にする。 */
function activateFreePersonalPlan(Organization $organization, User $declaredBy): void
{
    $organization->forceFill(['plan_code' => 'standard'])->save(); // 移行 OR を経由しないことの明示
    contractPaidPlan($organization, status: 'canceled');
    $organization->forceFill([
        'free_plan_code' => 'personal',
        'free_plan_activated_at' => now(),
        'personal_declared_at' => now(),
        'personal_declared_by_user_id' => $declaredBy->getKey(),
    ])->save();
}

test('ActiveFreePlan (free_plan_code=personal) の manageBilling 保持 owner は billing.index へ (Q-2-01 で不変)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    activateFreePersonalPlan($organization, $owner);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('billing.index', ['organization' => $organization->slug]));
});

// #4 [新規 Q-2-01]: bug-hunt が観測した実シナリオ。ActiveFreePlan (Personal free) の
// 組織に属する非管理メンバーは、請求画面ではなく dashboard へ着地する。
test('ActiveFreePlan + manageBilling 非保持 member は dashboard へ (Q-2-01 の既契約=Personal free ケース)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    activateFreePersonalPlan($organization, $owner);
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('app.entry'));
});

// #6 [characterization / 境界回帰]: 未契約 (hasActiveAccess=false) の非管理メンバーは
// dashboard には行かず billing-required へ。#4 と最も取り違えやすい境界 (active access の
// 有無で dashboard か billing-required かが分かれる) を固定する。現行コードでも緑であり
// (仕様変更テストではない)、変更後も緑を維持することで変更範囲が「active access を持つ
// 非管理者だけ」であることを保証する。
test('未契約 + manageBilling 非保持 member は billing-required へ (dashboard には行かない)', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('onboarding.billing-required', ['organization' => $organization->slug]));
});

// [着地の実効性]: dashboard への 302 の先で、非管理メンバーでも Dashboard 画面が
// 課金ゲートに阻まれず 200 で開くこと (soft dead-end でないこと) を段階で固定する。
test('dashboard 着地は 302 の先で実際に Dashboard 画面が 200 描画される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    activateFreePersonalPlan($organization, $owner);
    $member = attachOrganizationMember($organization);

    // (1) onboarding.checkout が dashboard へ 302。
    $this->actingAs($member)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('dashboard', ['organization' => $organization->slug]));

    // (2)(3) 同一認証ユーザーで dashboard を GET すると 200 で Dashboard 画面が描画される。
    $this->actingAs($member)->get(route('dashboard', ['organization' => $organization->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('Dashboard'));
});

test('未契約 org (plan_code IS NULL) は checkout を 200 で render する (P4 ゲート反転後)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    expect($organization->plan_code)->toBeNull()
        ->and($organization->free_plan_code)->toBeNull();

    // P3 時点では hasActiveAccess() の移行 OR (plan_code === null) が true を返すため
    // checkout は画面として到達せず billing.index へ redirect していた。
    // P4 で OR の 1 行を削除した結果、未契約 org は state()=NoSubscription で
    // grantsAccess()=false となり、checkout が本来の着地点として 200 render される。
    // (テストは削除せず期待を反転させた = P4 のテスト計画どおり)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertOk();
});

test('is_active=false に落とした Plan は pageData.plans に出ない (露出規則の固定)', function (): void {
    [$organization, $owner] = expiredCheckoutOrganizationWithOwner();
    Plan::query()->where('code', 'standard')->update(['is_active' => false]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
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

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pageData.personalEligibility.eligible', false)
            ->where('pageData.personalEligibility.reason', 'already_has_free_personal_org')
            ->where('pageData.personalEligibility.reasonLabel', '既にパーソナルプラン（無料）の組織をお持ちのため、この組織では選択できません。'));
});

test('未認証は login へ', function (): void {
    $this->get('/organizations/guest-org/onboarding/checkout')->assertRedirect('/login');
});

// ── 支払い未解決の契約がある組織はプラン選択ではなく課金画面へ逃がす ──
//
// 新規契約を作らせても二重請求になるだけで、利用者の次の一手は「支払い方法の更新」である。
// 判定は BillingAccess と同じ述語 (SubscriptionState::hasUnsettledPayment) 1 つだけを見る。

/** 支払い未解決 (猶予切れ past_due) の組織 + owner。 */
function unsettledPaymentOrganizationWithOwner(string $status = 'past_due'): array
{
    config()->set('billing.payment_grace_days', 14);
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $subscription = contractPaidPlan($organization, status: $status);
    $subscription->forceFill([
        'past_due_since' => CarbonImmutable::now()->subDays(15),
    ])->save();

    return [$organization->fresh(), $owner];
}

test('支払い未解決 + manageBilling は billing.index へ redirect (プラン選択を出さない)', function (string $status): void {
    [$organization, $owner] = unsettledPaymentOrganizationWithOwner($status);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('billing.index', ['organization' => $organization->slug]));
})->with(['past_due', 'unpaid']);

test('支払い未解決 + manageBilling なし member は従来どおり billing-required へ (判定順序が変わらない)', function (): void {
    [$organization] = unsettledPaymentOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('onboarding.billing-required', ['organization' => $organization->slug]));
});

test('逃がし先の billing.index は課金ゲートの外なので詰まない (200 で描画される)', function (): void {
    [$organization, $owner] = unsettledPaymentOrganizationWithOwner();

    $this->actingAs($owner)->get(route('billing.index', ['organization' => $organization->slug]))->assertOk();
});
