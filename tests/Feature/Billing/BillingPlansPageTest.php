<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeStripeGateway;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * P8b (bs-6): プラン提示の専用ページ (/billing/plans)。
 *
 * Billing/Index からプラン一覧を移設した先。表示用の currentPlanCode 解決規則は
 * ActiveFreePlan なら free_plan_code、それ以外は plan_code (gate 判定には使わない)。
 */

test('owner は /billing/plans で公開プラン一覧と表示状態を受け取る', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/billing/plans')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Plans')
            // sort_order 昇順 (personal 1 / starter 2 / standard 3。free 行は D11 で撤去済み)
            ->has('page.plans', 3)
            ->where('page.plans.0.code', 'personal')
            ->where('page.plans.0.baseAmountJpy', null)
            ->where('page.plans.1.code', 'starter')
            ->where('page.plans.2.code', 'standard')
            ->where('page.plans.2.maxStorageGb', 50)
            ->where('page.currentPlanCode', 'personal')
            ->where('page.billingState', 'active_free_plan')
            ->where('page.canManage', true));
});

test('ActiveFreePlan の org では plan_code に旧 paid が残っていても free 側が currentPlanCode', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['plan_code' => 'standard'])->save();
    createFakeSubscription($organization, status: 'canceled');

    $this->actingAs($owner)->get('/billing/plans')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.billingState', 'active_free_plan')
            ->where('page.currentPlanCode', 'personal'));
});

test('有償契約中の org では plan_code が currentPlanCode', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization);

    $this->actingAs($owner)->get('/billing/plans')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.billingState', 'subscribed')
            ->where('page.currentPlanCode', 'standard'));
});

test('member も閲覧できるが canManage=false', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/billing/plans')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Plans')
            ->where('page.canManage', false));
});

test('current organization が無いユーザーは 404', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/billing/plans')->assertNotFound();
});

test('POST /billing/checkout は plan_code のみで成立する (attempt token を要求しない)', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);

    $response = $this->actingAs($owner)->post('/billing/checkout', ['plan_code' => 'standard']);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('fake_external=stripe');
});
