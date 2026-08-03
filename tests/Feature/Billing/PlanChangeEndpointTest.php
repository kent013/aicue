<?php

declare(strict_types=1);

use App\Enums\Billing\SubscriptionSwapOutcome;
use App\Exceptions\Billing\PlanChangeFailedException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Illuminate\Support\Str;

/*
 * F-3-01: POST /billing/plan (billing.plan.change)。
 *
 * 有効な subscription を**持つ**組織専用の経路で、持たない組織の billing.checkout と排他。
 * 認可は manageBilling。応答は redirect + flash (禁止事項 #4 / #7)。
 * 例外の変換境界: 業務拒否 / 外部障害 / lock 競合 は flash、**前提違反 (Assert) は 500**。
 */

/**
 * @return array{Organization, User}
 */
function planChangeEndpointOrganization(string $planCode = 'starter', string $status = 'active'): array
{
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    createFakeSubscription($organization, status: $status);
    $organization->forceFill(['plan_code' => $planCode])->save();
    $organization->refresh();

    return [$organization, $owner];
}

/**
 * @return array<string, string|null>
 */
function planChangePayload(string $planCode = 'standard', ?string $currentPlanCode = 'starter'): array
{
    return [
        'plan_code' => $planCode,
        'current_plan_code' => $currentPlanCode,
        'plan_change_token' => (string) Str::ulid(),
    ];
}

test('契約中 owner のプラン変更は /billing へ redirect し受付 flash を出す', function (): void {
    [, $owner] = planChangeEndpointOrganization();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);

    $this->actingAs($owner)->post('/billing/plan', planChangePayload())
        ->assertRedirect('/billing')
        ->assertSessionHas('success', 'プラン変更を受け付けました。反映まで数分かかる場合があります。');
});

test('AlreadyOnTargetPrice のときは受付済み文言になる', function (): void {
    [, $owner] = planChangeEndpointOrganization();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()
        ->andReturn(SubscriptionSwapOutcome::AlreadyOnTargetPrice);

    $this->actingAs($owner)->post('/billing/plan', planChangePayload())
        ->assertRedirect('/billing')
        ->assertSessionHas('success', 'このプランへの変更は受付済みです。反映まで数分かかる場合があります。');
});

test('manageBilling を持たない member は 403', function (): void {
    [$organization] = planChangeEndpointOrganization();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    $this->actingAs($member)->post('/billing/plan', planChangePayload())->assertForbidden();
});

test('契約の無い組織は 422 で新規契約導線へ倒す', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    $this->actingAs($owner)->post('/billing/plan', planChangePayload(currentPlanCode: null))
        ->assertSessionHasErrors(['plan_code']);
});

test('期間終了済み契約は valid() が false のため 422', function (): void {
    // Cashier の valid() は「ends_at が過去」= ended() のときだけ false になる
    // (canceled + ends_at=null は active 扱い。実コードの意味論をそのまま固定する)。
    [$organization, $owner] = planChangeEndpointOrganization(status: 'canceled');
    $organization->subscription('default')?->forceFill(['ends_at' => now()->subDay()])->save();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    $this->actingAs($owner)->post('/billing/plan', planChangePayload())
        ->assertSessionHasErrors(['plan_code']);
});

test('current_plan_code が実際と食い違うと stale として errors.plan_code を返す', function (): void {
    [, $owner] = planChangeEndpointOrganization();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    $this->actingAs($owner)->post('/billing/plan', planChangePayload(currentPlanCode: 'personal'))
        ->assertSessionHasErrors(['plan_code']);
});

test('plan_change_token の欠落・非 ULID は 422', function (): void {
    [, $owner] = planChangeEndpointOrganization();
    $this->mock(StripeGatewayInterface::class)->shouldNotReceive('swapSubscriptionPrices');

    $this->actingAs($owner)->post('/billing/plan', [
        'plan_code' => 'standard',
        'current_plan_code' => 'starter',
    ])->assertSessionHasErrors(['plan_change_token']);

    $this->actingAs($owner)->post('/billing/plan', [
        'plan_code' => 'standard',
        'current_plan_code' => 'starter',
        'plan_change_token' => 'not-a-ulid',
    ])->assertSessionHasErrors(['plan_change_token']);
});

test('未知の plan_code は 422', function (): void {
    [, $owner] = planChangeEndpointOrganization();
    $this->mock(StripeGatewayInterface::class)->shouldNotReceive('swapSubscriptionPrices');

    $this->actingAs($owner)->post('/billing/plan', planChangePayload(planCode: 'unknown-plan'))
        ->assertSessionHasErrors(['plan_code']);
});

test('current_plan_code はキー欠落なら 422 だが値 null は通る', function (): void {
    [$organization, $owner] = planChangeEndpointOrganization();

    $this->mock(StripeGatewayInterface::class)->shouldNotReceive('swapSubscriptionPrices');
    $this->actingAs($owner)->post('/billing/plan', [
        'plan_code' => 'standard',
        'plan_change_token' => (string) Str::ulid(),
    ])->assertSessionHasErrors(['current_plan_code']);

    // plan_code=null の組織 + current_plan_code=null は正当な組み合わせ (恒常 422 を作らない)
    $organization->forceFill(['plan_code' => null])->save();
    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);

    $this->actingAs($owner)->post('/billing/plan', [
        'plan_code' => 'standard',
        'current_plan_code' => null,
        'plan_change_token' => (string) Str::ulid(),
    ])->assertRedirect('/billing');
});

test('業務拒否 (paused 契約) は back + error flash でその文言を返す', function (): void {
    [, $owner] = planChangeEndpointOrganization(status: 'paused');

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    $response = $this->actingAs($owner)->post('/billing/plan', planChangePayload());

    $response->assertRedirect();
    expect(session('error'))->toBeString()->toContain('一時停止');
});

test('外部障害の flash は固定文言で内部 reason を漏らさない', function (): void {
    [, $owner] = planChangeEndpointOrganization();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()
        ->andThrow(PlanChangeFailedException::unexpectedShape('sub_secret_1', 2, null));

    $this->actingAs($owner)->post('/billing/plan', planChangePayload())->assertRedirect();

    expect(session('error'))->toBe(PlanChangeFailedException::USER_MESSAGE);
    expect(session('error'))->not->toContain('sub_secret_1');
});

test('前提違反 (InvalidArgumentException) は catch されず 500 になる', function (): void {
    [, $owner] = planChangeEndpointOrganization();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()
        ->andThrow(new InvalidArgumentException('内部 Assert 文言'));

    $response = $this->actingAs($owner)->post('/billing/plan', planChangePayload());

    $response->assertStatus(500);
    expect(session('error'))->toBeNull();
});

test('保護キーを payload に混ぜると 422', function (): void {
    [, $owner] = planChangeEndpointOrganization();
    $this->mock(StripeGatewayInterface::class)->shouldNotReceive('swapSubscriptionPrices');

    $this->actingAs($owner)->post('/billing/plan', array_merge(planChangePayload(), [
        'organization_id' => 999,
    ]))->assertSessionHasErrors(['organization_id']);
});

test('route parameter を持たないため current org の契約しか変更されない', function (): void {
    [$current, $owner] = planChangeEndpointOrganization();
    // owner が別組織にも所属していても、current org 以外は指定する手段が無い
    [$other] = planChangeEndpointOrganization();
    $other->users()->attach($owner);

    $seen = null;
    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')
        ->once()
        ->andReturnUsing(function (Organization $org) use (&$seen): SubscriptionSwapOutcome {
            $seen = $org->getKey();

            return SubscriptionSwapOutcome::Applied;
        });

    $this->actingAs($owner)->post('/billing/plan', planChangePayload())->assertRedirect('/billing');

    expect($seen)->toBe($current->getKey());
});
