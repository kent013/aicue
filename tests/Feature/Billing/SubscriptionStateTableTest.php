<?php

declare(strict_types=1);

use App\Enums\Billing\SubscriptionState;
use App\Models\Organization;

/*
 * Stripe の status 文字列 → SubscriptionState → grantsAccess() / hasUnsettledPayment() の
 * 期待値表を 1 つ持ち、Stripe が subscription に取り得る status を全部回す。
 *
 * hasUnsettledPayment = 「契約が終了しておらず支払いが未解決」。これが true の間だけ
 * 無料枠への読み替え (BillingAccess) と新規契約 (SubscriptionService) を禁じる。
 * canceled は未払いの請求書が残りうるが false = 債権回収は課金事業者側の仕事として切り離す。
 */

/**
 * @return list<array{string, SubscriptionState, bool, bool}>
 */
function subscriptionStateTable(): array
{
    return [
        // [stripe_status, 期待 state, grantsAccess, hasUnsettledPayment]
        ['active', SubscriptionState::Active, true, false],
        ['trialing', SubscriptionState::Active, true, false],
        ['past_due', SubscriptionState::PastDue, true, true],
        ['paused', SubscriptionState::Paused, false, false],
        ['unpaid', SubscriptionState::Unpaid, false, true],
        ['canceled', SubscriptionState::Inactive, false, false],
        ['incomplete', SubscriptionState::Inactive, false, false],
        ['incomplete_expired', SubscriptionState::Inactive, false, false],
    ];
}

test('status → state → grantsAccess / hasUnsettledPayment の表', function (
    string $status,
    SubscriptionState $expectedState,
    bool $grantsAccess,
    bool $unsettled,
): void {
    $organization = Organization::factory()->create();
    $subscription = createFakeSubscription($organization, status: $status);

    $state = SubscriptionState::fromSubscription($subscription);

    expect($state)->toBe($expectedState)
        ->and($state->grantsAccess())->toBe($grantsAccess)
        ->and($state->hasUnsettledPayment())->toBe($unsettled);
})->with(subscriptionStateTable());

test('表は Stripe の subscription status を網羅している (取りこぼしを作らない)', function (): void {
    $covered = array_map(static fn (array $row): string => $row[0], subscriptionStateTable());

    expect($covered)->toBe([
        'active', 'trialing', 'past_due', 'paused',
        'unpaid', 'canceled', 'incomplete', 'incomplete_expired',
    ]);
});

test('hasUnsettledPayment は全 case で例外なく評価できる (網羅 match の空振り防止)', function (): void {
    foreach (SubscriptionState::cases() as $case) {
        expect($case->hasUnsettledPayment())->toBeBool();
    }

    // 支払い未解決なのは PastDue / Unpaid の 2 つだけ。
    $unsettled = array_values(array_filter(
        SubscriptionState::cases(),
        static fn (SubscriptionState $case): bool => $case->hasUnsettledPayment(),
    ));

    expect($unsettled)->toBe([SubscriptionState::PastDue, SubscriptionState::Unpaid]);
});
