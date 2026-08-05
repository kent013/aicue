<?php

declare(strict_types=1);

use App\Enums\Billing\BillingNotificationStatus;
use App\Enums\Billing\BillingNotificationType;
use App\Models\Billing\BillingNotification;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Notifications\Billing\RenewalReminderNotification;
use App\Services\Billing\StripeWebhookProcessor;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Events\WebhookReceived;

/*
 * 更新予告 (billing:send-billing-reminders、daily)。
 * - renewal 3 日前窓内の active 契約のみ対象 (解約予約済み ends_at は対象外)
 * - 冪等は dedup_key {stripe_id}:{epoch} の通知台帳。同日 rerun は二重送信しない
 * - 1 件失敗 (宛先なし等) でも batch は継続する
 */

/**
 * @return array{Organization, Subscription}
 */
function reminderOrgWithRenewal(int $daysUntilRenewal, bool $withOwner = true): array
{
    if ($withOwner) {
        [$organization] = createOrganizationWithOwner();
    } else {
        $organization = Organization::factory()->create();
    }
    $subscription = createFakeSubscription($organization);
    /** @var Subscription $subscription */
    $subscription->forceFill(['current_period_end' => now()->addDays($daysUntilRenewal)->subHour()])->save();

    return [$organization, $subscription];
}

test('renewal 窓内 (3 日前) の active 契約に更新予告が送られる', function (): void {
    Notification::fake();
    [$organization, $subscription] = reminderOrgWithRenewal(daysUntilRenewal: 2);

    $this->artisan('billing:send-billing-reminders')
        ->expectsOutputToContain('renewal: queued=1 skipped=0 failed=0')
        ->assertSuccessful();

    Notification::assertSentTo($organization, RenewalReminderNotification::class);
    $record = BillingNotification::query()->sole();
    expect($record->type)->toBe(BillingNotificationType::RenewalReminder)
        ->and($record->dedup_key)->toBe($subscription->stripe_id.':'.$subscription->refresh()->current_period_end?->getTimestamp())
        ->and($record->status)->toBe(BillingNotificationStatus::Queued);
});

test('同日 rerun は dedup_key で skipped になり二重送信しない', function (): void {
    Notification::fake();
    [$organization] = reminderOrgWithRenewal(daysUntilRenewal: 2);

    $this->artisan('billing:send-billing-reminders')->assertSuccessful();
    $this->artisan('billing:send-billing-reminders')
        ->expectsOutputToContain('renewal: queued=0 skipped=1 failed=0')
        ->assertSuccessful();

    Notification::assertSentToTimes($organization, RenewalReminderNotification::class, 1);
    expect(BillingNotification::query()->count())->toBe(1);
});

test('窓外 / 解約予約済み / 非 active は対象外', function (): void {
    Notification::fake();

    // 窓外 (5 日後)
    reminderOrgWithRenewal(daysUntilRenewal: 5);

    // 解約予約済み (cancel at period end)
    [, $cancelling] = reminderOrgWithRenewal(daysUntilRenewal: 2);
    $cancelling->forceFill(['ends_at' => now()->addDays(2)])->save();

    // past_due
    [, $pastDue] = reminderOrgWithRenewal(daysUntilRenewal: 2);
    $pastDue->forceFill(['stripe_status' => 'past_due'])->save();

    $this->artisan('billing:send-billing-reminders')
        ->expectsOutputToContain('renewal: queued=0 skipped=0 failed=0')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

test('宛先を解決できない組織があっても batch は継続する (1 件失敗で止めない)', function (): void {
    Notification::fake();

    // Owner のいない組織 = 宛先 null → failed
    reminderOrgWithRenewal(daysUntilRenewal: 2, withOwner: false);
    // 正常な組織 → queued
    [$healthyOrg] = reminderOrgWithRenewal(daysUntilRenewal: 2);

    $this->artisan('billing:send-billing-reminders')
        ->expectsOutputToContain('renewal: queued=1 skipped=0 failed=1')
        ->assertSuccessful();

    Notification::assertSentTo($healthyOrg, RenewalReminderNotification::class);
    expect(BillingNotification::query()->where('status', BillingNotificationStatus::Failed->value)->count())->toBe(1);
});

test('--days は非負整数のみ受け付ける', function (): void {
    $this->artisan('billing:send-billing-reminders', ['--days' => 'abc'])->assertFailed();
});

test('customer.subscription.updated webhook で current_period_end が同期される (renewal の真実源)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->stripe_id = 'cus_period_sync_1';
    $organization->save();
    /** @var Subscription $subscription */
    $subscription = createFakeSubscription($organization);
    expect($subscription->current_period_end)->toBeNull();

    $periodEnd = now()->addMonthNoOverflow()->startOfSecond();
    app(StripeWebhookProcessor::class)->handle(new WebhookReceived([
        'id' => 'evt_sub_updated_period_1',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => $subscription->stripe_id,
                'customer' => 'cus_period_sync_1',
                'status' => 'active',
                // 新 API (basil) は current_period_end を item 配下に持つ
                'items' => ['data' => [['current_period_end' => $periodEnd->getTimestamp()]]],
            ],
        ],
    ]));

    expect($subscription->refresh()->current_period_end?->getTimestamp())
        ->toBe($periodEnd->getTimestamp());
});
