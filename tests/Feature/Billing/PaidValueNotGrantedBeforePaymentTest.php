<?php

declare(strict_types=1);

use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\SubscriptionService;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Events\WebhookReceived;

/*
 * 「有料の価値は支払いが確定する前に渡さない」という前提の固定。
 *
 * 支払い未解決 (past_due / unpaid) の間だけ無料枠への読み替えと新規契約を禁じる設計は、
 * **契約が成立しただけでは価値が出ない**ことに依っている。ここが崩れると
 * 「incomplete のまま価値だけ取る」経路が生まれるため、次の 3 つを固定する:
 *   - incomplete (カード認証待ち) の契約は entitled にならない
 *   - 契約作成 (customer.subscription.created) で付くのは組織生涯 1 回の無償 signup grant だけで、
 *     月次付与は起きない (月次付与の契機は invoice.paid のみ)
 *   - 既に無料申告で signup grant 済みの組織では、契約作成でチケットが増えない
 */

/** Stripe customer を持つ未契約組織。 */
function paidValueOrganization(string $stripeId): Organization
{
    [$organization] = createOrganizationWithOwner('テスト組織', grandfatherFreePlan: false);
    $organization->stripe_id = $stripeId;
    $organization->save();

    return $organization;
}

/**
 * @return array<string, mixed>
 */
function paidValueSubscriptionCreatedPayload(string $eventId, string $stripeId, string $stripeSubId): array
{
    return [
        'id' => $eventId,
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => $stripeSubId,
                'customer' => $stripeId,
                'status' => 'incomplete',
            ],
        ],
    ];
}

test('incomplete の契約は entitled にならない (カード認証待ちで価値を渡さない)', function (): void {
    $organization = paidValueOrganization('cus_paid_value_1');
    $subscription = createFakeSubscription($organization, status: 'incomplete');

    expect(app(SubscriptionService::class)->deriveEntitlement($subscription)->entitled)->toBeFalse();
});

test('契約作成で付くのは signup grant 1 件だけ (月次付与は起きない)', function (): void {
    $organization = paidValueOrganization('cus_paid_value_2');

    event(new WebhookReceived(paidValueSubscriptionCreatedPayload(
        'evt_paid_value_2', 'cus_paid_value_2', 'sub_paid_value_2',
    )));

    $entries = TicketLedgerEntry::query()->where('organization_id', $organization->getKey())->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->firstOrFail()->idempotency_key)->toBe('signup_grant:sub_paid_value_2');
});

test('無料申告で signup grant 済みの組織は契約作成でチケットが増えない', function (): void {
    $organization = paidValueOrganization('cus_paid_value_3');
    $owner = $organization->users()->firstOrFail();
    app(PersonalPlanService::class)->activate($organization, $owner);

    $before = TicketLedgerEntry::query()->where('organization_id', $organization->getKey())->count();
    expect($before)->toBe(1);

    event(new WebhookReceived(paidValueSubscriptionCreatedPayload(
        'evt_paid_value_3', 'cus_paid_value_3', 'sub_paid_value_3',
    )));

    expect(TicketLedgerEntry::query()->where('organization_id', $organization->getKey())->count())
        ->toBe($before)
        ->and($organization->fresh()?->signup_tickets_granted_at)->not->toBeNull();
});

test('signup grant の marker は再契約でも再付与を許さない', function (): void {
    $organization = paidValueOrganization('cus_paid_value_4');

    event(new WebhookReceived(paidValueSubscriptionCreatedPayload(
        'evt_paid_value_4a', 'cus_paid_value_4', 'sub_paid_value_4a',
    )));
    $granted = $organization->fresh()?->signup_tickets_granted_at;
    expect($granted)->not->toBeNull();

    $this->travelTo(CarbonImmutable::now()->addMonthNoOverflow());
    event(new WebhookReceived(paidValueSubscriptionCreatedPayload(
        'evt_paid_value_4b', 'cus_paid_value_4', 'sub_paid_value_4b',
    )));

    expect(TicketLedgerEntry::query()->where('organization_id', $organization->getKey())->count())->toBe(1);
});
