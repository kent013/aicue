<?php

declare(strict_types=1);

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Enums\CheckoutSessionStatus;
use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
use App\Jobs\Billing\SetDefaultPaymentMethodJob;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\StripeWebhookProcessor;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\Support\FakeAutoRechargeGateway;

/*
 * P8a: オートリチャージ関連の Stripe webhook。
 *
 * - invoice.paid (metadata.purpose=auto_recharge) → 冪等付与 + attempt paid
 * - invoice.payment_failed (同上) → 失敗振り分け Job (外向き API は webhook から退避)
 * - checkout.session.completed (mode=setup) → PM default 設定 Job
 * - 月次付与 (billing_reason allowlist) には混入しない
 */

beforeEach(function (): void {
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
});

/** @return array{Organization, TicketAutoRechargeAttempt} */
function autoRechargeWebhookFixture(int $quantity = 40, int $unitAmount = 70): array
{
    [$organization] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_autorecharge_1';
    $organization->save();

    $attempt = TicketAutoRechargeAttempt::factory()
        ->for($organization)
        ->withInvoice('in_autorecharge_1')
        ->create(['quantity' => $quantity, 'unit_amount' => $unitAmount]);

    return [$organization, $attempt];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function autoRechargeInvoicePaidPayload(string $eventId, array $overrides = []): array
{
    $object = array_merge([
        'id' => 'in_autorecharge_1',
        'customer' => 'cus_autorecharge_1',
        'billing_reason' => 'manual',
        'amount_paid' => 40 * 70,
        'amount_due' => 40 * 70,
        'payment_intent' => 'pi_autorecharge_1',
        'metadata' => [
            'purpose' => 'auto_recharge',
            'organization_id' => '999999', // 照合専用 (org 解決には使わない)
            'recharge_attempt_ulid' => 'PLACEHOLDER',
        ],
    ], $overrides);

    return [
        'id' => $eventId,
        'type' => 'invoice.paid',
        'data' => ['object' => $object],
    ];
}

function dispatchWebhook(array $payload): void
{
    app(StripeWebhookProcessor::class)->handle(new WebhookReceived($payload));
}

test('auto_recharge invoice.paid でチケットが冪等付与され attempt が paid になる', function (): void {
    [$organization, $attempt] = autoRechargeWebhookFixture();

    $payload = autoRechargeInvoicePaidPayload('evt_ar_1');
    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;

    dispatchWebhook($payload);

    $attempt->refresh();
    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Paid)
        ->and($attempt->stripe_payment_intent_id)->toBe('pi_autorecharge_1');

    $entry = TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->firstOrFail();
    expect($entry->delta)->toBe(40)
        ->and($entry->purchase_amount)->toBe(40 * 70)
        ->and($entry->stripe_invoice_id)->toBe('in_autorecharge_1');
});

test('同一 invoice の invoice.paid を 2 回処理しても付与は 1 行 (二重課金・二重付与しない)', function (): void {
    [$organization, $attempt] = autoRechargeWebhookFixture();

    foreach (['evt_ar_dup_1', 'evt_ar_dup_2'] as $eventId) {
        $payload = autoRechargeInvoicePaidPayload($eventId);
        $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
        dispatchWebhook($payload);
    }

    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->count())->toBe(1);
    expect(app(TicketLedgerService::class)->availableTrueBalance($organization->fresh()))->toBe(40);
});

test('同期 pay が先に付与済みでも webhook 到着で二重付与しない', function (): void {
    [$organization, $attempt] = autoRechargeWebhookFixture();

    // 同期 pay 経路が先に付与した状態を作る
    app(TicketLedgerService::class)->grantAutoRecharge($organization, 40, 'in_autorecharge_1', 40 * 70, 'pi_autorecharge_1');

    $payload = autoRechargeInvoicePaidPayload('evt_ar_race');
    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
    dispatchWebhook($payload);

    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->count())->toBe(1);
    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
});

test('amount_due 不一致は fail-closed (例外 + 付与なし)', function (): void {
    [$organization, $attempt] = autoRechargeWebhookFixture();

    $payload = autoRechargeInvoicePaidPayload('evt_ar_mismatch', ['amount_due' => 1, 'amount_paid' => 1]);
    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;

    expect(fn () => dispatchWebhook($payload))->toThrow(RuntimeException::class);

    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->exists())->toBeFalse();
    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
});

test('amount_paid < amount_due (credit balance 適用) は正当 — 付与成立 + purchase_amount は実回収額', function (): void {
    [$organization, $attempt] = autoRechargeWebhookFixture();

    $payload = autoRechargeInvoicePaidPayload('evt_ar_credit', ['amount_paid' => 0]);
    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
    dispatchWebhook($payload);

    $entry = TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->firstOrFail();
    expect($entry->purchase_amount)->toBe(0)->and($entry->delta)->toBe(40);
});

test('customer 照合不一致は fail-closed (metadata の organization_id を信用しない)', function (): void {
    [$organization, $attempt] = autoRechargeWebhookFixture();

    $payload = autoRechargeInvoicePaidPayload('evt_ar_cross', ['customer' => 'cus_other_org']);
    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;

    expect(fn () => dispatchWebhook($payload))->toThrow(RuntimeException::class);
    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_autorecharge_1')->exists())->toBeFalse();
});

test('未追跡 attempt の invoice.paid は retryable failure (Stripe 再送待ち)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_autorecharge_1';
    $organization->save();

    $payload = autoRechargeInvoicePaidPayload('evt_ar_untracked');
    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = 'unknown-ulid';

    expect(fn () => dispatchWebhook($payload))->toThrow(RuntimeException::class);
});

test('auto-recharge invoice (billing_reason=manual) は月次付与に混入しない', function (): void {
    [$organization, $attempt] = autoRechargeWebhookFixture();
    contractPaidPlan($organization);

    $payload = autoRechargeInvoicePaidPayload('evt_ar_no_monthly');
    $payload['data']['object']['metadata']['recharge_attempt_ulid'] = $attempt->attempt_ulid;
    dispatchWebhook($payload);

    // 月次付与 (monthly:{invoiceId}) / signup grant は 1 件も増えない
    expect(TicketLedgerEntry::query()->where('idempotency_key', 'like', 'monthly:%')->exists())->toBeFalse();
});

test('auto_recharge の invoice.payment_failed は専用 Job に振られる (外向き API は webhook から退避)', function (): void {
    Queue::fake();
    [$organization, $attempt] = autoRechargeWebhookFixture();

    dispatchWebhook([
        'id' => 'evt_ar_failed',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => [
            'id' => 'in_autorecharge_1',
            'customer' => 'cus_autorecharge_1',
            'metadata' => [
                'purpose' => 'auto_recharge',
                'recharge_attempt_ulid' => $attempt->attempt_ulid,
            ],
        ]],
    ]);

    Queue::assertPushed(
        HandleAutoRechargeChargeFailureJob::class,
        fn (HandleAutoRechargeChargeFailureJob $job): bool => $job->attemptId === $attempt->id,
    );
});

test('mode=setup の checkout.session.completed は台帳を completed 化し PM 設定 Job を dispatch する', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_setup_1';
    $organization->save();

    $session = BillingCheckoutSession::factory()
        ->for($organization)
        ->setupPaymentMethod()
        ->create(['stripe_session_id' => 'cs_setup_webhook_1']);

    dispatchWebhook([
        'id' => 'evt_setup_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_setup_webhook_1',
            'mode' => 'setup',
            'customer' => 'cus_setup_1',
            'setup_intent' => 'seti_test_1',
            'metadata' => ['purpose' => 'auto_recharge_setup'],
        ]],
    ]);

    expect($session->fresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
    Queue::assertPushed(
        SetDefaultPaymentMethodJob::class,
        fn (SetDefaultPaymentMethodJob $job): bool => $job->organizationId === $organization->id
            && $job->setupIntentId === 'seti_test_1',
    );
});

test('mode=setup でも他組織の customer なら fail-closed (IDOR)', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_setup_1';
    $organization->save();

    BillingCheckoutSession::factory()
        ->for($organization)
        ->setupPaymentMethod()
        ->create(['stripe_session_id' => 'cs_setup_webhook_2']);

    expect(fn () => dispatchWebhook([
        'id' => 'evt_setup_2',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_setup_webhook_2',
            'mode' => 'setup',
            'customer' => 'cus_someone_else',
            'setup_intent' => 'seti_test_2',
            'metadata' => ['purpose' => 'auto_recharge_setup'],
        ]],
    ]))->toThrow(RuntimeException::class);

    Queue::assertNotPushed(SetDefaultPaymentMethodJob::class);
});
