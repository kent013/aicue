<?php

declare(strict_types=1);

use App\Dtos\Billing\ScheduleSnapshotDto;
use App\Enums\Billing\ScheduleSetupStatus;
use App\Models\Billing\Subscription;
use App\Services\Billing\StripeScheduleGateway;

/*
 * Subscription Schedule の reconcile (billing:reconcile-schedules、daily)。
 * 2 段 API call (create → update phases) の部分完了を 2 工程で復旧する:
 * - retryMissing: local schedule_id なし → remote に残る schedule を復元
 * - retryPartial: schedule_setup_status=Created → remote phases 確認で Configured 昇格 / 消失 reset
 * Stripe には到達しない (StripeScheduleGateway を mock 差替)。
 */

/**
 * @param  int  $phases  snapshot に含める phase 数
 */
function scheduleSnapshot(string $id, int $phases = 2): ScheduleSnapshotDto
{
    $phaseList = [];
    for ($i = 0; $i < $phases; $i++) {
        $phaseList[] = ['base_price_id' => "price_phase_{$i}", 'start_date' => 1750000000 + $i, 'end_date' => 1750000001 + $i];
    }

    return new ScheduleSnapshotDto(id: $id, phases: $phaseList, endBehavior: 'release');
}

/** 作成 1h 経過済みの active subscription (retryMissing の対象)。 */
function agedSubscription(): Subscription
{
    [$organization] = createOrganizationWithOwner();
    /** @var Subscription $subscription */
    $subscription = createFakeSubscription($organization);
    $subscription->forceFill(['created_at' => now()->subHours(2)])->save();

    return $subscription;
}

test('subscriptions() はテンプレート拡張 Subscription モデルを返す (useSubscriptionModel)', function (): void {
    [$organization] = createOrganizationWithOwner();
    createFakeSubscription($organization);

    expect($organization->subscriptions()->sole())->toBeInstanceOf(Subscription::class);
});

test('retryMissing: remote に schedule が残っていれば schedule_id を復元し Configured にする', function (): void {
    $subscription = agedSubscription();
    $this->mock(StripeScheduleGateway::class)
        ->shouldReceive('retrieve')->once()->with($subscription->stripe_id)
        ->andReturn(scheduleSnapshot('sub_sched_restored_1'));

    $this->artisan('billing:reconcile-schedules')
        ->expectsOutputToContain('reconcile-schedules: restored=1 promoted=0 reset=0')
        ->assertSuccessful();

    $subscription->refresh();
    expect($subscription->stripe_schedule_id)->toBe('sub_sched_restored_1')
        ->and($subscription->schedule_setup_status)->toBe(ScheduleSetupStatus::Configured);
});

test('retryMissing: remote にも schedule が無ければ何もしない', function (): void {
    $subscription = agedSubscription();
    $this->mock(StripeScheduleGateway::class)
        ->shouldReceive('retrieve')->once()->with($subscription->stripe_id)
        ->andReturnNull();

    $this->artisan('billing:reconcile-schedules')->assertSuccessful();

    $subscription->refresh();
    expect($subscription->stripe_schedule_id)->toBeNull()
        ->and($subscription->schedule_setup_status)->toBe(ScheduleSetupStatus::None);
});

test('retryMissing: 作成 1h 未満の subscription は in-flight とみなし照会しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    createFakeSubscription($organization);
    $this->mock(StripeScheduleGateway::class)
        ->shouldReceive('retrieve')->never();

    $this->artisan('billing:reconcile-schedules')->assertSuccessful();

    /** @var Subscription $subscription */
    $subscription = $organization->subscriptions()->sole();
    expect($subscription->stripe_schedule_id)->toBeNull();
});

test('retryPartial: Created で remote phases 設定済みなら Configured へ昇格する', function (): void {
    [$organization] = createOrganizationWithOwner();
    /** @var Subscription $subscription */
    $subscription = createFakeSubscription($organization);
    $subscription->markScheduleCreated('sub_sched_partial_1');

    $this->mock(StripeScheduleGateway::class)
        ->shouldReceive('retrieve')->once()->with($subscription->stripe_id)
        ->andReturn(scheduleSnapshot('sub_sched_partial_1'));

    $this->artisan('billing:reconcile-schedules')
        ->expectsOutputToContain('reconcile-schedules: restored=0 promoted=1 reset=0')
        ->assertSuccessful();

    expect($subscription->refresh()->schedule_setup_status)->toBe(ScheduleSetupStatus::Configured);
});

test('retryPartial: remote から schedule が消えていれば None へ reset する', function (): void {
    [$organization] = createOrganizationWithOwner();
    /** @var Subscription $subscription */
    $subscription = createFakeSubscription($organization);
    $subscription->markScheduleCreated('sub_sched_gone_1');

    $this->mock(StripeScheduleGateway::class)
        ->shouldReceive('retrieve')->once()->with($subscription->stripe_id)
        ->andReturnNull();

    $this->artisan('billing:reconcile-schedules')
        ->expectsOutputToContain('reconcile-schedules: restored=0 promoted=0 reset=1')
        ->assertSuccessful();

    $subscription->refresh();
    expect($subscription->stripe_schedule_id)->toBeNull()
        ->and($subscription->schedule_setup_status)->toBe(ScheduleSetupStatus::None);
});

test('1 件の Stripe 例外で全体を止めない (warning + continue)', function (): void {
    [$org1] = createOrganizationWithOwner();
    [$org2] = createOrganizationWithOwner();
    /** @var Subscription $failing */
    $failing = createFakeSubscription($org1);
    $failing->markScheduleCreated('sub_sched_err_1');
    /** @var Subscription $healthy */
    $healthy = createFakeSubscription($org2);
    $healthy->markScheduleCreated('sub_sched_ok_1');

    $mock = $this->mock(StripeScheduleGateway::class);
    $mock->shouldReceive('retrieve')->once()->with($failing->stripe_id)
        ->andThrow(new RuntimeException('stripe down'));
    $mock->shouldReceive('retrieve')->once()->with($healthy->stripe_id)
        ->andReturn(scheduleSnapshot('sub_sched_ok_1'));

    $this->artisan('billing:reconcile-schedules')
        ->expectsOutputToContain('reconcile-schedules: restored=0 promoted=1 reset=0')
        ->assertSuccessful();

    expect($failing->refresh()->schedule_setup_status)->toBe(ScheduleSetupStatus::Created)
        ->and($healthy->refresh()->schedule_setup_status)->toBe(ScheduleSetupStatus::Configured);
});
