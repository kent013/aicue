<?php

declare(strict_types=1);

use App\Console\Commands\Billing\ReconcileSubscriptionStatus;
use App\DataTransferObjects\Billing\RemoteSubscriptionState;
use App\Models\Billing\Subscription;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\SubscriptionSnapshot;
use App\Support\ExternalClientTimeouts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\FakeStripeGateway;

/*
 * billing:reconcile-subscription-status — Stripe を真実として契約状態を収束させる日次バッチ。
 *
 * 責務は「applySubscriptionSnapshot が書く列」だけで、金銭 (チケット) には触れない。
 * 未確認 (404) は状態を変えずに報告し、照会失敗は FAILURE で終わる。
 */

/** fake gateway を bind し、spy を返す。 */
function reconcileGateway(): FakeStripeGateway
{
    $gateway = new FakeStripeGateway;
    app()->instance(StripeGatewayInterface::class, $gateway);

    return $gateway;
}

/** report() 経路 (運用アラート) を観測する spy を差し込む。 */
function reconcileHandlerSpy(): MockInterface
{
    $handler = Mockery::spy(ExceptionHandler::class);
    app()->instance(ExceptionHandler::class, $handler);

    return $handler;
}

/** 突き合わせ対象の契約 1 件 (Stripe には到達しない)。 */
function reconcileSubscription(string $status = 'active', ?CarbonImmutable $pastDueSince = null): Subscription
{
    $organization = Organization::factory()->create();
    $subscription = createFakeSubscription($organization, status: $status);
    $subscription->forceFill(['past_due_since' => $pastDueSince])->save();

    return $subscription;
}

/** ローカル行と同じ形の remote 観測 (差分なし) を作る。 */
function reconcileRemote(
    Subscription $sub,
    ?string $status = null,
    ?string $basePriceId = null,
    ?int $quantity = 1,
    ?CarbonImmutable $currentPeriodEnd = null,
    ?CarbonImmutable $trialEndsAt = null,
    ?CarbonImmutable $endsAt = null,
    ?bool $hasPaymentMethod = null,
): RemoteSubscriptionState {
    return new RemoteSubscriptionState(
        snapshot: new SubscriptionSnapshot(
            stripeId: $sub->stripe_id,
            status: $status ?? $sub->stripe_status,
            basePriceId: $basePriceId,
            baseQuantity: $quantity,
            currentPeriodEnd: $currentPeriodEnd,
            trialEndsAt: $trialEndsAt,
            endsAt: $endsAt,
        ),
        hasPaymentMethod: $hasPaymentMethod,
    );
}

test('remote が past_due ならローカルを past_due にし猶予起点を打つ', function (): void {
    $gateway = reconcileGateway();
    $sub = reconcileSubscription(status: 'active');
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, status: 'past_due');

    $this->travelTo(CarbonImmutable::parse('2026-08-15 10:00:00'));
    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);

    $fresh = $sub->fresh();
    expect($fresh?->stripe_status)->toBe('past_due')
        ->and($fresh?->past_due_since?->toDateTimeString())->toBe('2026-08-15 10:00:00');
});

test('remote が active に戻っていればローカルも戻り猶予起点が消える', function (): void {
    $gateway = reconcileGateway();
    $sub = reconcileSubscription(status: 'past_due', pastDueSince: CarbonImmutable::now()->subDays(3));
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, status: 'active');

    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);

    $fresh = $sub->fresh();
    expect($fresh?->stripe_status)->toBe('active')
        ->and($fresh?->past_due_since)->toBeNull();
});

test('past_due のまま起点が NULL の行は打刻漏れとして修復される', function (): void {
    $gateway = reconcileGateway();
    $sub = reconcileSubscription(status: 'past_due', pastDueSince: null);
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, status: 'past_due');

    $this->travelTo(CarbonImmutable::parse('2026-08-20 08:00:00'));
    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);

    expect($sub->fresh()?->past_due_since?->toDateTimeString())->toBe('2026-08-20 08:00:00');
});

test('差分が無ければ 1 列も書かない (無駄な UPDATE をしない)', function (): void {
    $gateway = reconcileGateway();
    $sub = reconcileSubscription(status: 'active');
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub);

    $before = DB::table('subscriptions')->where('id', $sub->getKey())->value('updated_at');
    $this->travelTo(CarbonImmutable::now()->addHour());
    $this->artisan('billing:reconcile-subscription-status')
        ->expectsOutputToContain('checked=1 converged=0')
        ->assertExitCode(0);

    expect(DB::table('subscriptions')->where('id', $sub->getKey())->value('updated_at'))->toBe($before);
});

test('status 以外の差分も収束する (更新予告の真実源がずれたまま固まらない)', function (): void {
    $gateway = reconcileGateway();
    $sub = reconcileSubscription(status: 'active');
    $periodEnd = CarbonImmutable::parse('2026-09-01 00:00:00');
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, currentPeriodEnd: $periodEnd);

    $this->artisan('billing:reconcile-subscription-status')
        ->expectsOutputToContain('converged=1')
        ->assertExitCode(0);

    expect($sub->fresh()?->current_period_end?->toDateTimeString())->toBe('2026-09-01 00:00:00');
});

test('quantity / ends_at の差分も収束する', function (): void {
    $gateway = reconcileGateway();
    $sub = reconcileSubscription(status: 'active');
    $endsAt = CarbonImmutable::parse('2026-09-30 00:00:00');
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, quantity: 3, endsAt: $endsAt);

    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);

    $fresh = $sub->fresh();
    expect($fresh?->quantity)->toBe(3)
        ->and($fresh?->ends_at?->toDateTimeString())->toBe('2026-09-30 00:00:00');
});

test('remote の period 欠落ではローカルの current_period_end を消さない', function (): void {
    $gateway = reconcileGateway();
    $sub = reconcileSubscription(status: 'active');
    $sub->forceFill(['current_period_end' => CarbonImmutable::parse('2026-09-01 00:00:00')])->save();
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, currentPeriodEnd: null);

    $this->artisan('billing:reconcile-subscription-status')
        ->expectsOutputToContain('converged=0')
        ->assertExitCode(0);

    expect($sub->fresh()?->current_period_end?->toDateTimeString())->toBe('2026-09-01 00:00:00');
});

test('決済手段の観測は true 方向だけ書く (null 観測で false へ戻さない)', function (): void {
    $gateway = reconcileGateway();
    $sub = reconcileSubscription(status: 'active');
    expect($sub->fresh()?->has_payment_method)->toBeFalse();

    // 観測できなかった (null) → 書かない
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, hasPaymentMethod: null);
    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
    expect($sub->fresh()?->has_payment_method)->toBeFalse();

    // 観測できた (true) → 書く
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, hasPaymentMethod: true);
    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
    expect($sub->fresh()?->has_payment_method)->toBeTrue();

    // 一度 true になった行は null 観測で false に戻らない
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, hasPaymentMethod: null);
    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
    expect($sub->fresh()?->has_payment_method)->toBeTrue();
});

test('Stripe に無い契約 (404) は状態を変えず report され、終了コードは成功', function (): void {
    reconcileGateway(); // remoteStates を仕込まない = 未検出
    $sub = reconcileSubscription(status: 'past_due', pastDueSince: null);
    $handler = reconcileHandlerSpy();

    $this->artisan('billing:reconcile-subscription-status')
        ->expectsOutputToContain('missing=1')
        ->assertExitCode(0);

    $fresh = $sub->fresh();
    expect($fresh?->stripe_status)->toBe('past_due')
        ->and($fresh?->past_due_since)->toBeNull();
    $handler->shouldHaveReceived('report')->once();
});

test('照会失敗は走査を止めず FAILURE で終わる', function (): void {
    $gateway = reconcileGateway();
    $gateway->failOnLookup = true;
    $first = reconcileSubscription(status: 'active');
    $second = reconcileSubscription(status: 'active');
    $handler = reconcileHandlerSpy();

    $this->artisan('billing:reconcile-subscription-status')
        ->expectsOutputToContain('checked=2 converged=0 missing=0 failed=2')
        ->assertExitCode(1);

    // 1 件目で止まらず 2 件目も照会している
    expect($gateway->lookedUp)->toBe([$first->stripe_id, $second->stripe_id]);
    $handler->shouldHaveReceived('report')->once();
});

test('report は 1 実行 1 回で、内容は件数と organization id のみ (PII なし)', function (): void {
    $gateway = reconcileGateway();
    $gateway->failOnLookup = true;
    $organization = Organization::factory()->create(['name' => '秘密の現場']);
    createFakeSubscription($organization, status: 'active');
    $handler = reconcileHandlerSpy();

    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(1);

    $handler->shouldHaveReceived('report')->once()->withArgs(function (Throwable $e) use ($organization): bool {
        return str_contains($e->getMessage(), 'failed=1')
            && str_contains($e->getMessage(), (string) $organization->getKey())
            && ! str_contains($e->getMessage(), '秘密の現場');
    });
});

test('ローカルが終了扱いの行は照会しない (照会対象が単調増加しない)', function (string $status): void {
    $gateway = reconcileGateway();
    reconcileSubscription(status: $status);

    $this->artisan('billing:reconcile-subscription-status')
        ->expectsOutputToContain('checked=0')
        ->assertExitCode(0);

    expect($gateway->lookedUp)->toBe([]);
})->with(['canceled', 'incomplete_expired']);

test('金銭は動かさない (チケット台帳の件数が変わらない)', function (): void {
    $gateway = reconcileGateway();
    $sub = reconcileSubscription(status: 'active');
    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, status: 'past_due');
    $before = TicketLedgerEntry::query()->count();

    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);

    expect(TicketLedgerEntry::query()->count())->toBe($before)
        ->and($sub->fresh()?->stripe_status)->toBe('past_due');
});

test('ロック保持中の実行は照会せず FAILURE で終わる (多重起動の防止)', function (): void {
    $gateway = reconcileGateway();
    reconcileSubscription(status: 'active');

    $lock = Cache::lock('billing:reconcile-subscription-status', ReconcileSubscriptionStatus::LOCK_SECONDS);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('billing:reconcile-subscription-status')->assertExitCode(1);
        expect($gateway->lookedUp)->toBe([]);
    } finally {
        $lock->release();
    }
});

// ── 実行時間上限とロック有効期限の関係 ──

test('実行時間上限を超えたら残りを照会せず FAILURE で終わる', function (): void {
    $gateway = reconcileGateway();
    // 1 件目の照会で予算を丸ごと使い切らせる (実際には待たず時計だけ進める)。
    $gateway->lookupElapsedSeconds = ReconcileSubscriptionStatus::TIME_BUDGET_SECONDS + 1;
    $first = reconcileSubscription(status: 'active');
    $second = reconcileSubscription(status: 'active');
    $gateway->remoteStates[$first->stripe_id] = reconcileRemote($first);
    $gateway->remoteStates[$second->stripe_id] = reconcileRemote($second);

    $this->travelTo(CarbonImmutable::parse('2026-08-15 03:00:00'));

    $this->artisan('billing:reconcile-subscription-status')
        ->expectsOutputToContain('checked=1')
        ->assertExitCode(1);

    expect($gateway->lookedUp)->toBe([$first->stripe_id]);
});

test('実行時間上限は Stripe の待ち上限と合わせてロック有効期限に収まる', function (): void {
    // 再試行 0 回が前提 (再試行を許すと SDK のバックオフ待機が式に入らなくなる)
    expect(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES)->toBe(0);
    expect(
        ReconcileSubscriptionStatus::TIME_BUDGET_SECONDS
        + ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS
        + ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS
    )->toBeLessThan(ReconcileSubscriptionStatus::LOCK_SECONDS);
});

test('scheduler に daily + onOneServer + withoutOverlapping で登録されている', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'billing:reconcile-subscription-status'));

    expect($events)->toHaveCount(1);
    $event = $events->firstOrFail();
    expect($event->getExpression())->toBe('0 0 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
