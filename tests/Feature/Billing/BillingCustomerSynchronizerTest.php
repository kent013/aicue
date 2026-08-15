<?php

declare(strict_types=1);

use App\Actions\Billing\UpdateBillingContactAction;
use App\Actions\Organizations\RenameOrganizationAction;
use App\DataTransferObjects\Billing\UpdateBillingContactData;
use App\Jobs\Billing\SyncBillingCustomerDetails;
use App\Services\Billing\BillingCustomerSynchronizer;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
 * BillingCustomerSynchronizer: Stripe customer 同期 job の dispatch を集約する単一窓口。
 * - Stripe customer 未作成 (stripe_id === null) は no-op (例外にしない)
 * - dispatch は業務 tx の内側 (jobs 行が同一 tx に乗り、rollback では jobs 行ごと巻き戻る)
 */

function synchronizer(): BillingCustomerSynchronizer
{
    return app(BillingCustomerSynchronizer::class);
}

test('stripe_id が null の組織では job を dispatch しない (no-op)', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    expect($organization->stripe_id)->toBeNull();

    DB::transaction(fn () => synchronizer()->dispatchFor($organization));

    Queue::assertNothingPushed();
});

test('stripe_id を持つ組織では SyncBillingCustomerDetails を対象組織付きで dispatch する', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_test_1'])->save();

    DB::transaction(fn () => synchronizer()->dispatchFor($organization));

    Queue::assertPushed(
        SyncBillingCustomerDetails::class,
        fn (SyncBillingCustomerDetails $job): bool => $job->organization->is($organization),
    );
});

/**
 * 【契約の反転 (AG-114 確定 1 / T137)】
 * - 旧主張: dispatch した job は afterCommit フラグを持つ (outer commit 後に発火する)
 * - 旧目的: commit 前の値を Stripe へ送る stale read を防ぐ (IV-3)
 * - 新主張: job は afterCommit フラグを**持たない**。投入は業務 tx に乗る
 * - 新前提: jobs 行が業務 tx と同一トランザクションにあるため、可視化は commit 後になる
 * - 前提を守る機構: QueueDispatchAtomicityGuard (R1〜R3) + QueueDispatchAtomicityInventoryTest (D1)
 * - 反転根拠: afterCommit は「commit したのに未投入」の窓を残す。job は organization を
 *   ID で直列化して handle 時に再取得するため、IV-3 は afterCommit なしで保たれる
 */
test('dispatch した job は afterCommit フラグを持たない (業務 tx に乗る)', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_test_2'])->save();

    DB::transaction(fn () => synchronizer()->dispatchFor($organization));

    Queue::assertPushed(
        SyncBillingCustomerDetails::class,
        fn (SyncBillingCustomerDetails $job): bool => $job->afterCommit !== true,
    );
});

/**
 * 実挙動 (jobs 行が業務 tx に乗ること) は **実 jobs 表**で観測する。
 * `Queue::fake()` では検証できない (QueueFake::push は enqueueUsing を通らない)。
 */
test('外側 tx が rollback すると jobs 行が残らない', function (): void {
    config()->set('queue.default', 'database');
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_test_rollback'])->save();
    $before = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($organization): void {
            synchronizer()->dispatchFor($organization);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(DB::table('jobs')->count())->toBe($before);
});

/**
 * **主契約** (移設を検出するのはこれだけ)。実際の呼び出し元 (RenameOrganizationAction) 経由で
 * `JobQueueing` の tx level を観測する。呼び出し元が tx の外へ移動したら赤くなる。
 * rollback テストは補助であり、旧実装 (afterCommit) でも緑になるため移設の検出には使えない。
 */
test('実呼び出し元 (RenameOrganizationAction) 経由でも SyncBillingCustomerDetails は業務 tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database.after_commit'))->toBeFalse();

    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_test_txlevel'])->save();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(RenameOrganizationAction::class)->execute($organization, '新しい組織名'),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), SyncBillingCustomerDetails::class);

    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

/**
 * **主契約 (2 経路目)**。`dispatchFor()` の呼び出し元は **現時点で確認済みの 2 本**
 * (`RenameOrganizationAction` / `UpdateBillingContactAction`) で、どちらか一方だけを観測すると
 * 他方が tx の外へ移動しても緑のままになるため両方を固定する。
 *
 * ★ **保証範囲を誇張しない**: `BillingSyncDispatchInvariantTest` が閉じているのは
 *   「`SyncBillingCustomerDetails::dispatch` を書けるのは `BillingCustomerSynchronizer` だけ」で
 *   あって、**`dispatchFor()` の呼び出し元が 2 本であること**ではない。第 3 の呼び出し元が
 *   増えても機械的には検出されない (設計 §保証しないもの 11「dispatch が業務 tx の内側に
 *   あることの静的完全性は保証しない」と同じ性質)。
 */
test('実呼び出し元 (UpdateBillingContactAction) 経由でも SyncBillingCustomerDetails は業務 tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');

    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_test_txlevel_2'])->save();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(UpdateBillingContactAction::class)->execute(
            $organization,
            new UpdateBillingContactData('billing-new@example.com', '請求 太郎'),
        ),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), SyncBillingCustomerDetails::class);

    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('業務 tx の内側で dispatch した job は commit 後に jobs 行として残る', function (): void {
    config()->set('queue.default', 'database');
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_test_commit'])->save();
    $before = DB::table('jobs')->count();

    DB::transaction(fn () => synchronizer()->dispatchFor($organization));

    expect(DB::table('jobs')->count())->toBe($before + 1);
});

test('job は StripeGatewayInterface へ委譲する (fake bind 時は実 Stripe を叩かない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_test_3'])->save();
    enableFakeExternals();

    // fake gateway の syncCustomerDetails は no-op。例外なく完走することを固定する
    (new SyncBillingCustomerDetails($organization))->handle(app(StripeGatewayInterface::class));
})->throwsNoExceptions();
