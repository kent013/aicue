<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Models\Billing\TicketAutoRecharge;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\TicketLedgerService;
use App\Services\Notification\NotificationCenterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAutoRechargeGateway;

/*
 * P8a の AI-CUE 固有の要: トリガ点は commit ではなく **reserve**。
 *
 * AI-CUE の balance() は reserve で減り commit では不変 (拘束 −amount と台帳 −amount が相殺) の
 * ため、閾値クロスを取り逃さない唯一の点が reserve。既存の低残高通知と同居させる
 * (parity の名で既存機能を後退させない)。
 */

beforeEach(function (): void {
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
});

test('reserve で AutoRechargeTriggerJob が dispatch される', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');

    app(TicketLedgerService::class)->reserve($organization, 1);

    Queue::assertPushed(
        AutoRechargeTriggerJob::class,
        fn (AutoRechargeTriggerJob $job): bool => $job->organizationId === $organization->id,
    );
});

test('既存の低残高通知が消えていない (parity の名での機能後退を防ぐ回帰)', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();

    $notifications = Mockery::mock(NotificationCenterService::class);
    $notifications->shouldReceive('notifyTicketBalanceLow')->once();
    app()->instance(NotificationCenterService::class, $notifications);

    $threshold = config()->integer('billing.ticket_low_balance_threshold');
    app(TicketLedgerService::class)->grant($organization, $threshold, '閾値ちょうど');

    // 閾値以上 → 閾値未満 のクロス
    app(TicketLedgerService::class)->reserve($organization, 1);

    // 低残高通知とオートリチャージ trigger が同居している
    Queue::assertPushed(AutoRechargeTriggerJob::class);
});

test('commit では dispatch されない (balance 不変のため)', function (): void {
    [$organization] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
    $reservation = app(TicketLedgerService::class)->reserve($organization, 1);

    // reserve 後に fake すると commit 由来の dispatch のみを観測できる
    Queue::fake();
    app(TicketLedgerService::class)->commit($reservation);

    Queue::assertNotPushed(AutoRechargeTriggerJob::class);
});

test('reserve が rollback したら dispatch されない (afterCommit の保証)', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');

    try {
        DB::transaction(function () use ($organization): void {
            app(TicketLedgerService::class)->reserve($organization, 1);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    Queue::assertNotPushed(AutoRechargeTriggerJob::class);
});

test('amount ベース reserve (可変コスト) が壊れていない', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');

    $reservation = app(TicketLedgerService::class)->reserve($organization, 7);

    expect($reservation->amount)->toBe(7);
    expect(app(TicketLedgerService::class)->availableTrueBalance($organization))->toBe(3);
});

test('reserve→commit/release の 2 フェーズが維持されている', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    $ledger = app(TicketLedgerService::class);
    $ledger->grant($organization, 10, '初期付与');

    $committed = $ledger->reserve($organization, 2);
    $ledger->commit($committed);
    expect($ledger->availableTrueBalance($organization))->toBe(8);

    $released = $ledger->reserve($organization, 3);
    $ledger->release($released);
    expect($ledger->availableTrueBalance($organization))->toBe(8);
});

test('設定行が無い組織では TriggerJob が即 return する (既定 off の回帰)', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();

    (new AutoRechargeTriggerJob($organization->id))->handle(app(AutoRechargeService::class));

    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
    Queue::assertNotPushed(ExecuteAutoRechargeAttemptJob::class);
});

test('enabled な組織では TriggerJob が attempt を起票し ExecuteJob を dispatch する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    enableAutoRechargeFor($organization, $owner, $this->gateway);

    Queue::fake();
    (new AutoRechargeTriggerJob($organization->id))->handle(app(AutoRechargeService::class));

    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
    Queue::assertPushed(ExecuteAutoRechargeAttemptJob::class);
});

/** テスト用にオートリチャージを有効化する (default PM を注入してから updateSettings)。 */
function enableAutoRechargeFor(
    Organization $organization,
    User $owner,
    FakeAutoRechargeGateway $gateway,
    int $threshold = 5,
    int $max = 50,
): TicketAutoRecharge {
    $gateway->withDefaultPaymentMethod();

    return app(AutoRechargeService::class)->updateSettings(
        $organization,
        $owner,
        enabled: true,
        threshold: $threshold,
        max: $max,
        consent: new AutoRechargeConsentDto(
            config()->string('billing.auto_recharge.consent_version'),
        ),
    );
}
