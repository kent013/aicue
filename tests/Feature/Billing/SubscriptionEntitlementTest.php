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
 *              AND NOT (state = PastDue AND past_due_since != null AND 猶予期限切れ)
 */

function entitlementSubscription(
    string $status = 'active',
    bool $hasPaymentMethod = true,
    ?CarbonImmutable $trialEndsAt = null,
    ?string $scheduleId = null,
    ScheduleSetupStatus $scheduleSetupStatus = ScheduleSetupStatus::None,
    ?CarbonImmutable $pastDueSince = null,
): Subscription {
    $organization = Organization::factory()->create();
    $subscription = createFakeSubscription($organization, status: $status);
    $subscription->forceFill([
        'has_payment_method' => $hasPaymentMethod,
        'trial_ends_at' => $trialEndsAt,
        'stripe_schedule_id' => $scheduleId,
        'schedule_setup_status' => $scheduleSetupStatus,
        'past_due_since' => $pastDueSince,
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
})->with(['canceled', 'incomplete', 'incomplete_expired']);

test('unpaid は Unpaid state / NoActiveSubscription で否定 (Inactive から分離しても可否は同じ)', function (): void {
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(status: 'unpaid'));

    expect($entitlement->entitled)->toBeFalse()
        ->and($entitlement->state)->toBe(SubscriptionState::Unpaid)
        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::NoActiveSubscription);
});

// ── 支払い失敗の猶予 (AG-035 (5)) ──

test('past_due + 猶予中 (起点 13 日前) は entitled', function (): void {
    config()->set('billing.payment_grace_days', 14);
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'past_due',
        trialEndsAt: CarbonImmutable::now()->subDay(),
        pastDueSince: CarbonImmutable::now()->subDays(13),
    ));

    expect($entitlement->entitled)->toBeTrue()
        ->and($entitlement->state)->toBe(SubscriptionState::PastDue)
        ->and($entitlement->reason)->toBeNull();
});

test('past_due + 起点ちょうど 14 日前は entitled (境界は継続)', function (): void {
    config()->set('billing.payment_grace_days', 14);
    // 境界そのものを見るので時計を止める (経過マイクロ秒で結論が揺れないようにする)。
    $this->travelTo(CarbonImmutable::parse('2026-08-15 09:00:00'));
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'past_due',
        trialEndsAt: CarbonImmutable::now()->subDay(),
        pastDueSince: CarbonImmutable::now()->subDays(14),
    ));

    expect($entitlement->entitled)->toBeTrue()
        ->and($entitlement->reason)->toBeNull();
});

test('past_due + 起点 15 日前は PaymentGraceExpired で否定', function (): void {
    config()->set('billing.payment_grace_days', 14);
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'past_due',
        trialEndsAt: CarbonImmutable::now()->subDay(),
        pastDueSince: CarbonImmutable::now()->subDays(15),
    ));

    expect($entitlement->entitled)->toBeFalse()
        ->and($entitlement->state)->toBe(SubscriptionState::PastDue)
        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::PaymentGraceExpired);
});

test('past_due + 起点 NULL は遮断しない (打刻漏れを締め出しに変えない)', function (): void {
    config()->set('billing.payment_grace_days', 14);
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'past_due',
        trialEndsAt: CarbonImmutable::now()->subDays(90),
        pastDueSince: null,
    ));

    expect($entitlement->entitled)->toBeTrue()
        ->and($entitlement->reason)->toBeNull();
});

test('猶予切れでも trial 終了 + PM 無しの理由が優先される (既存の優先順位が変わらない)', function (): void {
    config()->set('billing.payment_grace_days', 14);
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'past_due',
        hasPaymentMethod: false,
        trialEndsAt: CarbonImmutable::now()->subDay(),
        pastDueSince: CarbonImmutable::now()->subDays(15),
    ));

    expect($entitlement->entitled)->toBeFalse()
        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::TrialEndedWithoutPaymentMethod);
});

test('猶予は PastDue 限定 (active に古い起点が残っていても遮断しない)', function (): void {
    config()->set('billing.payment_grace_days', 14);
    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
        status: 'active',
        pastDueSince: CarbonImmutable::now()->subDays(15),
    ));

    expect($entitlement->entitled)->toBeTrue()
        ->and($entitlement->state)->toBe(SubscriptionState::Active)
        ->and($entitlement->reason)->toBeNull();
});

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
