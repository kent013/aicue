<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\DataTransferObjects\Billing\InvoiceStateDto;
use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Enums\Billing\BillingNotificationType;
use App\Models\Billing\BillingNotification;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAutoRechargeGateway;

/*
 * P8a: リコンサイル (5 分岐) + D20 の監視 DoD。
 *
 * webhook が terminal-ack で恒久 drop した「課金済み・付与なし」の唯一のセーフティネット。
 */

beforeEach(function (): void {
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
});

test('(i) invoice 未作成の pending は 15 分超で再実行される', function (): void {
    [$organization] = createOrganizationWithOwner();
    $attempt = TicketAutoRechargeAttempt::factory()->for($organization)->create([
        'created_at' => CarbonImmutable::now()->subMinutes(20),
    ]);
    // 停止後課金の禁止により enabled が必要
    enableAutoRecharge($organization);
    $this->gateway->payAmountPaid = $attempt->quantity * $attempt->unit_amount;

    $stats = app(AutoRechargeService::class)->reconcile();

    expect($stats['retried'])->toBe(1);
    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
});

test('(i) 15 分未満の pending は再実行しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRechargeAttempt::factory()->for($organization)->create([
        'created_at' => CarbonImmutable::now()->subMinutes(3),
    ]);

    $stats = app(AutoRechargeService::class)->reconcile();

    expect($stats['retried'])->toBe(0);
});

test('(ii) Stripe 上 paid だが webhook 未着なら付与を回収する (terminal drop の唯一の救済)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $attempt = TicketAutoRechargeAttempt::factory()
        ->for($organization)
        ->withInvoice('in_recovered')
        ->create(['quantity' => 40, 'unit_amount' => 70]);

    $this->gateway->invoiceState = new InvoiceStateDto('paid', 40 * 70, 40 * 70, 'pi_recovered', false, null);

    $stats = app(AutoRechargeService::class)->reconcile();

    expect($stats['recovered_paid'])->toBe(1);
    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_recovered')->count())->toBe(1);
});

test('(iii) SCA 待ちは日次リマインダを送り、同日 2 回目は dedup で送られない', function (): void {
    [$organization] = createOrganizationWithOwner();
    TicketAutoRechargeAttempt::factory()
        ->for($organization)
        ->withInvoice('in_sca_pending')
        ->create(['failure_code' => 'authentication_required']);

    $this->gateway->invoiceState = new InvoiceStateDto('open', 0, 2800, 'pi_sca', true, 'https://invoice.stripe.test/i/in_sca_pending');

    $first = app(AutoRechargeService::class)->reconcile();
    expect($first['sca_reminded'])->toBe(1);

    $second = app(AutoRechargeService::class)->reconcile();
    expect($second['sca_reminded'])->toBe(1); // 呼び出しはされるが…

    // …通知台帳は同日 1 件のまま (dedup key = JST date bucket)
    expect(
        BillingNotification::query()
            ->where('organization_id', $organization->id)
            ->where('type', BillingNotificationType::AutoRechargeActionRequired->value)
            ->count(),
    )->toBe(1);
});

test('(iv) 期限切れ — SCA は failed、それ以外は canceled になる', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');
    $expiryHours = config()->integer('billing.auto_recharge.pending_expiry_hours');

    $scaAttempt = TicketAutoRechargeAttempt::factory()
        ->for($organizationA)
        ->withInvoice('in_expired_sca')
        ->create([
            'failure_code' => 'authentication_required',
            'created_at' => CarbonImmutable::now()->subHours($expiryHours + 1),
        ]);
    $draftAttempt = TicketAutoRechargeAttempt::factory()
        ->for($organizationB)
        ->withInvoice('in_expired_draft')
        ->create(['created_at' => CarbonImmutable::now()->subHours($expiryHours + 1)]);

    $this->gateway->invoiceStatuses = ['in_expired_sca' => 'open', 'in_expired_draft' => 'draft'];

    $stats = app(AutoRechargeService::class)->reconcile();

    expect($stats['expired'])->toBe(2);
    expect($scaAttempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Failed);
    expect($draftAttempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Canceled);
});

test('(v) enabled + 閾値割れ + pending なしで取りこぼしを起票する', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    enableAutoRecharge($organization);

    $stats = app(AutoRechargeService::class)->reconcile();

    expect($stats['triggered'])->toBe(1);
    expect(TicketAutoRechargeAttempt::query()->where('organization_id', $organization->id)->count())->toBe(1);
});

test('既定 off の組織はリコンサイルでも一切起票されない', function (): void {
    [$organization] = createOrganizationWithOwner();

    $stats = app(AutoRechargeService::class)->reconcile();

    expect($stats['triggered'])->toBe(0);
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
});

test('1 attempt の例外が他 org の回収を止めない (隔離)', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB] = createOrganizationWithOwner('組織B');

    // A: amount 不一致で例外になる paid invoice
    TicketAutoRechargeAttempt::factory()->for($organizationA)->withInvoice('in_bad')->create([
        'quantity' => 10, 'unit_amount' => 100,
    ]);
    // B: 正常に回収できる paid invoice
    $good = TicketAutoRechargeAttempt::factory()->for($organizationB)->withInvoice('in_good')->create([
        'quantity' => 40, 'unit_amount' => 70,
    ]);

    // amount 不一致 (in_bad) は例外になり、正常な in_good は回収される
    $this->gateway->invoiceStates = [
        'in_bad' => new InvoiceStateDto('paid', 1, 1, 'pi_bad', false, null),
        'in_good' => new InvoiceStateDto('paid', 40 * 70, 40 * 70, 'pi_good', false, null),
    ];

    $stats = app(AutoRechargeService::class)->reconcile();

    expect($stats['recovered_paid'])->toBe(1);
    expect($good->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
});

test('リコンサイルコマンドは 0 で終了し統計を出力する', function (): void {
    $this->artisan('billing:reconcile-auto-recharge')
        ->expectsOutputToContain('auto-recharge reconcile:')
        ->assertExitCode(0);
});

test('D20: scheduler に 15 分毎で登録されている (監視 DoD の回帰)', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'billing:reconcile-auto-recharge'));

    expect($events)->toHaveCount(1);
    expect($events->firstOrFail()->getExpression())->toBe('*/15 * * * *');
});

/** テスト用にオートリチャージを直接有効化する (PM 注入 + 同意記録)。 */
function enableAutoRecharge(Organization $organization): void
{
    $owner = $organization->users()->firstOrFail();
    /** @var FakeAutoRechargeGateway $gateway */
    $gateway = app(AutoRechargeGatewayInterface::class);
    $gateway->withDefaultPaymentMethod();

    app(AutoRechargeService::class)->updateSettings(
        $organization,
        $owner,
        enabled: true,
        threshold: 5,
        max: 50,
        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
    );
}
