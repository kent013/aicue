<?php

declare(strict_types=1);

use App\Enums\Billing\OnboardingBillingState;

test('5 case の value が固定されている', function (): void {
    expect(array_map(
        fn (OnboardingBillingState $state): string => $state->value,
        OnboardingBillingState::cases(),
    ))->toBe([
        'no_subscription',
        'pending_checkout',
        'expired_checkout',
        'subscribed',
        'active_free_plan',
    ]);
});

test('grantsAccess は Subscribed / ActiveFreePlan のみ true', function (OnboardingBillingState $state, bool $expected): void {
    expect($state->grantsAccess())->toBe($expected);
})->with([
    [OnboardingBillingState::NoSubscription, false],
    [OnboardingBillingState::PendingCheckout, false],
    [OnboardingBillingState::ExpiredCheckout, false],
    [OnboardingBillingState::Subscribed, true],
    [OnboardingBillingState::ActiveFreePlan, true],
]);
