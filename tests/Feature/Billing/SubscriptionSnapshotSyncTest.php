<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Enums\Billing\ScheduleSetupStatus;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\StripeWebhookProcessor;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\SubscriptionSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Events\WebhookReceived;
use Webmozart\Assert\Assert;

/*
 * SubscriptionService::applySubscriptionSnapshot / recordPaymentMethodSnapshot。
 *
 * - applySubscriptionSnapshot: webhook 受信時の唯一の状態書込経路。
 *   organizations.plan_code は「base Price が解決でき かつ status が active/trialing」の
 *   ときだけ同期し、terminated では null に戻す。**subscriptions 行は作らない**
 *   (行の作成権威は Cashier の WebhookController)。
 * - recordPaymentMethodSnapshot: has_payment_method の独立 monotonic writer
 *   (true → false に戻さない / 行不在は早期 return)。
 */

function snapshotSyncService(): SubscriptionService
{
    return app(SubscriptionService::class);
}

/** PlanSeeder が投入した standard プラン現行 base Price の Stripe Price ID */
function snapshotSyncStandardPriceId(): string
{
    $price = Plan::query()->where('code', 'standard')->firstOrFail()
        ->currentPrice(PlanPriceKind::Base);
    Assert::notNull($price, 'standard プランの current base price が未 seed');

    return $price->stripe_price_id;
}

function snapshotSyncOrganization(): Organization
{
    [$organization] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_snapshot_1';
    $organization->save();

    return $organization;
}

/**
 * @param  string|null  $basePriceId  null なら standard の現行 base price
 */
function snapshotSyncSnapshot(
    string $status = 'active',
    ?string $basePriceId = null,
    ?int $quantity = 1,
    ?CarbonImmutable $currentPeriodEnd = null,
    ?CarbonImmutable $trialEndsAt = null,
    ?CarbonImmutable $endsAt = null,
    string $stripeId = 'sub_snapshot_1',
): SubscriptionSnapshot {
    return new SubscriptionSnapshot(
        stripeId: $stripeId,
        status: $status,
        basePriceId: $basePriceId ?? snapshotSyncStandardPriceId(),
        baseQuantity: $quantity,
        currentPeriodEnd: $currentPeriodEnd,
        trialEndsAt: $trialEndsAt,
        endsAt: $endsAt,
    );
}

test('active + 既知 base price は plan_code を同期し subscription 行の Stripe 由来列を更新する', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization, status: 'incomplete');
    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();

    $periodEnd = CarbonImmutable::now()->addMonthNoOverflow()->startOfSecond();
    $trialEnd = CarbonImmutable::now()->addDays(3)->startOfSecond();

    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(
        status: 'trialing',
        quantity: 3,
        currentPeriodEnd: $periodEnd,
        trialEndsAt: $trialEnd,
    ));

    expect($organization->fresh()?->plan_code)->toBe('standard');

    $fresh = $subscription->fresh();
    Assert::isInstanceOf($fresh, Subscription::class);
    expect($fresh->stripe_status)->toBe('trialing')
        ->and($fresh->stripe_price)->toBe(snapshotSyncStandardPriceId())
        ->and($fresh->quantity)->toBe(3)
        ->and($fresh->current_period_end?->equalTo($periodEnd))->toBeTrue()
        ->and($fresh->trial_ends_at?->equalTo($trialEnd))->toBeTrue()
        ->and($fresh->ends_at)->toBeNull();
});

test('未知の Price は受理のみ (plan_code を同期しない) で Stripe 列だけ更新する', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization, status: 'incomplete');
    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();

    snapshotSyncService()->applySubscriptionSnapshot(
        $organization,
        snapshotSyncSnapshot(basePriceId: 'price_unknown_xyz'),
    );

    expect($organization->fresh()?->plan_code)->toBeNull()
        ->and($subscription->fresh()?->stripe_status)->toBe('active');
});

test('非 active 系 status は plan_code を同期しない (既存値を維持する)', function (string $status): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization, status: 'active');
    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();

    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: $status));

    expect($organization->fresh()?->plan_code)->toBeNull()
        ->and($subscription->fresh()?->stripe_status)->toBe($status);
})->with(['past_due', 'paused', 'canceled', 'incomplete']);

test('terminated は plan_code を解除し schedule ライフサイクル列を同一 TX でクリアする', function (): void {
    $organization = snapshotSyncOrganization();
    $organization->forceFill(['plan_code' => 'standard'])->save();
    $subscription = createFakeSubscription($organization, status: 'active');
    $subscription->forceFill([
        'stripe_id' => 'sub_snapshot_1',
        'stripe_schedule_id' => 'sub_sched_1',
        'schedule_setup_status' => ScheduleSetupStatus::Created,
    ])->save();

    $endedAt = CarbonImmutable::now()->startOfSecond();

    snapshotSyncService()->applySubscriptionSnapshot(
        $organization,
        snapshotSyncSnapshot(status: 'canceled', endsAt: $endedAt),
        terminated: true,
    );

    expect($organization->fresh()?->plan_code)->toBeNull();

    $fresh = $subscription->fresh();
    Assert::isInstanceOf($fresh, Subscription::class);
    expect($fresh->stripe_status)->toBe('canceled')
        ->and($fresh->stripe_schedule_id)->toBeNull()
        ->and($fresh->schedule_setup_status)->toBe(ScheduleSetupStatus::None)
        ->and($fresh->ends_at?->equalTo($endedAt))->toBeTrue();
});

test('subscription 行が無くても行を作らない (作成権威は Cashier の WebhookController)', function (): void {
    $organization = snapshotSyncOrganization();

    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot());

    // plan_code の同期は行の有無に依らず走る (行の materialize は Cashier に委ねる)
    expect($organization->fresh()?->plan_code)->toBe('standard')
        ->and(Subscription::query()->count())->toBe(0);
});

test('period 欠落 snapshot は既存の current_period_end を維持する (reminder の真実源を壊さない)', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization);
    $existingPeriodEnd = CarbonImmutable::now()->addMonthNoOverflow()->startOfSecond();
    $subscription->forceFill([
        'stripe_id' => 'sub_snapshot_1',
        'current_period_end' => $existingPeriodEnd,
    ])->save();

    snapshotSyncService()->applySubscriptionSnapshot(
        $organization,
        snapshotSyncSnapshot(currentPeriodEnd: null),
    );

    expect($subscription->fresh()?->current_period_end?->equalTo($existingPeriodEnd))->toBeTrue();
});

test('recordPaymentMethodSnapshot は false → true へ昇格させる', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization);
    $subscription->forceFill(['has_payment_method' => false])->save();

    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, true);

    expect($subscription->fresh()?->has_payment_method)->toBeTrue();
});

test('recordPaymentMethodSnapshot は monotonic (true → false に戻さない)', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization);
    $subscription->forceFill(['has_payment_method' => true])->save();

    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, false);

    expect($subscription->fresh()?->has_payment_method)->toBeTrue();
});

test('recordPaymentMethodSnapshot は PM 無しのまま false を渡しても no-op', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization);
    $subscription->forceFill(['has_payment_method' => false])->save();

    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, false);

    expect($subscription->fresh()?->has_payment_method)->toBeFalse();
});

test('recordPaymentMethodSnapshot は行不在なら早期 return (例外を投げない)', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization);
    $subscriptionId = $subscription->id;
    // Cashier が行を作る前 / 削除後の instance を模す
    Subscription::query()->whereKey($subscriptionId)->delete();

    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, true);

    expect(Subscription::query()->whereKey($subscriptionId)->exists())->toBeFalse();
});

test('recordPaymentMethodSnapshot は transaction 内で行を lockForUpdate してから書く', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization);
    $subscription->forceFill(['has_payment_method' => false])->save();

    /** @var list<string> $queries */
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    // DB::listen と同じ (connection が保持する) dispatcher に直接登録する。
    // Event::fake は container の binding だけを差し替えるため connection には届かない。
    $began = 0;
    $dispatcher = DB::connection()->getEventDispatcher();
    Assert::isInstanceOf($dispatcher, Dispatcher::class);
    $dispatcher->listen(TransactionBeginning::class, function () use (&$began): void {
        $began++;
    });

    snapshotSyncService()->recordPaymentMethodSnapshot($subscription, true);

    // RefreshDatabase の外側 TX 内では savepoint として開始される (level が上がる)
    expect($began)->toBeGreaterThan(0);

    $locking = array_values(array_filter(
        $queries,
        fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
    ));
    expect($locking)->not->toBeEmpty()
        ->and(strtolower($locking[0]))->toContain('from "subscriptions"');
});

test('customer.subscription.updated は snapshot 同期と PM 記録を配線する', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization, status: 'incomplete');
    $subscription->forceFill([
        'stripe_id' => 'sub_wired_1',
        'has_payment_method' => false,
    ])->save();

    event(new WebhookReceived([
        'id' => 'evt_wired_updated_1',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_wired_1',
                'customer' => 'cus_snapshot_1',
                'status' => 'active',
                'default_payment_method' => 'pm_test_1',
                'items' => [
                    'data' => [[
                        'price' => ['id' => snapshotSyncStandardPriceId()],
                        'quantity' => 1,
                        'current_period_end' => CarbonImmutable::now()->addMonthNoOverflow()->getTimestamp(),
                    ]],
                ],
            ],
        ],
    ]));

    $fresh = $subscription->fresh();
    Assert::isInstanceOf($fresh, Subscription::class);
    expect($organization->fresh()?->plan_code)->toBe('standard')
        ->and($fresh->stripe_status)->toBe('active')
        ->and($fresh->has_payment_method)->toBeTrue()
        ->and($fresh->current_period_end)->not->toBeNull();
});

test('default_source だけでも PM 有りと判定する (expanded object も id を拾う)', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization);
    $subscription->forceFill(['stripe_id' => 'sub_wired_2', 'has_payment_method' => false])->save();

    event(new WebhookReceived([
        'id' => 'evt_wired_updated_2',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_wired_2',
                'customer' => 'cus_snapshot_1',
                'status' => 'active',
                'default_source' => ['id' => 'card_test_1'],
                'items' => ['data' => [['price' => ['id' => snapshotSyncStandardPriceId()]]]],
            ],
        ],
    ]));

    expect($subscription->fresh()?->has_payment_method)->toBeTrue();
});

test('PM 情報を含まない customer.subscription.updated は has_payment_method を false に戻さない', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization);
    $subscription->forceFill(['stripe_id' => 'sub_wired_3', 'has_payment_method' => true])->save();

    event(new WebhookReceived([
        'id' => 'evt_wired_updated_3',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_wired_3',
                'customer' => 'cus_snapshot_1',
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => snapshotSyncStandardPriceId()]]]],
            ],
        ],
    ]));

    expect($subscription->fresh()?->has_payment_method)->toBeTrue();
});

test('customer.subscription.created で行がまだ無くても例外にならず行も作らない', function (): void {
    $organization = snapshotSyncOrganization();

    // Cashier の WebhookController より先に走る WebhookReceived listener を直接叩く
    app(StripeWebhookProcessor::class)->handle(new WebhookReceived([
        'id' => 'evt_wired_created_1',
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_wired_4',
                'customer' => 'cus_snapshot_1',
                'status' => 'active',
                'default_payment_method' => 'pm_test_1',
                'items' => ['data' => [['price' => ['id' => snapshotSyncStandardPriceId()]]]],
            ],
        ],
    ]));

    expect(Subscription::query()->count())->toBe(0)
        ->and($organization->fresh()?->plan_code)->toBe('standard');
});

// ── 猶予の起点 (past_due_since) の打刻 — 唯一の writer は applySubscriptionSnapshot ──

test('active → past_due の観測で past_due_since が観測時刻で打たれる', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization, status: 'active');
    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();

    $this->travelTo(CarbonImmutable::parse('2026-08-15 10:00:00'));
    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'past_due'));

    expect($subscription->fresh()?->past_due_since?->toDateTimeString())->toBe('2026-08-15 10:00:00');
});

test('past_due の再送では起点を上書きしない (猶予を先送りしない)', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization, status: 'active');
    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();

    $this->travelTo(CarbonImmutable::parse('2026-08-15 10:00:00'));
    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'past_due'));

    $this->travelTo(CarbonImmutable::parse('2026-08-18 10:00:00'));
    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'past_due'));

    expect($subscription->fresh()?->past_due_since?->toDateTimeString())->toBe('2026-08-15 10:00:00');
});

test('past_due → active の復旧で起点が NULL に戻る', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization, status: 'past_due');
    $subscription->forceFill([
        'stripe_id' => 'sub_snapshot_1',
        'past_due_since' => CarbonImmutable::parse('2026-08-01 10:00:00'),
    ])->save();

    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'active'));

    expect($subscription->fresh()?->past_due_since)->toBeNull();
});

test('契約終了 (terminated) でも起点が NULL に戻る', function (): void {
    $organization = snapshotSyncOrganization();
    $subscription = createFakeSubscription($organization, status: 'past_due');
    $subscription->forceFill([
        'stripe_id' => 'sub_snapshot_1',
        'past_due_since' => CarbonImmutable::parse('2026-08-01 10:00:00'),
    ])->save();

    snapshotSyncService()->applySubscriptionSnapshot(
        $organization,
        snapshotSyncSnapshot(status: 'canceled'),
        terminated: true,
    );

    expect($subscription->fresh()?->past_due_since)->toBeNull();
});

test('subscription 行が無い間の past_due 観測は no-op (行を作らない)', function (): void {
    $organization = snapshotSyncOrganization();

    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'past_due'));

    expect(Subscription::query()->count())->toBe(0);
});
