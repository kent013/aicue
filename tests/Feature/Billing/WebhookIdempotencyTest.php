<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Enums\Billing\WebhookEventStatus;
use App\Models\Billing\Plan;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Organization;
use App\Services\Billing\StripeWebhookProcessor;
use App\Services\Billing\TicketLedgerService;
use Laravel\Cashier\Events\WebhookReceived;
use Webmozart\Assert\Assert;

/*
 * Stripe webhook 冪等マシン (StripeWebhookProcessor)。
 * Stripe API は呼ばない: Cashier の WebhookReceived イベントを直接発火して検証する。
 */

function billingStripeCustomer(): Organization
{
    [$organization] = createOrganizationWithOwner();
    // stripe_id は Cashier customer column (状態キー)。テストでは明示代入する
    $organization->stripe_id = 'cus_test_123';
    $organization->save();

    return $organization;
}

/** PlanSeeder が bootstrap 投入した standard プラン現行 base Price の Stripe Price ID */
function billingStandardBasePriceId(): string
{
    $price = Plan::query()->where('code', 'standard')->firstOrFail()
        ->currentPrice(PlanPriceKind::Base);
    Assert::notNull($price, 'standard プランの current base price が未 seed');

    return $price->stripe_price_id;
}

/**
 * standard プランの月次付与を有効化する (arrange)。
 *
 * D28 で月次付与は廃止され seed 既定の monthly_ticket_grant は全 tier 0 になった。
 * 列とコード経路 (StripeWebhookProcessor::grantMonthlyTickets) は運用上の再開のため
 * 残しているので、その経路を検証する test は arrange で明示的に枚数を設定する。
 */
function enableStandardMonthlyGrant(int $count = 100): void
{
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => $count]);
}

/**
 * @return array<string, mixed>
 */
function invoicePaidPayload(string $eventId = 'evt_invoice_paid_1'): array
{
    return [
        'id' => $eventId,
        'type' => 'invoice.paid',
        'data' => [
            'object' => [
                'id' => 'in_test_1',
                'customer' => 'cus_test_123',
                'billing_reason' => 'subscription_cycle',
                'lines' => [
                    'data' => [
                        ['price' => ['id' => billingStandardBasePriceId()]],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function subscriptionPayload(string $type, string $status, string $eventId): array
{
    return [
        'id' => $eventId,
        'type' => $type,
        'data' => [
            'object' => [
                'id' => 'sub_test_1',
                'customer' => 'cus_test_123',
                'status' => $status,
                'items' => [
                    'data' => [
                        ['price' => ['id' => billingStandardBasePriceId()]],
                    ],
                ],
            ],
        ],
    ];
}

test('同一 event_id の invoice.paid を 2 回発火しても付与は 1 回だけ', function (): void {
    $organization = billingStripeCustomer();
    enableStandardMonthlyGrant();

    // listener 配線 (AppServiceProvider) ごと検証するため event() で発火する
    event(new WebhookReceived(invoicePaidPayload()));
    event(new WebhookReceived(invoicePaidPayload()));

    // standard プランの monthly_ticket_grant (100) が 1 回だけ付与される
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
    expect($organization->ticketLedgerEntries()->count())->toBe(1);
    expect(StripeWebhookEvent::query()->count())->toBe(1);
    $record = StripeWebhookEvent::query()->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Processed);
    expect($record->event_id)->toBe('evt_invoice_paid_1');
});

test('event_id が異なれば別イベントとして処理される (invoice id が違えば別付与)', function (): void {
    $organization = billingStripeCustomer();
    enableStandardMonthlyGrant();

    $first = invoicePaidPayload('evt_1');
    $second = invoicePaidPayload('evt_2');
    $second['data']['object']['id'] = 'in_test_2'; // 別 invoice = 別の月次付与

    event(new WebhookReceived($first));
    event(new WebhookReceived($second));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(200);
    expect(StripeWebhookEvent::query()->count())->toBe(2);
});

test('event_id が異なっても同一 invoice の再通知は idempotency_key で二重付与しない', function (): void {
    $organization = billingStripeCustomer();
    enableStandardMonthlyGrant();

    // Stripe が同一 invoice を別 event_id で再通知するケース (event_id 冪等では防げない)
    event(new WebhookReceived(invoicePaidPayload('evt_dup_a')));
    event(new WebhookReceived(invoicePaidPayload('evt_dup_b')));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
    expect($organization->ticketLedgerEntries()->count())->toBe(1);
    expect(StripeWebhookEvent::query()->count())->toBe(2);
});

test('billing_reason=subscription_create の invoice.paid は月次付与に加えて signup grant を冪等付与する', function (): void {
    $organization = billingStripeCustomer();
    enableStandardMonthlyGrant();

    $payload = invoicePaidPayload('evt_signup_1');
    $payload['data']['object']['billing_reason'] = 'subscription_create';

    event(new WebhookReceived($payload));

    // 月次 100 + signup grant (config billing.signup_grant_tickets = 10)
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(110);
    // 冪等キーは org スコープ (呼び出し側が渡す)。subscription id には依存しない。
    $signup = $organization->ticketLedgerEntries()
        ->where('idempotency_key', "signup_grant:org:{$organization->id}")
        ->firstOrFail();
    expect($signup->delta)->toBe(config('billing.signup_grant_tickets'));
    expect($signup->expires_at)->not->toBeNull();

    // 別 event_id での再通知でも signup grant は 1 回だけ (idempotency_key 冪等)
    $retry = $payload;
    $retry['id'] = 'evt_signup_2';
    event(new WebhookReceived($retry));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(110);
});

test('subscription id が無くても org スコープキーで signup grant を付与する', function (): void {
    $organization = billingStripeCustomer();
    enableStandardMonthlyGrant();

    // subscription id を含まない subscription_create の invoice.paid。
    // org スコープキー (signup_grant:org:{id}) は subscription id に依存しないため付与される。
    $payload = invoicePaidPayload('evt_signup_nosub');
    $payload['data']['object']['billing_reason'] = 'subscription_create';

    event(new WebhookReceived($payload));

    // 月次 100 + signup grant 10
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(110);
    expect(
        $organization->ticketLedgerEntries()
            ->where('idempotency_key', 'like', 'signup_grant:%')
            ->count(),
    )->toBe(1);
});

test('seed 既定 (D28: monthly_ticket_grant=0) では invoice.paid で月次付与行が作られない', function (): void {
    $organization = billingStripeCustomer();
    // arrange 無し = seed 既定 (全 tier 0)。grantMonthlyTickets の guard で付与が走らない。

    $payload = invoicePaidPayload('evt_d28_no_monthly');
    $payload['data']['object']['billing_reason'] = 'subscription_create';

    event(new WebhookReceived($payload));

    expect($organization->ticketLedgerEntries()
        ->where('idempotency_key', 'like', 'monthly:%')->count())->toBe(0);
    // signup grant のみが計上される (残高は config の付与枚数と一致)
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())
        ->toBe(config('billing.signup_grant_tickets'));
    expect($organization->ticketLedgerEntries()->count())->toBe(1);
});

test('customer.subscription.updated で organizations.plan_code が同期される', function (): void {
    $organization = billingStripeCustomer();
    expect($organization->plan_code)->toBeNull();

    event(new WebhookReceived(
        subscriptionPayload('customer.subscription.updated', 'active', 'evt_sub_updated_1'),
    ));

    expect($organization->refresh()->plan_code)->toBe('standard');
});

test('active / trialing 以外の status では plan_code を書き換えない', function (): void {
    $organization = billingStripeCustomer();
    $organization->plan_code = 'standard';
    $organization->save();

    event(new WebhookReceived(
        subscriptionPayload('customer.subscription.updated', 'past_due', 'evt_sub_pastdue_1'),
    ));

    expect($organization->refresh()->plan_code)->toBe('standard');
});

test('customer.subscription.deleted で plan_code が解除される', function (): void {
    $organization = billingStripeCustomer();
    $organization->plan_code = 'standard';
    $organization->save();

    event(new WebhookReceived(
        subscriptionPayload('customer.subscription.deleted', 'canceled', 'evt_sub_deleted_1'),
    ));

    expect($organization->refresh()->plan_code)->toBeNull();
});

test('billing_reason がサブスク以外の invoice.paid では付与しない', function (): void {
    $organization = billingStripeCustomer();
    enableStandardMonthlyGrant();
    $payload = invoicePaidPayload('evt_manual_invoice');
    $payload['data']['object']['billing_reason'] = 'manual';

    event(new WebhookReceived($payload));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    // 受理自体は冪等記録される (processed)
    $record = StripeWebhookEvent::query()->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Processed);
});

test('未知の customer のイベントは受理のみで何も変更しない', function (): void {
    $organization = billingStripeCustomer();
    enableStandardMonthlyGrant();
    $payload = invoicePaidPayload('evt_unknown_customer');
    $payload['data']['object']['customer'] = 'cus_other';

    event(new WebhookReceived($payload));

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    expect(StripeWebhookEvent::query()->count())->toBe(1);
});

/*
 * 冪等マシンの再送上限 (terminal-ack)。
 * failed→received 復帰のたびに attempts をインクリメントし、
 * MAX_PROCESSING_ATTEMPTS 到達後は処理せず skip (= 200 ack) する。
 */

/**
 * failed 状態の冪等記録を明示代入で作る (attempts / failure_reason 検証用)。
 */
function failedWebhookRecord(string $eventId, int $attempts): StripeWebhookEvent
{
    $record = new StripeWebhookEvent;
    $record->event_id = $eventId;
    $record->type = 'invoice.paid';
    $record->status = WebhookEventStatus::Failed;
    $record->payload = invoicePaidPayload($eventId);
    $record->attempts = $attempts;
    $record->failure_reason = '過去の失敗理由';
    $record->save();

    return $record;
}

test('処理失敗時は failed + failure_reason を記録して再 throw する (Stripe 再送を促す)', function (): void {
    billingStripeCustomer();
    enableStandardMonthlyGrant();
    $this->mock(TicketLedgerService::class)
        ->shouldReceive('grantMonthly')
        ->andThrow(new RuntimeException('付与処理の一時故障'));

    expect(fn () => event(new WebhookReceived(invoicePaidPayload('evt_fail_1'))))
        ->toThrow(RuntimeException::class);

    $record = StripeWebhookEvent::query()->where('event_id', 'evt_fail_1')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Failed);
    expect($record->failure_reason)->toBe('付与処理の一時故障');
    expect($record->attempts)->toBe(0); // インクリメントは failed→received 復帰時 (再送時)
});

test('failed の再送で attempts が増え、成功すれば failure_reason が消える', function (): void {
    $organization = billingStripeCustomer();
    enableStandardMonthlyGrant();
    failedWebhookRecord('evt_retry_ok', 2);

    // Stripe 再送: failed→received 復帰 (attempts+1) して再処理 → 成功
    event(new WebhookReceived(invoicePaidPayload('evt_retry_ok')));

    $record = StripeWebhookEvent::query()->where('event_id', 'evt_retry_ok')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Processed);
    expect($record->attempts)->toBe(3);
    expect($record->failure_reason)->toBeNull();
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
});

test('attempts が上限到達済みの failed は terminal-ack (処理せず例外も投げない)', function (): void {
    $organization = billingStripeCustomer();
    enableStandardMonthlyGrant();
    failedWebhookRecord('evt_terminal', StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);

    // 再送されても claim が null を返し、処理も再 throw もしない (= Cashier が 200 を返す)
    event(new WebhookReceived(invoicePaidPayload('evt_terminal')));

    $record = StripeWebhookEvent::query()->where('event_id', 'evt_terminal')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Failed); // failed のまま (運用調査用に保持)
    expect($record->attempts)->toBe(StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0); // 付与されない
});
