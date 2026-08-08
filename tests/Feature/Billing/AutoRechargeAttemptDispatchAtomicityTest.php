<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAutoRechargeGateway;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
|--------------------------------------------------------------------------
| キュー投入の原子性 (attempt 起票 → ExecuteAutoRechargeAttemptJob。AG-114 確定 1)
|--------------------------------------------------------------------------
|
| 旧実装は呼び出し側 (AutoRechargeTriggerJob::handle / reconcile (v)) が起票 tx の
| 成功後に dispatch していたため「attempt=pending・実行未投入」の窓があり、
| reconcile (v) の 15 分周期に依存していた。投入は起票と同一 tx へ移す。
*/

beforeEach(function (): void {
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
});

/**
 * 閾値割れの enabled 組織を用意する。
 *
 * @return array{Organization, User}
 */
function attemptAtomicityContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
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

    return [$organization, $owner];
}

test('attempt 起票と ExecuteAutoRechargeAttemptJob の投入は同一 tx である', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database.after_commit'))->toBeFalse();

    [$organization] = attemptAtomicityContext();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(AutoRechargeService::class)->maybeCreateAttempt($organization),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), ExecuteAutoRechargeAttemptJob::class);

    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('起票 tx が rollback すると attempt 行も jobs 行も残らない', function (): void {
    config()->set('queue.default', 'database');
    [$organization] = attemptAtomicityContext();
    $jobsBefore = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($organization): void {
            app(AutoRechargeService::class)->maybeCreateAttempt($organization);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe($jobsBefore);
});
