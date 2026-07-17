<?php

declare(strict_types=1);

use App\Jobs\Billing\SyncBillingCustomerDetails;
use App\Services\Billing\BillingCustomerSynchronizer;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeStripeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * BillingCustomerSynchronizer: Stripe customer 同期 job の dispatch を集約する単一窓口。
 * - Stripe customer 未作成 (stripe_id === null) は no-op (例外にしない)
 * - dispatch は afterCommit (transaction rollback では発火しない)
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

/*
 * IV-3 (commit 前の stale read を防ぐ) の固定。job が afterCommit フラグを立てて積まれることを
 * 検証する。「rollback では発火しない」という実挙動そのものは Queue::fake では観測できない
 * (QueueFake は afterCommit を解決する Queue::enqueueUsing を経由せず即時記録するため)。
 * afterCommit フラグ = 実 queue driver における「outer commit 後に発火」の唯一の入力。
 */
test('dispatch した job は afterCommit フラグを持つ (outer commit 後に発火する)', function (): void {
    Queue::fake();
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_test_2'])->save();

    DB::transaction(fn () => synchronizer()->dispatchFor($organization));

    Queue::assertPushed(
        SyncBillingCustomerDetails::class,
        fn (SyncBillingCustomerDetails $job): bool => $job->afterCommit === true,
    );
});

test('job は StripeGatewayInterface へ委譲する (fake bind 時は実 Stripe を叩かない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['stripe_id' => 'cus_test_3'])->save();
    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);

    // fake gateway の syncCustomerDetails は no-op。例外なく完走することを固定する
    (new SyncBillingCustomerDetails($organization))->handle(app(StripeGatewayInterface::class));
})->throwsNoExceptions();
