<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 課金ページ (/billing)。閲覧は組織メンバー全員、Checkout / Portal は
 * manageBilling (owner / admin) のみ。Stripe API は呼ばない (Checkout 開始は
 * 認可・validation 失敗経路のみ検証する)。
 */

test('owner は /billing で現在プラン・per-bucket 残高・quota・管理フラグを見られる', function (): void {
    // P8b: プラン一覧は /billing/plans へ移設 (期待は BillingPlansPageTest が持つ)。
    [$organization, $owner] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->missing('plans') // インラインのプラン一覧は撤去済み
            ->where('page.plan.code', 'personal') // ActiveFreePlan = free_plan_code が正
            ->where('page.billingState', 'active_free_plan')
            ->where('page.currentPeriodEnd', null)
            ->where('page.balance.totalAvailable', 10)
            ->where('page.balance.monthlyRemaining', 0)
            ->where('page.balance.purchasedRemaining', 10)
            ->where('page.quotas.maxProjects', 1)
            ->where('page.quotas.maxMembers', 3)
            ->where('page.quotas.maxStorageGb', 1)
            ->where('page.canManageBilling', true));
});

test('未契約 org の /billing では現在プランが null で届く', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.plan', null)
            ->where('page.billingState', 'no_subscription'));
});

test('member も閲覧できるが管理フラグは false', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->where('page.canManageBilling', false));
});

test('member は checkout を開始できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)
        ->post("/organizations/{$organization->slug}/billing/checkout", [
            'plan_code' => 'standard',
            'subscription_attempt_token' => (string) Str::ulid(),
        ])
        ->assertForbidden();
});

test('member は portal を開けない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)->post("/organizations/{$organization->slug}/billing/portal")->assertForbidden();
});

test('未知の plan_code の checkout は 422 (Stripe には到達しない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/checkout", [
            'plan_code' => 'no-such-plan',
            'subscription_attempt_token' => (string) Str::ulid(),
        ])
        ->assertSessionHasErrors('plan_code');
});

test('checkout payload で organization_id 等の保護キーは 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post("/organizations/{$organization->slug}/billing/checkout", [
            'plan_code' => 'standard',
            'subscription_attempt_token' => (string) Str::ulid(),
            'organization_id' => $organization->id,
        ])
        ->assertSessionHasErrors('organization_id');
});

test('非所属の組織 URL は 404 (組織の有無を露出しない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/organizations/{$organization->slug}/billing")->assertNotFound();
});

test('owner の checkout は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    enableFakeExternals();

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/checkout", [
        'plan_code' => 'standard',
        'subscription_attempt_token' => (string) Str::ulid(),
    ]);

    // 非 Inertia リクエストでは Inertia::location は 302 redirect を返す。
    // P9: cancel URL は /billing/plans (fake gateway は cancel URL ベースの中立帰還)。
    $response->assertStatus(302);
    $location = $response->headers->get('Location');
    expect($location)->toContain("/organizations/{$organization->slug}/billing/plans")
        ->and($location)->toContain('fake_external=stripe');
});

test('owner の portal は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
    // P8b (bs-11): portal は有償サブスク前提の事前ガードを通る必要がある
    // (未契約 / ActiveFreePlan の遮断は BillingPortalGuardTest が固定)。
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization);
    enableFakeExternals();

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/portal");

    $response->assertStatus(302);
    $location = $response->headers->get('Location');
    expect($location)->toContain("/organizations/{$organization->slug}/billing")
        ->and($location)->toContain('fake_external=stripe');
});
