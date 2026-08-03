<?php

declare(strict_types=1);

use App\Enums\Billing\OnboardingBillingState;
use App\Enums\CheckoutIntent;
use App\Enums\CheckoutSessionStatus;
use App\Enums\OrganizationRole;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\TicketAutoRecharge;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Illuminate\Support\Str;
use Tests\Support\FakeAutoRechargeGateway;

/*
 * P8a: オートリチャージ endpoint の認可 / validation / 着地。
 */

beforeEach(function (): void {
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
});

test('manageBilling を持たない member は設定更新できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)
        ->post('/billing/auto-recharge', [
            'enabled' => false,
            'threshold_count' => 5,
            'max_count' => 50,
        ])
        ->assertForbidden();

    expect(TicketAutoRecharge::query()->count())->toBe(0);
});

test('manageBilling を持たない member はカード登録を開始できない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)
        ->post('/billing/auto-recharge/setup', ['attempt_token' => strtolower((string) Str::ulid())])
        ->assertForbidden();

    expect(BillingCheckoutSession::query()->count())->toBe(0);
});

test('他組織の設定は触れない — current org スコープで解決されるため cross-org 書き込みが起きない', function (): void {
    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');

    $this->actingAs($ownerA)
        ->post('/billing/auto-recharge', [
            'enabled' => false,
            'threshold_count' => 3,
            'max_count' => 30,
        ])
        ->assertRedirect();

    expect(TicketAutoRecharge::query()->where('organization_id', $organizationA->id)->exists())->toBeTrue()
        ->and(TicketAutoRecharge::query()->where('organization_id', $organizationB->id)->exists())->toBeFalse();
});

test('enabled=true で consent_version 欠落は 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post('/billing/auto-recharge', [
            'enabled' => true,
            'threshold_count' => 5,
            'max_count' => 50,
        ])
        ->assertSessionHasErrors('consent_version');
});

test('max_count <= threshold_count は 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post('/billing/auto-recharge', [
            'enabled' => false,
            'threshold_count' => 50,
            'max_count' => 50,
        ])
        ->assertSessionHasErrors('max_count');
});

test('max_count が config 上限を超えると 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post('/billing/auto-recharge', [
            'enabled' => false,
            'threshold_count' => 5,
            'max_count' => config()->integer('billing.auto_recharge.max_count') + 1,
        ])
        ->assertSessionHasErrors('max_count');
});

test('保護キー (organization_id) を payload に載せると 422 (mass assignment 入口防御)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->post('/billing/auto-recharge', [
            'enabled' => false,
            'threshold_count' => 5,
            'max_count' => 50,
            'organization_id' => 999,
        ])
        ->assertSessionHasErrors('organization_id');
});

test('カード登録開始で SetupPaymentMethod 台帳行が 1 行だけ作られる (二重 submit で増殖しない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = strtolower((string) Str::ulid());

    foreach ([1, 2] as $ignored) {
        $this->actingAs($owner)
            ->post('/billing/auto-recharge/setup', ['attempt_token' => $token]);
    }

    $sessions = BillingCheckoutSession::query()
        ->where('organization_id', $organization->id)
        ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
        ->get();

    expect($sessions)->toHaveCount(1)
        ->and($sessions->firstOrFail()->attempt_token)->toBe($token)
        ->and($sessions->firstOrFail()->idempotency_key)->toBe('auto-recharge-setup:'.$token);
});

test('カード登録着地は 303 + flash で canonical URL に倒れる (GET で副作用を起こさない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $session = BillingCheckoutSession::factory()
        ->for($organization)
        ->setupPaymentMethod()
        ->completed()
        ->create(['stripe_session_id' => 'cs_setup_landing']);

    $this->actingAs($owner)
        ->get('/billing?setup_session_id='.$session->stripe_session_id)
        ->assertStatus(303)
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('success');
});

test('他組織の setup session id を投げ込んでも成功文言は出ない (IDOR 防御)', function (): void {
    [$organizationA, $ownerA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');

    BillingCheckoutSession::factory()
        ->for($organizationB)
        ->setupPaymentMethod()
        ->completed()
        ->create(['stripe_session_id' => 'cs_setup_other_org']);

    $this->actingAs($ownerA)
        ->get('/billing?setup_session_id=cs_setup_other_org')
        ->assertStatus(303)
        ->assertSessionMissing('success');
});

test('課金ページ props に autoRecharge が常に含まれる (既定 off)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->get('/billing')
        ->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['page'])->toHaveKey('autoRecharge')
        ->and($props['page']['autoRecharge']['enabled'])->toBeFalse()
        ->and($props['page']['autoRecharge']['canManage'])->toBeTrue()
        ->and($props['page'])->toHaveKey('autoRechargeSetupToken');
});

test('member でも autoRecharge props は届くが canManage=false (閲覧は全員)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $response = $this->actingAs($member)
        ->get('/billing')
        ->assertOk();

    expect($response->viewData('page')['props']['page']['autoRecharge']['canManage'])->toBeFalse();
});

test('setup 台帳行があっても BillingAccess::state() は PendingCheckout にならない (P2 契約の回帰)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(); // free plan 申告済み = ActiveFreePlan

    BillingCheckoutSession::factory()
        ->for($organization)
        ->setupPaymentMethod()
        ->create([
            'stripe_session_id' => 'cs_setup_state',
            'status' => CheckoutSessionStatus::Pending->value,
        ]);

    $state = app(BillingAccess::class)->state($organization->fresh());

    expect($state)->toBe(OnboardingBillingState::ActiveFreePlan)
        ->and($state->grantsAccess())->toBeTrue();

    // ついでに課金ページが到達可能なままであること
    $this->actingAs($owner)
        ->get('/billing')
        ->assertOk();
});
