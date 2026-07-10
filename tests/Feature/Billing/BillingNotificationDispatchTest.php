<?php

declare(strict_types=1);

use App\Enums\Billing\BillingNotificationStatus;
use App\Enums\Billing\BillingNotificationType;
use App\Enums\Billing\BillingReminderDispatchResult;
use App\Listeners\Billing\MarkBillingNotificationDelivered;
use App\Models\Billing\BillingNotification;
use App\Models\Organization;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Billing\RenewalReminderNotification;
use App\Services\Billing\BillingNotificationDispatcher;
use App\Services\Billing\StripeWebhookProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Events\WebhookReceived;

/*
 * 請求通知の冪等 dispatch (BillingNotificationDispatcher + 通知台帳 billing_notifications)。
 * - send-once: (type, invoice_id) / (type, dedup_key) 複合 UNIQUE で二重送信しない
 * - 宛先を解決できない組織は failed(missing_billing_recipient) で確定 (queued 滞留させない)
 * - invoice.payment_failed webhook から dispatcher が配線されている
 */

function billingNotificationOrg(): Organization
{
    [$organization] = createOrganizationWithOwner();

    return $organization;
}

test('sendOnce は初回のみ通知を送り delivery record を queued で作る', function (): void {
    Notification::fake();
    $organization = billingNotificationOrg();

    $notification = new PaymentFailedNotification('in_once_1', $organization->name, 'https://example.test/billing');
    app(BillingNotificationDispatcher::class)->sendOnce(
        $organization, BillingNotificationType::PaymentFailed, 'in_once_1', $notification,
    );

    Notification::assertSentTo($organization, PaymentFailedNotification::class);
    $record = BillingNotification::query()->sole();
    expect($record->type)->toBe(BillingNotificationType::PaymentFailed)
        ->and($record->invoice_id)->toBe('in_once_1')
        ->and($record->organization_id)->toBe($organization->id)
        ->and($record->status)->toBe(BillingNotificationStatus::Queued);
});

test('sendOnce は同一 (type, invoice_id) の再送で二重送信しない', function (): void {
    Notification::fake();
    $organization = billingNotificationOrg();
    $dispatcher = app(BillingNotificationDispatcher::class);

    foreach ([1, 2] as $attempt) {
        $dispatcher->sendOnce(
            $organization,
            BillingNotificationType::PaymentFailed,
            'in_dup_1',
            new PaymentFailedNotification('in_dup_1', $organization->name, 'https://example.test/billing'),
        );
    }

    Notification::assertSentToTimes($organization, PaymentFailedNotification::class, 1);
    expect(BillingNotification::query()->count())->toBe(1);
});

test('宛先を解決できない組織は failed(missing_billing_recipient) で確定する', function (): void {
    Notification::fake();
    // Owner メンバーのいない素の組織 = routeNotificationForMail が null
    $organization = Organization::factory()->create();

    app(BillingNotificationDispatcher::class)->sendOnce(
        $organization,
        BillingNotificationType::PaymentFailed,
        'in_norecipient_1',
        new PaymentFailedNotification('in_norecipient_1', $organization->name, 'https://example.test/billing'),
    );

    Notification::assertNothingSent();
    $record = BillingNotification::query()->sole();
    expect($record->status)->toBe(BillingNotificationStatus::Failed)
        ->and($record->error)->toBe('missing_billing_recipient');
});

test('sendReminderOnce は初回 queued・再送 skipped を返す', function (): void {
    Notification::fake();
    $organization = billingNotificationOrg();
    $dispatcher = app(BillingNotificationDispatcher::class);
    $dedupKey = 'sub_reminder_1:1750000000';
    $build = fn (): RenewalReminderNotification => new RenewalReminderNotification(
        $dedupKey, $organization->name, 'https://example.test/billing', CarbonImmutable::now()->addDays(3),
    );

    $first = $dispatcher->sendReminderOnce($organization, BillingNotificationType::RenewalReminder, $dedupKey, $build());
    $second = $dispatcher->sendReminderOnce($organization, BillingNotificationType::RenewalReminder, $dedupKey, $build());

    expect($first)->toBe(BillingReminderDispatchResult::Queued)
        ->and($second)->toBe(BillingReminderDispatchResult::Skipped);
    Notification::assertSentToTimes($organization, RenewalReminderNotification::class, 1);
    $record = BillingNotification::query()->sole();
    expect($record->dedup_key)->toBe($dedupKey)
        ->and($record->invoice_id)->toBeNull();
});

test('NotificationSent で delivery record が sent に確定する (invoice / dedup_key 両経路)', function (): void {
    $organization = billingNotificationOrg();
    $listener = app(MarkBillingNotificationDelivered::class);

    // invoice_id 経路
    BillingNotification::factory()->forOrganization($organization)->create(['invoice_id' => 'in_sent_1']);
    $listener->handle(new NotificationSent(
        $organization,
        new PaymentFailedNotification('in_sent_1', $organization->name, 'https://example.test/billing'),
        'mail',
    ));
    $paymentRecord = BillingNotification::query()->where('invoice_id', 'in_sent_1')->sole();
    expect($paymentRecord->status)->toBe(BillingNotificationStatus::Sent)
        ->and($paymentRecord->sent_at)->not->toBeNull();

    // dedup_key 経路
    BillingNotification::factory()->forOrganization($organization)->reminder('sub_sent_1:1750000000')->create();
    $listener->handle(new NotificationSent(
        $organization,
        new RenewalReminderNotification('sub_sent_1:1750000000', $organization->name, 'https://example.test/billing', CarbonImmutable::now()),
        'mail',
    ));
    $reminderRecord = BillingNotification::query()->where('dedup_key', 'sub_sent_1:1750000000')->sole();
    expect($reminderRecord->status)->toBe(BillingNotificationStatus::Sent);
});

test('invoice.payment_failed webhook で支払い失敗通知が send-once で送られる', function (): void {
    Notification::fake();
    $organization = billingNotificationOrg();
    $organization->stripe_id = 'cus_pf_test_1';
    $organization->save();

    $payload = fn (string $eventId): array => [
        'id' => $eventId,
        'type' => 'invoice.payment_failed',
        'data' => [
            'object' => [
                'id' => 'in_webhook_pf_1',
                'customer' => 'cus_pf_test_1',
                'attempt_count' => 1,
            ],
        ],
    ];

    $processor = app(StripeWebhookProcessor::class);
    $processor->handle(new WebhookReceived($payload('evt_pf_1')));
    // 別 event_id での同一 invoice 再通知 → 通知台帳の (type, invoice_id) で dedup
    $processor->handle(new WebhookReceived($payload('evt_pf_2')));

    Notification::assertSentToTimes($organization, PaymentFailedNotification::class, 1);
    $record = BillingNotification::query()->sole();
    expect($record->type)->toBe(BillingNotificationType::PaymentFailed)
        ->and($record->invoice_id)->toBe('in_webhook_pf_1');
});

test('invoice.payment_failed で組織不明 / invoice id 不明でも webhook は processed になる', function (): void {
    Notification::fake();

    app(StripeWebhookProcessor::class)->handle(new WebhookReceived([
        'id' => 'evt_pf_unknown_1',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => ['id' => 'in_unknown_1', 'customer' => 'cus_unknown_1']],
    ]));

    Notification::assertNothingSent();
    expect(BillingNotification::query()->count())->toBe(0);
});
