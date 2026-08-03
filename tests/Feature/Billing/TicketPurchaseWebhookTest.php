<?php

declare(strict_types=1);

use App\Enums\Billing\TicketCheckoutSessionStatus;
use App\Enums\Billing\TicketSource;
use App\Enums\Billing\WebhookEventStatus;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Organization;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\StripeWebhookProcessor;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Cashier\Events\WebhookReceived;

/*
 * checkout.session.completed によるチケット購入の冪等付与。
 * 真実源は ticket_checkout_sessions 行。照合不一致・行不在は retryable failure (failed +
 * Stripe 再送)、purpose 外は受理のみ (processed)。Stripe API は呼ばない
 * (WebhookReceived 直発火の既存流儀)。
 */

/** stripe_id 付き org + owner + pending 追跡行を作る */
function ticketPurchaseFixture(int $count = 30, int $unitAmount = 80): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_ticket_test_1';
    $organization->save();

    $session = TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create([
            'ticket_count' => $count,
            'unit_amount' => $unitAmount,
            'currency' => 'jpy',
            'stripe_session_id' => 'cs_test_purchase_1',
        ]);

    return [$organization, $owner, $session];
}

/**
 * @return array<string, mixed>
 */
function ticketPurchaseCompletedPayload(
    string $eventId = 'evt_ticket_purchase_1',
    array $overrides = [],
): array {
    $object = array_merge([
        'id' => 'cs_test_purchase_1',
        'mode' => 'payment',
        'customer' => 'cus_ticket_test_1',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_ticket_test_1',
        'amount_subtotal' => 30 * 80,
        'currency' => 'jpy',
        'metadata' => [
            'purpose' => 'ticket_purchase',
            'org_ref' => '',
            'count' => '30',
        ],
    ], $overrides);

    return [
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'data' => ['object' => $object],
    ];
}

/** org_ref を実 org id で埋めた正常 payload */
function paidTicketPayload(Organization $organization, string $eventId = 'evt_ticket_purchase_1'): array
{
    $payload = ticketPurchaseCompletedPayload($eventId);
    $payload['data']['object']['metadata']['org_ref'] = (string) $organization->id;

    return $payload;
}

test('正常系: pending 行 + paid payload で残高が増え行が completed 化される', function (): void {
    [$organization, , $session] = ticketPurchaseFixture();

    event(new WebhookReceived(paidTicketPayload($organization)));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
    expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Completed);
    expect($session->completed_at)->not->toBeNull();

    $entry = $organization->ticketLedgerEntries()
        ->where('idempotency_key', 'purchase:cs_test_purchase_1')
        ->firstOrFail();
    expect($entry->delta)->toBe(30);
    expect($entry->source)->toBe(TicketSource::Purchased);
    expect($entry->payment_intent_id)->toBe('pi_ticket_test_1');
    expect($entry->purchase_amount)->toBe(2400);
});

test('同一 event_id の再送は claim skip で二重付与しない', function (): void {
    [$organization] = ticketPurchaseFixture();

    event(new WebhookReceived(paidTicketPayload($organization)));
    event(new WebhookReceived(paidTicketPayload($organization)));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
    expect($organization->ticketLedgerEntries()->count())->toBe(1);
});

test('event_id 違いの同一 session 再送は台帳 idempotency_key で二重付与しない', function (): void {
    [$organization] = ticketPurchaseFixture();

    event(new WebhookReceived(paidTicketPayload($organization, 'evt_a')));
    event(new WebhookReceived(paidTicketPayload($organization, 'evt_b')));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
    expect($organization->ticketLedgerEntries()->count())->toBe(1);
});

test('DB 行なし (crash 先着) は failed になり、行記録後の再送で一度だけ付与される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_ticket_test_1';
    $organization->save();

    // (1) 追跡行が無い状態で webhook 先着 → 例外 = failed + 付与なし
    expect(fn () => event(new WebhookReceived(paidTicketPayload($organization))))
        ->toThrow(RuntimeException::class, '未追跡 session');

    $record = StripeWebhookEvent::query()->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Failed);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);

    // (2) 同一 attempt の再試行が DB 行を記録 (Stripe idempotency key で同一 session に収束)
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create([
            'ticket_count' => 30,
            'unit_amount' => 80,
            'currency' => 'jpy',
            'stripe_session_id' => 'cs_test_purchase_1',
        ]);

    // (3) Stripe の event 再送 (failed→received 復帰) で一度だけ付与
    event(new WebhookReceived(paidTicketPayload($organization)));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
    expect($organization->ticketLedgerEntries()->count())->toBe(1);
    expect($record->refresh()->status)->toBe(WebhookEventStatus::Processed);
});

test('照合不一致・未決済は failed + 付与なし (fail-closed)', function (array $overrides, string $messagePart): void {
    [$organization, , $session] = ticketPurchaseFixture();

    $payload = paidTicketPayload($organization);
    foreach ($overrides as $key => $value) {
        data_set($payload, "data.object.{$key}", $value);
    }

    expect(fn () => event(new WebhookReceived($payload)))
        ->toThrow(RuntimeException::class, $messagePart);

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Pending);
    expect(StripeWebhookEvent::query()->firstOrFail()->status)->toBe(WebhookEventStatus::Failed);
})->with([
    'payment_status 未決済' => [['payment_status' => 'unpaid'], 'payment_status'],
    'amount_subtotal 不一致' => [['amount_subtotal' => 100], '金額/通貨照合不一致'],
    'currency 不一致' => [['currency' => 'usd'], '金額/通貨照合不一致'],
    'customer 不一致' => [['customer' => 'cus_other'], 'customer 照合不一致'],
    'metadata org_ref 不一致' => [['metadata.org_ref' => '999999'], 'org_ref 照合不一致'],
]);

test('purpose 外・mode 外は受理のみ (processed) で付与しない', function (array $overrides): void {
    [$organization, , $session] = ticketPurchaseFixture();

    $payload = paidTicketPayload($organization);
    foreach ($overrides as $key => $value) {
        data_set($payload, "data.object.{$key}", $value);
    }

    event(new WebhookReceived($payload));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Pending);
    expect(StripeWebhookEvent::query()->firstOrFail()->status)->toBe(WebhookEventStatus::Processed);
})->with([
    'purpose なし' => [['metadata.purpose' => null]],
    '別 purpose' => [['metadata.purpose' => 'other_purpose']],
    'mode=subscription' => [['mode' => 'subscription']],
]);

test('expired 行への遅延 completed webhook でも付与し completed 化する (決済成立が真実源)', function (): void {
    // DB 上 expired 扱いでも Stripe 側で決済が成立していれば completed event が届きうる
    // (expire API との競合・event 遅延到着)。支払い済みの付与を落とさない方針を pin する
    [$organization, , $session] = ticketPurchaseFixture();
    $session->status = TicketCheckoutSessionStatus::Expired;
    $session->save();

    event(new WebhookReceived(paidTicketPayload($organization)));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
    expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Completed);
    expect($session->completed_at)->not->toBeNull();
});

test('attempts 上限到達の checkout.session.completed は terminal-ack + report される', function (): void {
    [$organization] = ticketPurchaseFixture();

    $record = new StripeWebhookEvent;
    $record->event_id = 'evt_terminal_1';
    $record->type = 'checkout.session.completed';
    $record->status = WebhookEventStatus::Failed;
    $record->payload = paidTicketPayload($organization, 'evt_terminal_1');
    $record->attempts = StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS;
    $record->failure_reason = '恒久失敗';
    $record->save();

    // report() 経路 (運用アラート) に載ることを ExceptionHandler の spy で観測する
    $handler = Mockery::spy(ExceptionHandler::class);
    app()->instance(ExceptionHandler::class, $handler);

    // 例外は投げられない (terminal-ack = 200) + 付与されない
    event(new WebhookReceived(paidTicketPayload($organization, 'evt_terminal_1')));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    expect($record->refresh()->status)->toBe(WebhookEventStatus::Failed);
    expect($record->attempts)->toBe(StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
    $handler->shouldHaveReceived('report')->once();
});

test('gateway payload は promo / automatic tax を含まない (金額照合の前提 invariant)', function (): void {
    [$organization] = ticketPurchaseFixture();
    $gateway = new CashierTicketCheckoutGateway;

    $payload = $gateway->buildSessionPayload(
        $organization,
        'price_test_x',
        30,
        'https://app.test/success',
        'https://app.test/cancel',
        ['purpose' => 'ticket_purchase'],
    );

    expect($payload)->not->toHaveKey('allow_promotion_codes');
    expect($payload)->not->toHaveKey('automatic_tax');
    expect($payload['mode'])->toBe('payment');
    expect($payload['payment_method_types'])->toBe(['card']);
    expect($payload['line_items'])->toBe([['price' => 'price_test_x', 'quantity' => 30]]);
});

test('付与後の charge.refunded (payment_intent 一致) で既存 clawback が逆仕訳する', function (): void {
    [$organization] = ticketPurchaseFixture();

    event(new WebhookReceived(paidTicketPayload($organization)));
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);

    // 全額返金 → 全枚数逆仕訳 (既存 charge.refunded → clawbackPurchasedByPaymentIntent 経路)
    event(new WebhookReceived([
        'id' => 'evt_refund_1',
        'type' => 'charge.refunded',
        'data' => [
            'object' => [
                'id' => 'ch_test_1',
                'payment_intent' => 'pi_ticket_test_1',
                'amount_refunded' => 2400,
            ],
        ],
    ]));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
});
