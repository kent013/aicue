<?php

declare(strict_types=1);

use App\Enums\Billing\SignupFundingChoice;
use App\Enums\CheckoutSessionStatus;
use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
use App\Jobs\Billing\SetDefaultPaymentMethodJob;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Organization;
use App\Services\Billing\StripeWebhookProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
|--------------------------------------------------------------------------
| キュー投入の原子性 (Stripe webhook の save + dispatch。AG-114 確定 1)
|--------------------------------------------------------------------------
|
| 打刻 / 台帳更新だけが残って job が投入されない状態は「表示と実態の食い違い」になる。
| 同一 tx に括り、tx level 観測 (baseline + 1 以上) で固定する。
*/

/** @param array<string, mixed> $payload */
function webhookAtomicityDispatch(array $payload): void
{
    app(StripeWebhookProcessor::class)->handle(new WebhookReceived($payload));
}

test('checkout.session.completed (funding=auto_recharge) の打刻と PM 流用 job 投入は同一 tx である', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database.after_commit'))->toBeFalse();

    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_wh_atomicity_1'])->save();
    $session = BillingCheckoutSession::factory()
        ->for($organization)
        ->initiatedBy((int) $owner->id)
        ->withAttempt((string) Str::ulid(), 'standard')
        ->create([
            'stripe_session_id' => 'cs_wh_atomicity_1',
            'funding_choice' => SignupFundingChoice::AutoRecharge->value,
        ]);

    $payload = [
        'id' => 'evt_wh_atomicity_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_wh_atomicity_1',
            'mode' => 'subscription',
            'customer' => 'cus_wh_atomicity_1',
            'payment_status' => 'paid',
            'subscription' => 'sub_wh_atomicity_1',
            'metadata' => [
                'purpose' => 'subscription_start',
                'org_ref' => (string) $organization->id,
                'plan_code' => 'standard',
            ],
        ]],
    ];

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => webhookAtomicityDispatch($payload),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), ReuseSubscriptionPaymentMethodJob::class);

    expect($session->refresh()->pm_reuse_dispatched_at)->not->toBeNull();
    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('auto_recharge_setup 完了の台帳更新と PM 既定設定 job 投入は同一 tx である', function (): void {
    config()->set('queue.default', 'database');

    /** @var Organization $organization */
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_wh_atomicity_2'])->save();
    $session = BillingCheckoutSession::factory()
        ->for($organization)
        ->setupPaymentMethod()
        ->create(['stripe_session_id' => 'cs_wh_atomicity_2']);

    $payload = [
        'id' => 'evt_wh_atomicity_2',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_wh_atomicity_2',
            'mode' => 'setup',
            'customer' => 'cus_wh_atomicity_2',
            'setup_intent' => 'seti_wh_atomicity_2',
            'metadata' => ['purpose' => 'auto_recharge_setup'],
        ]],
    ];

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => webhookAtomicityDispatch($payload),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), SetDefaultPaymentMethodJob::class);

    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});
