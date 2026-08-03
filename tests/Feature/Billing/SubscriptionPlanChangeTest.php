<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Enums\Billing\ScheduleSetupStatus;
use App\Enums\Billing\SubscriptionSwapOutcome;
use App\Exceptions\Billing\PlanChangeFailedException;
use App\Exceptions\Billing\PlanChangeNotAllowedException;
use App\Exceptions\Billing\StalePlanChangeException;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\SubscriptionSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/*
 * F-3-01 層 2/3: SubscriptionService::changePlan()。gateway は mock 差し替え。
 *
 * 段 1 契約再読込 → 段 2 state 判定 → 段 3 schedule 拒否 → 段 4 stale 検知 → 段 5 swap。
 *
 * - `organizations.plan_code` は **書かない** (webhook = applySubscriptionSnapshot が唯一の writer)。
 * - 同一プラン判定は **remote 照合に一本化** (local projection で早期 return しない)。
 * - stale 検知は「要求先 ≠ local 現在プラン」のときだけ評価する (反映待ち中の再操作を誤拒否しない)。
 */

function planChangePlan(string $code): Plan
{
    return Plan::query()->where('code', $code)->firstOrFail();
}

function planChangeBasePriceId(string $code): string
{
    $price = planChangePlan($code)->currentPrice(PlanPriceKind::Base);
    Assert::notNull($price, "{$code} プランの current base price が未 seed");

    return $price->stripe_price_id;
}

/**
 * starter 契約中の組織を作る。
 *
 * @return array{Organization, Subscription}
 */
function planChangeOrganization(string $planCode = 'starter', string $status = 'active'): array
{
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $subscription = createFakeSubscription($organization, status: $status);
    $organization->forceFill(['plan_code' => $planCode])->save();
    $organization->refresh();

    return [$organization, $subscription];
}

function planChangeService(): SubscriptionService
{
    return app(SubscriptionService::class);
}

test('starter 契約中の組織は standard へ swap でき、plan_code は webhook 前なので変わらない', function (): void {
    [$organization] = planChangeOrganization();
    $token = (string) Str::ulid();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')
        ->once()
        ->withArgs(function (Organization $org, string $priceId, string $key): bool {
            return $org->getKey() !== null
                && $priceId === planChangeBasePriceId('standard')
                && str_starts_with($key, 'change-plan:')
                && str_ends_with($key, ':standard');
        })
        ->andReturn(SubscriptionSwapOutcome::Applied);

    $outcome = planChangeService()->changePlan($organization, planChangePlan('standard'), $token, 'starter');

    expect($outcome)->toBe(SubscriptionSwapOutcome::Applied);
    // 単一 writer 契約: swap 経路は organizations.plan_code を書かない
    expect($organization->fresh()?->plan_code)->toBe('starter');
});

test('idempotency key は change-plan:{token}:{planCode} の形をとる', function (): void {
    [$organization] = planChangeOrganization();
    $token = (string) Str::ulid();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')
        ->once()
        ->with(Mockery::type(Organization::class), planChangeBasePriceId('standard'), "change-plan:{$token}:standard")
        ->andReturn(SubscriptionSwapOutcome::Applied);

    planChangeService()->changePlan($organization, planChangePlan('standard'), $token, 'starter');
});

test('swap 後に customer.subscription.updated が届くと plan_code が追随する (projection_synced)', function (): void {
    [$organization, $subscription] = planChangeOrganization();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);

    $service = planChangeService();
    $service->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter');
    expect($organization->fresh()?->plan_code)->toBe('starter');

    $service->applySubscriptionSnapshot($organization, new SubscriptionSnapshot(
        stripeId: $subscription->stripe_id,
        status: 'active',
        basePriceId: planChangeBasePriceId('standard'),
        baseQuantity: 1,
        currentPeriodEnd: null,
        trialEndsAt: null,
        endsAt: null,
    ));

    expect($organization->fresh()?->plan_code)->toBe('standard');
});

test('要求先が local 現在プランと違い期待値も不一致なら stale で拒否する', function (): void {
    [$organization] = planChangeOrganization();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    expect(fn () => planChangeService()->changePlan(
        $organization, planChangePlan('standard'), (string) Str::ulid(), 'personal',
    ))->toThrow(StalePlanChangeException::class);
});

test('plan_code が null の組織に期待値 null を渡しても stale にならない', function (): void {
    [$organization] = planChangeOrganization();
    $organization->forceFill(['plan_code' => null])->save();
    $organization->refresh();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);

    expect(planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), null))
        ->toBe(SubscriptionSwapOutcome::Applied);
});

test('local が既に対象プランでも gateway を呼び、remote が同一なら AlreadyOnTargetPrice', function (): void {
    [$organization] = planChangeOrganization(planCode: 'standard');

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()
        ->andReturn(SubscriptionSwapOutcome::AlreadyOnTargetPrice);

    expect(planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'standard'))
        ->toBe(SubscriptionSwapOutcome::AlreadyOnTargetPrice);
});

test('local が既に対象プランでも remote が別 Price なら Applied (受付済みと嘘をつかない)', function (): void {
    [$organization] = planChangeOrganization(planCode: 'standard');

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::Applied);

    expect(planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'standard'))
        ->toBe(SubscriptionSwapOutcome::Applied);
});

test('要求先 = local 現在プランなら期待値が古くても stale にしない', function (): void {
    [$organization] = planChangeOrganization(planCode: 'standard');

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')->once()->andReturn(SubscriptionSwapOutcome::AlreadyOnTargetPrice);

    expect(planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter'))
        ->toBe(SubscriptionSwapOutcome::AlreadyOnTargetPrice);
});

test('grace period (解約予約中) の契約は同一プラン要求でも state で拒否する', function (): void {
    [$organization, $subscription] = planChangeOrganization(planCode: 'standard', status: 'canceled');
    $subscription->forceFill(['ends_at' => CarbonImmutable::now()->addDays(10)])->save();
    $organization->refresh();
    // Cashier の valid() は grace period を true にする (= 変更経路には入る)
    expect($organization->subscription('default')?->valid())->toBeTrue();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    expect(fn () => planChangeService()->changePlan(
        $organization, planChangePlan('standard'), (string) Str::ulid(), 'standard',
    ))->toThrow(PlanChangeNotAllowedException::class);
});

test('変更できない state は段ごとに異なる理由で拒否する', function (): void {
    // Cashier の valid() 意味論 (実コードが正):
    //  - past_due は Cashier::$deactivatePastDue 既定 true により active()=false = 段 1 で拒否
    //  - paused / canceled(ends_at=null) は valid()=true のまま段 2 の state 判定へ進む
    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    $messages = [];
    foreach (['past_due', 'paused', 'canceled'] as $status) {
        [$org] = planChangeOrganization(status: $status);
        try {
            planChangeService()->changePlan($org, planChangePlan('standard'), (string) Str::ulid(), 'starter');
            $this->fail("PlanChangeNotAllowedException が投げられていない ({$status})");
        } catch (PlanChangeNotAllowedException $e) {
            $messages[] = $e->getMessage();
        }
    }

    expect($messages[0])->toContain('変更できる契約がありません');
    expect($messages[1])->toContain('一時停止');
    expect($messages[2])->toContain('ご契約が有効でないため');
    expect(array_unique($messages))->toHaveCount(3);
});

test('schedule 管理下の契約は swap せず拒否する', function (): void {
    [$organization, $subscription] = planChangeOrganization();
    $subscription->forceFill([
        'stripe_schedule_id' => 'sub_sched_1',
        'schedule_setup_status' => ScheduleSetupStatus::Configured,
    ])->save();
    $organization->refresh();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    expect(fn () => planChangeService()->changePlan(
        $organization, planChangePlan('standard'), (string) Str::ulid(), 'starter',
    ))->toThrow(PlanChangeNotAllowedException::class, '予約済みのプラン変更があります。反映後に再度お試しください。');
});

test('契約が無い組織は業務拒否 (前提違反の InvalidArgumentException にしない)', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    expect(fn () => planChangeService()->changePlan(
        $organization, planChangePlan('standard'), (string) Str::ulid(), null,
    ))->toThrow(PlanChangeNotAllowedException::class);
});

test('決済対象外プラン (personal) は 422 で倒れ、base Price 未設定の Assert には落ちない', function (): void {
    [$organization] = planChangeOrganization();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldNotReceive('swapSubscriptionPrices');

    // 段 0 の順序 (assertStripeBillablePlan が先) の回帰防止:
    // personal は base Price を持たないため、順序を逆にすると InvalidArgumentException になる。
    expect(fn () => planChangeService()->changePlan(
        $organization, planChangePlan('personal'), (string) Str::ulid(), 'starter',
    ))->toThrow(ValidationException::class);
});

test('ABA 往復では 3 回とも異なる idempotency key になる', function (): void {
    [$organization] = planChangeOrganization();

    $keys = [];
    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')
        ->times(3)
        ->andReturnUsing(function (Organization $org, string $priceId, string $key) use (&$keys): SubscriptionSwapOutcome {
            $keys[] = $key;

            return SubscriptionSwapOutcome::Applied;
        });

    $service = planChangeService();
    $service->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter');
    $service->changePlan($organization, planChangePlan('starter'), (string) Str::ulid(), 'starter');
    $service->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter');

    expect($keys)->toHaveCount(3);
    expect(array_unique($keys))->toHaveCount(3);
});

test('gateway の PlanChangeFailedException はそのまま伝播し reason が log に出る', function (): void {
    [$organization] = planChangeOrganization();

    $gateway = $this->mock(StripeGatewayInterface::class);
    $gateway->shouldReceive('swapSubscriptionPrices')
        ->once()
        ->andThrow(PlanChangeFailedException::unexpectedShape('sub_x', 2, null));

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'changePlan: swap failed'
                && is_string($context['reason'])
                && str_starts_with($context['reason'], 'unexpected_shape:');
        });

    try {
        planChangeService()->changePlan($organization, planChangePlan('standard'), (string) Str::ulid(), 'starter');
        $this->fail('PlanChangeFailedException が投げられていない');
    } catch (PlanChangeFailedException $e) {
        expect($e->getMessage())->toBe(PlanChangeFailedException::USER_MESSAGE);
    }
});
