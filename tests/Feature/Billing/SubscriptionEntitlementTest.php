<?php

declare(strict_types=1);

use App\Enums\Billing\EntitlementDeniedReason;
use App\Enums\Billing\ScheduleSetupStatus;
use App\Enums\Billing\SubscriptionState;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\SubscriptionService;
use Carbon\CarbonImmutable;

/*
 * SubscriptionService::deriveEntitlement (aigenba verbatim) の
 * entitled / state / reason マトリクスを固定する。
 *
 *   entitled = state.grantsAccess()
 *              AND NOT (trial_ends_at <= now AND !has_payment_method)
 *              AND status != paused
 */

function entitlementSubscription(
    string $status = 'active',
    bool $hasPaymentMethod = true,
    ?CarbonImmutable $trialEndsAt = null,
    ?string $scheduleId = null,
    ScheduleSetupStatus $scheduleSetupStatus = ScheduleSetupStatus::None,
): Subscription {
    $organization = Organization::factory()->create();
    $subscription = createFakeSubscription($organization, status: $status);
    $subscription->forceFill([
        'has_payment_method' => $hasPaymentMethod,
        'trial_ends_at' => $trialEndsAt,
        'stripe_schedule_id' => $scheduleId,
        'schedule_setup_status' => $scheduleSetupStatus,
    ])->save();

    return $subscription;
}

function entitlementService(): SubscriptionService
{
    return app(SubscriptionService::class);
}

test('active / trialing は Active state で entitled', function (string $status): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(status: $status));

    expect($entitlement->entitled)->toBeTrue()
        ->and($entitlement->state)->toBe(SubscriptionState::Active)
        ->and($entitlement->reason)->toBeNull();
})->with(['active', 'trialing']);

test('schedule 部分完了 (schedule_id + Created) は UpgradeRecovery で entitled', function (): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'active',
        scheduleId: 'sub_sched_test',
        scheduleSetupStatus: ScheduleSetupStatus::Created,
    ));

    expect($entitlement->entitled)->toBeTrue()
        ->and($entitlement->state)->toBe(SubscriptionState::UpgradeRecovery)
        ->and($entitlement->reason)->toBeNull();
});

test('schedule 設定完了 (Configured) は Active のまま (ScheduledForUpgrade は非移植)', function (): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'active',
        scheduleId: 'sub_sched_test',
        scheduleSetupStatus: ScheduleSetupStatus::Configured,
    ));

    expect($entitlement->state)->toBe(SubscriptionState::Active)
        ->and($entitlement->entitled)->toBeTrue();
});

test('paused は state=Paused / reason=Paused で否定 (schedule 状態に依らない)', function (): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'paused',
        scheduleId: 'sub_sched_test',
        scheduleSetupStatus: ScheduleSetupStatus::Created,
    ));

    expect($entitlement->entitled)->toBeFalse()
        ->and($entitlement->state)->toBe(SubscriptionState::Paused)
        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::Paused);
});

test('past_due + PM 有りは state=PastDue で entitled (請求失敗中も利用継続)', function (): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'past_due',
        hasPaymentMethod: true,
        trialEndsAt: CarbonImmutable::now()->subDay(),
    ));

    expect($entitlement->entitled)->toBeTrue()
        ->and($entitlement->state)->toBe(SubscriptionState::PastDue)
        ->and($entitlement->reason)->toBeNull();
});

test('past_due + trial 終了 + PM 無しは TrialEndedWithoutPaymentMethod で否定', function (): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'past_due',
        hasPaymentMethod: false,
        trialEndsAt: CarbonImmutable::now()->subDay(),
    ));

    expect($entitlement->entitled)->toBeFalse()
        ->and($entitlement->state)->toBe(SubscriptionState::PastDue)
        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::TrialEndedWithoutPaymentMethod);
});

test('trial 終了 + PM 無しは webhook の paused 化前でも先回りで否定', function (string $status): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: $status,
        hasPaymentMethod: false,
        trialEndsAt: CarbonImmutable::now()->subSecond(),
    ));

    expect($entitlement->entitled)->toBeFalse()
        ->and($entitlement->state)->toBe(SubscriptionState::Active)
        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::TrialEndedWithoutPaymentMethod);
})->with(['active', 'trialing']);

test('trial 終了 + PM 有りは entitled', function (): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        hasPaymentMethod: true,
        trialEndsAt: CarbonImmutable::now()->subDay(),
    ));

    expect($entitlement->entitled)->toBeTrue()
        ->and($entitlement->reason)->toBeNull();
});

test('trial 未終了は PM 無しでも entitled', function (): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'trialing',
        hasPaymentMethod: false,
        trialEndsAt: CarbonImmutable::now()->addDay(),
    ));

    expect($entitlement->entitled)->toBeTrue()
        ->and($entitlement->reason)->toBeNull();
});

test('非 active 系 status は Inactive / NoActiveSubscription で否定', function (string $status): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(status: $status));

    expect($entitlement->entitled)->toBeFalse()
        ->and($entitlement->state)->toBe(SubscriptionState::Inactive)
        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::NoActiveSubscription);
})->with(['canceled', 'unpaid', 'incomplete', 'incomplete_expired']);

test('DTO の toArray は entitled / state / reason を value で返す', function (): void {
    $granted = entitlementService()->deriveEntitlement(entitlementSubscription());
    $denied = entitlementService()->deriveEntitlement(entitlementSubscription(status: 'paused'));

    expect($granted->toArray())->toBe([
        'entitled' => true,
        'state' => 'active',
        'reason' => null,
    ])->and($denied->toArray())->toBe([
        'entitled' => false,
        'state' => 'paused',
        'reason' => 'paused',
    ]);
});
