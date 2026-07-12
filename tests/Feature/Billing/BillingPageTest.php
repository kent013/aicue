<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
use App\Services\Billing\SubscriptionCheckoutGateway;
use App\Services\Billing\TicketLedgerService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 課金ページ (/billing)。閲覧は組織メンバー全員、Checkout / Portal は
 * manageBilling (owner / admin) のみ。Stripe API は呼ばない (Checkout 開始は
 * 認可・validation 失敗経路のみ検証する)。
 */

test('owner は /billing でプラン一覧・残高・管理フラグを見られる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');

    $this->actingAs($owner)->get('/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->has('plans', 2)
            ->where('plans.0.code', 'free')
            ->where('plans.0.price', null)
            ->where('plans.1.code', 'standard')
            ->where('plans.1.monthlyTicketGrant', 100)
            ->has('plans.1.price', fn (Assert $price) => $price
                ->where('unitAmount', 4980)
                ->where('currency', 'jpy'))
            ->where('currentPlanCode', null)
            ->where('ticketBalance', 10)
            ->where('canManageBilling', true));
});

test('member も閲覧できるが管理フラグは false', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/billing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->where('canManageBilling', false));
});

test('member は checkout を開始できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)
        ->post('/billing/checkout', ['plan_code' => 'standard'])
        ->assertForbidden();
});

test('member は portal を開けない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->post('/billing/portal')->assertForbidden();
});

test('未知の plan_code の checkout は 422 (Stripe には到達しない)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post('/billing/checkout', ['plan_code' => 'no-such-plan'])
        ->assertSessionHasErrors('plan_code');
});

test('checkout payload で organization_id 等の保護キーは 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post('/billing/checkout', [
            'plan_code' => 'standard',
            'organization_id' => $organization->id,
        ])
        ->assertSessionHasErrors('organization_id');
});

test('current organization が無いユーザーは 404', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/billing')->assertNotFound();
});

test('owner の checkout は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);

    $response = $this->actingAs($owner)->post('/billing/checkout', ['plan_code' => 'standard']);

    // 非 Inertia リクエストでは Inertia::location は 302 redirect を返す
    $response->assertStatus(302);
    $location = $response->headers->get('Location');
    expect($location)->toContain('/billing')
        ->and($location)->toContain('fake_external=stripe');
});

test('owner の portal は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);

    $response = $this->actingAs($owner)->post('/billing/portal');

    $response->assertStatus(302);
    $location = $response->headers->get('Location');
    expect($location)->toContain('/billing')
        ->and($location)->toContain('fake_external=stripe');
});
