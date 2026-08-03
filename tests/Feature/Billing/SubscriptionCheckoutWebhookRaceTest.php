<?php

declare(strict_types=1);

use App\Enums\Billing\OnboardingBillingState;
use App\Enums\Billing\WebhookEventStatus;
use App\Enums\CheckoutSessionStatus;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Organization;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\Support\FakeStripeGateway;

/*
 * P9 (要件 8 / C-2): サブスク契約 Checkout の webhook 状態遷移。
 *
 * 遷移条件は 1 定義のみ: status !== Completed の行だけを payment_status の判定結果へ遷移させ、
 * Completed は終局 (再送は no-op = 冪等)。Failed / Expired からの遅延成功は受理する
 * (それらは AI-CUE 側のローカルな見立てであり、決済の終局は Stripe が持つ)。
 *
 * **金銭の付与経路には一切触れない** (付与は invoice.paid、plan_code 同期は
 * customer.subscription.* が真実源 = D7 境界)。
 */

/** @return array{Organization, BillingCheckoutSession} */
function subWebhookFixture(string $status = CheckoutSessionStatus::Pending->value): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_sub_test_1';
    $organization->save();

    $session = BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt((string) Str::ulid(), 'standard')
        ->create([
            'stripe_session_id' => 'cs_test_sub_1',
            'status' => $status,
        ]);

    return [$organization, $session];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function subCompletedPayload(Organization $organization, string $eventId = 'evt_sub_1', array $overrides = []): array
{
    $object = array_merge([
        'id' => 'cs_test_sub_1',
        'mode' => 'subscription',
        'customer' => 'cus_sub_test_1',
        'payment_status' => 'paid',
        'subscription' => 'sub_test_1',
        'metadata' => [
            'purpose' => 'subscription_start',
            'org_ref' => (string) $organization->id,
            'plan_code' => 'standard',
        ],
    ], $overrides);

    return [
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'data' => ['object' => $object],
    ];
}

test('paid の completed で行が Completed になり、チケット付与も plan_code 同期も起きない', function (): void {
    [$organization, $session] = subWebhookFixture();
    $planCodeBefore = $organization->plan_code;

    event(new WebhookReceived(subCompletedPayload($organization)));

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
    expect($session->completed_at)->not->toBeNull();
    // D7 境界: 台帳もプランも動かさない
    expect($organization->ticketLedgerEntries()->count())->toBe(0);
    expect($organization->refresh()->plan_code)->toBe($planCodeBefore);
});

test('同一 event の再送は終局 no-op (completed_at が更新されない)', function (): void {
    [$organization, $session] = subWebhookFixture();

    event(new WebhookReceived(subCompletedPayload($organization)));
    $completedAt = $session->refresh()->completed_at;

    // event_id 違いの再送 (claim skip を迂回) でも終局 no-op
    event(new WebhookReceived(subCompletedPayload($organization, 'evt_sub_2')));

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
    expect($session->completed_at?->toIso8601String())->toBe($completedAt?->toIso8601String());
});

test('Expired / Failed 行への遅延 completed (paid) は Completed として受理する', function (string $status): void {
    [$organization, $session] = subWebhookFixture($status);

    event(new WebhookReceived(subCompletedPayload($organization)));

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
})->with([
    'expired' => [CheckoutSessionStatus::Expired->value],
    'failed' => [CheckoutSessionStatus::Failed->value],
]);

test('Completed 行への unpaid は遷移しない (終局)', function (): void {
    [$organization, $session] = subWebhookFixture(CheckoutSessionStatus::Completed->value);

    event(new WebhookReceived(subCompletedPayload($organization, overrides: ['payment_status' => 'unpaid'])));

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
});

test('unpaid は Failed へ、payment_status 欠落 / 未知値は遷移しない', function (?string $paymentStatus, string $expected): void {
    [$organization, $session] = subWebhookFixture();

    $overrides = $paymentStatus === null ? ['payment_status' => null] : ['payment_status' => $paymentStatus];
    event(new WebhookReceived(subCompletedPayload($organization, overrides: $overrides)));

    expect($session->refresh()->status)->toBe($expected);
})->with([
    'unpaid' => ['unpaid', CheckoutSessionStatus::Failed->value],
    'null' => [null, CheckoutSessionStatus::Pending->value],
    'unknown' => ['no_payment_yet', CheckoutSessionStatus::Pending->value],
]);

test('Expired 行への unpaid は Failed になる', function (): void {
    [$organization, $session] = subWebhookFixture(CheckoutSessionStatus::Expired->value);

    event(new WebhookReceived(subCompletedPayload($organization, overrides: ['payment_status' => 'unpaid'])));

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Failed->value);
});

test('cancel 相当 (webhook が来ない) では行が Pending のまま、2 日後は ExpiredCheckout で再開できる', function (): void {
    $this->app->singleton(StripeGatewayInterface::class, fn (): FakeStripeGateway => new FakeStripeGateway);

    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $session = BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt((string) Str::ulid(), 'standard')
        ->create(['created_at' => CarbonImmutable::now()->subDays(2)]);

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
    expect(app(BillingAccess::class)->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
    // state() 実行で行は書き換わらない
    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);

    $this->actingAs($owner)->post('/billing/checkout', [
        'plan_code' => 'standard',
        'subscription_attempt_token' => (string) Str::ulid(),
    ])->assertRedirectContains('https://checkout.stripe.test/');
});

test('行不在の completed は retryable failure (silent 付与しない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_sub_test_1';
    $organization->save();

    app()->instance(ExceptionHandler::class, Mockery::spy(ExceptionHandler::class));

    expect(fn () => event(new WebhookReceived(subCompletedPayload($organization))))
        ->toThrow(RuntimeException::class);

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_sub_1')->firstOrFail()->status)
        ->toBe(WebhookEventStatus::Failed);
});

test('customer / metadata.org_ref の照合不一致は throw する (tenant キー不信)', function (array $overrides): void {
    [$organization, $session] = subWebhookFixture();

    app()->instance(ExceptionHandler::class, Mockery::spy(ExceptionHandler::class));

    expect(fn () => event(new WebhookReceived(subCompletedPayload($organization, overrides: $overrides))))
        ->toThrow(RuntimeException::class);

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
})->with([
    'customer 不一致' => [['customer' => 'cus_other']],
    'org_ref 不一致' => [['metadata' => ['purpose' => 'subscription_start', 'org_ref' => '99999', 'plan_code' => 'standard']]],
]);

test('purpose ディスパッチは排他: ticket_purchase / mode=setup は settleSubscriptionCheckout に入らない', function (): void {
    [$organization, $session] = subWebhookFixture();

    // purpose=ticket_purchase の payload を投げても subscription 行は動かない
    // (ticket 側は追跡行不在で throw するため purpose 不一致だけを見る payload にする)
    $payload = subCompletedPayload($organization, 'evt_other_1', [
        'mode' => 'payment',
        'metadata' => ['purpose' => 'other_purpose'],
    ]);
    event(new WebhookReceived($payload));

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);

    // mode=setup + purpose=auto_recharge_setup は P8a 経路 (subscription 行に触れない)
    $setupPayload = subCompletedPayload($organization, 'evt_other_2', [
        'mode' => 'setup',
        'metadata' => ['purpose' => 'other_setup'],
    ]);
    event(new WebhookReceived($setupPayload));

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
});
