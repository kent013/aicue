<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Enums\Billing\WebhookEventStatus;
use App\Enums\Billing\WebhookRecoveryReason;
use App\Models\Billing\Plan;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Billing\TicketCheckoutSession;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\StripeWebhookProcessor;
use App\Services\Billing\TicketLedgerService;
use App\Support\Legal\BillingRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Events\WebhookReceived;
use Webmozart\Assert\Assert;

/*
 * 滞留 webhook の回収 (StripeWebhookProcessor::recoverStale) と、
 * 受理した世代を握っている実行だけが行う終局書き込み (finalize の条件付き UPDATE)。
 *
 * 背景: claim() が直列化するのは状態遷移だけで process() はトランザクションの外にある。
 * そこで落ちた行は received のまま残り、Stripe の再送は claim() に弾かれて 200 で終わるため、
 * 決済済みチケットの付与が無音で失われる。
 */

/**
 * stripe_id を持つ組織と owner を作る。
 *
 * @return array{Organization, User}
 */
function staleRecoveryFixture(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_stale_recovery_1';
    $organization->save();

    return [$organization, $owner];
}

/** standard プランの現行 base Price の Stripe Price ID。 */
function staleRecoveryBasePriceId(): string
{
    $price = Plan::query()->where('code', 'standard')->firstOrFail()
        ->currentPrice(PlanPriceKind::Base);
    Assert::notNull($price, 'standard プランの current base price が未 seed');

    return $price->stripe_price_id;
}

/**
 * @return array<string, mixed>
 */
function staleRecoveryInvoicePaidPayload(string $eventId, string $invoiceId = 'in_stale_1'): array
{
    return [
        'id' => $eventId,
        'type' => 'invoice.paid',
        'data' => [
            'object' => [
                'id' => $invoiceId,
                'customer' => 'cus_stale_recovery_1',
                'billing_reason' => 'subscription_cycle',
                'lines' => [
                    'data' => [
                        ['price' => ['id' => staleRecoveryBasePriceId()]],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function staleRecoveryTicketPurchasePayload(string $eventId, Organization $organization): array
{
    return [
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_stale_1',
                'mode' => 'payment',
                'customer' => 'cus_stale_recovery_1',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_stale_1',
                'amount_subtotal' => 30 * 80,
                'currency' => 'jpy',
                'metadata' => [
                    'purpose' => 'ticket_purchase',
                    'org_ref' => (string) $organization->id,
                    'count' => '30',
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function staleRecoverySubscriptionPayload(string $eventId, string $type = 'customer.subscription.updated'): array
{
    return [
        'id' => $eventId,
        'type' => $type,
        'data' => [
            'object' => [
                'id' => 'sub_stale_1',
                'customer' => 'cus_stale_recovery_1',
                'status' => 'active',
                'items' => [
                    'data' => [
                        ['price' => ['id' => staleRecoveryBasePriceId()]],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * received のまま滞留している記録を作る。
 *
 * Eloquent は保存時に updated_at を now へ書き換えるため、保存後に明示的に押し戻す
 * (Factory の state だけでは滞留行にならない)。
 *
 * @param  array<mixed>  $payload
 */
function staleWebhookRecord(
    string $eventId,
    string $type,
    array $payload,
    int $attempts = 0,
    int $minutesAgo = 60,
): StripeWebhookEvent {
    $record = StripeWebhookEvent::factory()->stale($minutesAgo)->create([
        'event_id' => $eventId,
        'type' => $type,
        'payload' => $payload,
        'attempts' => $attempts,
    ]);

    pushBackWebhookUpdatedAt($eventId, $minutesAgo);

    return $record->refresh();
}

/** updated_at を過去へ押し戻す (滞留判定を跨がせる)。 */
function pushBackWebhookUpdatedAt(string $eventId, int $minutesAgo): void
{
    StripeWebhookEvent::query()
        ->where('event_id', $eventId)
        ->update(['updated_at' => CarbonImmutable::now()->subMinutes($minutesAgo)]);
}

/** 台帳の不変条件: recovery_reason が非 NULL ⟺ status = recovery_pending。 */
function assertRecoveryReasonInvariant(): void
{
    expect(StripeWebhookEvent::query()
        ->whereNotNull('recovery_reason')
        ->where('status', '!=', WebhookEventStatus::RecoveryPending->value)
        ->count())->toBe(0);
    expect(StripeWebhookEvent::query()
        ->where('status', WebhookEventStatus::RecoveryPending->value)
        ->whereNull('recovery_reason')
        ->count())->toBe(0);
}

test('滞留した checkout.session.completed は回収で付与され processed になる', function (): void {
    [$organization, $owner] = staleRecoveryFixture();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create([
            'ticket_count' => 30,
            'unit_amount' => 80,
            'currency' => 'jpy',
            'stripe_session_id' => 'cs_stale_1',
        ]);
    staleWebhookRecord(
        'evt_stale_purchase',
        'checkout.session.completed',
        staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
    );

    $result = app(StripeWebhookProcessor::class)->recoverStale();

    expect($result->replayed)->toBe(1);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);

    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_purchase')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Processed);
    expect($record->processed_at)->not->toBeNull();
    expect($record->recovery_reason)->toBeNull();
    assertRecoveryReasonInvariant();
});

test('滞留した invoice.paid は回収で月次付与される', function (): void {
    [$organization] = staleRecoveryFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
    staleWebhookRecord(
        'evt_stale_invoice',
        'invoice.paid',
        staleRecoveryInvoicePaidPayload('evt_stale_invoice'),
    );

    $result = app(StripeWebhookProcessor::class)->recoverStale();

    expect($result->replayed)->toBe(1);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
    expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_invoice')->firstOrFail()->status)
        ->toBe(WebhookEventStatus::Processed);
});

test('回収で付与した後に Stripe 再送が来ても二重付与しない', function (): void {
    [$organization, $owner] = staleRecoveryFixture();
    TicketCheckoutSession::factory()
        ->forOrganization($organization)
        ->initiatedBy($owner)
        ->create([
            'ticket_count' => 30,
            'unit_amount' => 80,
            'currency' => 'jpy',
            'stripe_session_id' => 'cs_stale_1',
        ]);
    staleWebhookRecord(
        'evt_stale_purchase',
        'checkout.session.completed',
        staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
    );

    app(StripeWebhookProcessor::class)->recoverStale();
    // 別 event_id での再通知 (event_id 冪等では防げない経路)
    event(new WebhookReceived(staleRecoveryTicketPurchasePayload('evt_resend_purchase', $organization)));

    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'purchase:cs_stale_1')->count())
        ->toBe(1);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
});

test('順序に依存する種類の滞留は再実行せず回収待ちへ置く', function (): void {
    [$organization] = staleRecoveryFixture();
    expect($organization->plan_code)->toBeNull();
    staleWebhookRecord(
        'evt_stale_sub',
        'customer.subscription.updated',
        staleRecoverySubscriptionPayload('evt_stale_sub'),
    );

    $result = app(StripeWebhookProcessor::class)->recoverStale();

    expect($result->movedToRecoveryPending)->toBe(1);
    expect($result->replayed)->toBe(0);

    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_sub')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
    expect($record->recovery_reason)->toBe(WebhookRecoveryReason::OrderSensitive);
    // 状態は書き換わっていない (再実行していない)
    expect($organization->refresh()->plan_code)->toBeNull();

    // 回収待ちの行に Stripe 再送が来ても claim() が受理しない (状態が巻き戻らない)
    event(new WebhookReceived(staleRecoverySubscriptionPayload('evt_stale_sub')));

    expect($organization->refresh()->plan_code)->toBeNull();
    expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_sub')->firstOrFail()->status)
        ->toBe(WebhookEventStatus::RecoveryPending);
    assertRecoveryReasonInvariant();
});

test('試行上限に到達した滞留は再実行せず回収待ちへ置く', function (): void {
    [$organization] = staleRecoveryFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
    staleWebhookRecord(
        'evt_stale_exhausted',
        'invoice.paid',
        staleRecoveryInvoicePaidPayload('evt_stale_exhausted'),
        attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS,
    );

    $result = app(StripeWebhookProcessor::class)->recoverStale();

    expect($result->movedToRecoveryPending)->toBe(1);
    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_exhausted')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
    expect($record->recovery_reason)->toBe(WebhookRecoveryReason::AttemptsExhausted);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    assertRecoveryReasonInvariant();
});

test('本アプリが処理しない種類の滞留は通常経路と同じく processed になる', function (): void {
    [$organization] = staleRecoveryFixture();
    staleWebhookRecord('evt_stale_unhandled', 'customer.updated', [
        'id' => 'evt_stale_unhandled',
        'type' => 'customer.updated',
        'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
    ]);

    $result = app(StripeWebhookProcessor::class)->recoverStale();

    expect($result->replayed)->toBe(1);
    expect($result->movedToRecoveryPending)->toBe(0);
    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Processed);
    expect($record->recovery_reason)->toBeNull();
    // 副作用が何も起きない
    expect($organization->ticketLedgerEntries()->count())->toBe(0);
    expect($organization->refresh()->plan_code)->toBeNull();
});

test('本アプリが処理しない種類は試行上限に到達していても processed になる', function (): void {
    staleRecoveryFixture();
    staleWebhookRecord('evt_stale_unhandled_max', 'customer.updated', [
        'id' => 'evt_stale_unhandled_max',
        'type' => 'customer.updated',
        'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
    ], attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);

    app(StripeWebhookProcessor::class)->recoverStale();

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled_max')->firstOrFail()->status)
        ->toBe(WebhookEventStatus::Processed);
});

test('滞留の閾値内の received 行には触らない', function (): void {
    [$organization] = staleRecoveryFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
    staleWebhookRecord(
        'evt_fresh',
        'invoice.paid',
        staleRecoveryInvoicePaidPayload('evt_fresh'),
        minutesAgo: 5,
    );

    $result = app(StripeWebhookProcessor::class)->recoverStale();

    expect($result->replayed)->toBe(0);
    expect($result->skipped)->toBe(0);
    $record = StripeWebhookEvent::query()->where('event_id', 'evt_fresh')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Received);
    expect($record->attempts)->toBe(0);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
});

test('回収の再実行が失敗しても終局させず次回の回収へ回す (最後は試行上限で止まる)', function (): void {
    staleRecoveryFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
    $this->mock(TicketLedgerService::class)
        ->shouldReceive('grantMonthly')
        ->andThrow(new RuntimeException('付与処理の一時故障'));

    staleWebhookRecord(
        'evt_stale_retry',
        'invoice.paid',
        staleRecoveryInvoicePaidPayload('evt_stale_retry'),
    );

    $result = app(StripeWebhookProcessor::class)->recoverStale();

    expect($result->retryScheduled)->toBe(1);
    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Received); // 終局させない
    expect($record->failure_reason)->toBe('付与処理の一時故障');
    expect($record->attempts)->toBe(1);

    // 閾値を再び超えさせて繰り返すと attempts が上限まで進み、最後は回収待ちで止まる
    for ($i = 0; $i < StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS + 1; $i++) {
        pushBackWebhookUpdatedAt('evt_stale_retry', 60);
        app(StripeWebhookProcessor::class)->recoverStale();
    }

    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
    expect($record->recovery_reason)->toBe(WebhookRecoveryReason::AttemptsExhausted);
    expect($record->attempts)->toBe(StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
    assertRecoveryReasonInvariant();
});

test('回収中に別の実行が世代を進めたら件数は skipped に計上する', function (): void {
    staleRecoveryFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
    $this->mock(TicketLedgerService::class)
        ->shouldReceive('grantMonthly')
        ->andReturnUsing(function (): void {
            // 別の実行が世代を進めた状況 (単一プロセスで追い越しだけを再現する)
            StripeWebhookEvent::query()->where('event_id', 'evt_stale_overtaken')->update(['attempts' => 5]);

            throw new RuntimeException('付与処理の一時故障');
        });

    staleWebhookRecord(
        'evt_stale_overtaken',
        'invoice.paid',
        staleRecoveryInvoicePaidPayload('evt_stale_overtaken'),
    );

    $result = app(StripeWebhookProcessor::class)->recoverStale();

    expect($result->skipped)->toBe(1);
    expect($result->retryScheduled)->toBe(0);
    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_overtaken')->firstOrFail();
    expect($record->attempts)->toBe(5); // 追い越した側の値が残る
    expect($record->failure_reason)->toBeNull(); // 旧世代は何も書かない
});

test('HTTP 経路で世代を追い越されたら終局書き込みを見送り例外も投げない', function (): void {
    [$organization] = staleRecoveryFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
    $this->mock(TicketLedgerService::class)
        ->shouldReceive('grantMonthly')
        ->andReturnUsing(function (): void {
            StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http')->update(['attempts' => 5]);
        });

    // 例外を投げない = Cashier が 200 を返す (行は既に別の世代が持っている)
    event(new WebhookReceived(staleRecoveryInvoicePaidPayload('evt_overtaken_http')));

    $record = StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Received); // processed にならない
    expect($record->attempts)->toBe(5);
    expect($record->processed_at)->toBeNull();
    // 回収経路の据え置きと違い recovery_pending にはしない
    expect($record->recovery_reason)->toBeNull();
    expect($organization->refresh()->plan_code)->toBeNull();
});

test('HTTP 経路で世代を追い越されたら処理が失敗しても例外を投げない', function (): void {
    [$organization] = staleRecoveryFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
    $this->mock(TicketLedgerService::class)
        ->shouldReceive('grantMonthly')
        ->andReturnUsing(function (): void {
            StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http_fail')->update(['attempts' => 5]);

            throw new RuntimeException('付与処理の一時故障');
        });

    // 行は既に別の世代が持っているので、失敗しても Stripe の再送を促さない (200 で終わる)
    event(new WebhookReceived(staleRecoveryInvoicePaidPayload('evt_overtaken_http_fail')));

    $record = StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http_fail')->firstOrFail();
    expect($record->status)->toBe(WebhookEventStatus::Received); // failed にもならない
    expect($record->attempts)->toBe(5);
    expect($record->failure_reason)->toBeNull();
    expect($organization->refresh()->plan_code)->toBeNull();
});

test('回収の件数は処置と一致する (replayed / movedToRecoveryPending / skipped)', function (): void {
    [$organization] = staleRecoveryFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);

    staleWebhookRecord('evt_count_replay', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_count_replay'));
    staleWebhookRecord(
        'evt_count_pending',
        'customer.subscription.updated',
        staleRecoverySubscriptionPayload('evt_count_pending'),
    );
    staleWebhookRecord('evt_count_fresh', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_count_fresh', 'in_fresh'), minutesAgo: 5);

    $result = app(StripeWebhookProcessor::class)->recoverStale();

    expect($result->replayed)->toBe(1);
    expect($result->movedToRecoveryPending)->toBe(1);
    expect($result->retryScheduled)->toBe(0);
    expect($result->skipped)->toBe(0);
    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'monthly:in_stale_1')->count())->toBe(1);
    assertRecoveryReasonInvariant();
});

test('cron コマンドが滞留を回収し 4 件数を出力する', function (): void {
    [$organization] = staleRecoveryFixture();
    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
    staleWebhookRecord('evt_cron', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_cron'));

    $this->artisan('billing:recover-stale-webhook-events')
        ->expectsOutputToContain('replayed 1 / retry-scheduled 0 / moved-to-recovery-pending 0 / skipped 0')
        ->assertExitCode(0);

    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
});

test('滞留判定の閾値は整数で設定されている', function (): void {
    expect(config()->integer('billing.webhook_stale_after_minutes'))->toBeGreaterThan(0);
});

test('recovery_reason は recovery_pending 以外の行に入れられない (DB CHECK)', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
        $this->markTestSkipped('CHECK 制約は pgsql/mysql のみ (sqlite は ALTER ADD CONSTRAINT 非対応)');
    }

    StripeWebhookEvent::factory()->create(['event_id' => 'evt_check_1']);

    expect(fn () => StripeWebhookEvent::query()
        ->where('event_id', 'evt_check_1')
        ->update(['recovery_reason' => WebhookRecoveryReason::OrderSensitive->value]))
        ->toThrow(QueryException::class);
});

test('recovery_pending の行から recovery_reason を外せない (DB CHECK)', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
        $this->markTestSkipped('CHECK 制約は pgsql/mysql のみ (sqlite は ALTER ADD CONSTRAINT 非対応)');
    }

    StripeWebhookEvent::factory()
        ->recoveryPending(WebhookRecoveryReason::AttemptsExhausted)
        ->create(['event_id' => 'evt_check_2']);

    expect(fn () => StripeWebhookEvent::query()
        ->where('event_id', 'evt_check_2')
        ->update(['recovery_reason' => null]))
        ->toThrow(QueryException::class);
});

test('保持期限を超えても回収待ち・滞留 received の行は purge が消さない', function (): void {
    // 起算点 (processed_at) が NULL の行は「異常として計上するだけで消さない」契約
    $expired = BillingRetention::threshold()->subSecond();
    StripeWebhookEvent::factory()
        ->recoveryPending(WebhookRecoveryReason::OrderSensitive)
        ->create(['event_id' => 'evt_purge_pending', 'created_at' => $expired]);
    StripeWebhookEvent::factory()
        ->stale()
        ->create(['event_id' => 'evt_purge_stale', 'created_at' => $expired]);

    $this->artisan('billing:purge-retention-expired', ['--apply' => true])
        ->expectsOutputToContain('stripe_webhook_event: expired=0 processed=0 fail_closed=2')
        ->assertExitCode(0);

    expect(StripeWebhookEvent::query()->where('event_id', 'evt_purge_pending')->exists())->toBeTrue();
    expect(StripeWebhookEvent::query()->where('event_id', 'evt_purge_stale')->exists())->toBeTrue();
});

test('新しい状態と理由は表示名を持つ', function (): void {
    expect(WebhookEventStatus::RecoveryPending->label())->toBe('回収待ち');
    foreach (WebhookRecoveryReason::cases() as $reason) {
        expect($reason->label())->not->toBe('');
    }
});

test('migration の down() で CHECK 制約・index・列が落ち、再適用できる', function (): void {
    $migration = require database_path(
        'migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php'
    );
    expect($migration)->toBeInstanceOf(Migration::class);

    try {
        $migration->down();

        expect(Schema::hasColumn('stripe_webhook_events', 'recovery_reason'))->toBeFalse();
        expect(Schema::hasIndex('stripe_webhook_events', 'stripe_webhook_events_status_updated_at_index'))
            ->toBeFalse();
    } finally {
        // assert が落ちても schema を必ず戻す (同一プロセスの後続テストへ破損を残さない)。
        // 再適用が通ること自体が「CHECK 制約 (同名) も確かに落ちている」ことの証明になる。
        $migration->up();
    }

    expect(Schema::hasColumn('stripe_webhook_events', 'recovery_reason'))->toBeTrue();
    expect(Schema::hasIndex('stripe_webhook_events', 'stripe_webhook_events_status_updated_at_index'))
        ->toBeTrue();
});
