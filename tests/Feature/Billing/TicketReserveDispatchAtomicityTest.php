<?php

declare(strict_types=1);

use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Models\Billing\TicketReservation;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
|--------------------------------------------------------------------------
| キュー投入の原子性 (reserve → AutoRechargeTriggerJob。AG-114 確定 1)
|--------------------------------------------------------------------------
|
| ★ 本ファイルは **reserve() 内の経路専用**である。
|   AutoRechargeAttemptDispatchAtomicityTest は createAttemptLocked() 内の**別ジョブ**
|   (ExecuteAutoRechargeAttemptJob) を見ているため、この経路の変異を検出できない。
*/

test('reserve の AutoRechargeTriggerJob は業務 tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database.after_commit'))->toBeFalse();

    [$organization] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(TicketLedgerService::class)->reserve($organization, 1),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), AutoRechargeTriggerJob::class);

    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('reserve が rollback すると予約行も jobs 行も残らない', function (): void {
    config()->set('queue.default', 'database');
    [$organization] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
    $jobsBefore = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($organization): void {
            app(TicketLedgerService::class)->reserve($organization, 1);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(TicketReservation::query()->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe($jobsBefore);
});
