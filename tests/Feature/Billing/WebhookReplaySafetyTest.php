<?php

declare(strict_types=1);

use App\Enums\Billing\HandledStripeWebhookEvent;
use App\Enums\Billing\PlanPriceKind;
use App\Enums\Billing\WebhookReplaySafety;
use App\Models\Billing\Plan;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\TicketLedgerService;
use Laravel\Cashier\Events\WebhookReceived;
use Webmozart\Assert\Assert;

/*
 * 保存済み payload を再実行してよいかの分類 (HandledStripeWebhookEvent::replaySafety)。
 *
 * 分類は滞留回収 (StripeWebhookProcessor::recoverStuckEvent) が自動再実行の可否に使う唯一の判断材料
 * なので、網羅性と個々の値に加えて **SafeToReplay の前提** (付与が下位の冪等キーで冪等であること)
 * も behavioral に固定する。
 */

/**
 * stripe_id を持つ組織と owner を作る (回収分類テスト用)。
 *
 * @return array{Organization, User}
 */
function replaySafetyFixture(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_replay_safety_1';
    $organization->save();

    return [$organization, $owner];
}

/** standard プランの現行 base Price の Stripe Price ID。 */
function replaySafetyBasePriceId(): string
{
    $price = Plan::query()->where('code', 'standard')->firstOrFail()
        ->currentPrice(PlanPriceKind::Base);
    Assert::notNull($price, 'standard プランの current base price が未 seed');

    return $price->stripe_price_id;
}

/**
 * @return array<string, mixed>
 */
function replaySafetyInvoicePaidPayload(string $eventId, string $invoiceId): array
{
    return [
        'id' => $eventId,
        'type' => 'invoice.paid',
        'data' => [
            'object' => [
                'id' => $invoiceId,
                'customer' => 'cus_replay_safety_1',
                'billing_reason' => 'subscription_cycle',
                'lines' => [
                    'data' => [
                        ['price' => ['id' => replaySafetyBasePriceId()]],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function replaySafetyTicketPurchasePayload(
    string $eventId,
    string $sessionId,
    Organization $organization,
): array {
    return [
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => $sessionId,
                'mode' => 'payment',
                'customer' => 'cus_replay_safety_1',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_replay_safety_1',
                'amount_subtotal' => 30 * 80,
                'currency' => 'jpy',
                'metadata' => [
                    'purpose' => 'ticket_purchase',
                    'org_ref' => (string) $organization->id,
                    'count' => '30',
                ],
            ],
        ],
    ];
}

test('全 case が replaySafety() を返す (case 追加時に網羅 match が落ちる)', function (): void {
    foreach (HandledStripeWebhookEvent::cases() as $case) {
        expect($case->replaySafety())->toBeInstanceOf(WebhookReplaySafety::class);
    }

    expect(HandledStripeWebhookEvent::cases())->not->toBeEmpty();
});

test('customer.subscription.* は順序に依存する (OrderSensitive)', function (): void {
    expect(HandledStripeWebhookEvent::SubscriptionCreated->replaySafety())
        ->toBe(WebhookReplaySafety::OrderSensitive);
    expect(HandledStripeWebhookEvent::SubscriptionUpdated->replaySafety())
        ->toBe(WebhookReplaySafety::OrderSensitive);
    expect(HandledStripeWebhookEvent::SubscriptionDeleted->replaySafety())
        ->toBe(WebhookReplaySafety::OrderSensitive);
});

test('付与・通知・返金の 4 種は再実行しても追加の被害を生まない (SafeToReplay)', function (): void {
    expect(HandledStripeWebhookEvent::InvoicePaid->replaySafety())
        ->toBe(WebhookReplaySafety::SafeToReplay);
    expect(HandledStripeWebhookEvent::InvoicePaymentFailed->replaySafety())
        ->toBe(WebhookReplaySafety::SafeToReplay);
    expect(HandledStripeWebhookEvent::CheckoutSessionCompleted->replaySafety())
        ->toBe(WebhookReplaySafety::SafeToReplay);
    expect(HandledStripeWebhookEvent::ChargeRefunded->replaySafety())
        ->toBe(WebhookReplaySafety::SafeToReplay);
});

test('SafeToReplay の前提: 同一 invoice を別 event_id で 2 回処理しても月次付与は 1 行だけ', function (): void {
    [$organization] = replaySafetyFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);

    event(new WebhookReceived(replaySafetyInvoicePaidPayload('evt_replay_a', 'in_replay_1')));
    event(new WebhookReceived(replaySafetyInvoicePaidPayload('evt_replay_b', 'in_replay_1')));

    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'monthly:in_replay_1')->count())
        ->toBe(1);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
});

test('SafeToReplay の前提: 同一 session を別 event_id で 2 回処理しても購入付与は 1 行だけ', function (): void {
    [$organization, $owner] = replaySafetyFixture();

    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create([
            'ticket_count' => 30,
            'unit_amount' => 80,
            'currency' => 'jpy',
            'stripe_session_id' => 'cs_replay_1',
        ]);

    event(new WebhookReceived(
        replaySafetyTicketPurchasePayload('evt_purchase_a', 'cs_replay_1', $organization),
    ));
    event(new WebhookReceived(
        replaySafetyTicketPurchasePayload('evt_purchase_b', 'cs_replay_1', $organization),
    ));

    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'purchase:cs_replay_1')->count())
        ->toBe(1);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
});
