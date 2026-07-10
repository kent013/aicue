<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Enums\Billing\BillingNotificationType;
use App\Notifications\Billing\Concerns\TracksBillingReminderDelivery;
use App\Support\Billing\BillingNotificationRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * 更新予告。次回請求 (current_period_end) の N 日前に組織の請求宛先へ送る
 * (billing:send-billing-reminders が日次 dispatch する)。
 */
class RenewalReminderNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
{
    use Queueable;

    public function __construct(
        public readonly string $dedupKey,
        public readonly string $organizationName,
        public readonly string $billingUrl,
        public readonly CarbonImmutable $effectiveDate,
    ) {}

    public function deliveryType(): BillingNotificationType
    {
        return BillingNotificationType::RenewalReminder;
    }

    public function deliveryDedupKey(): string
    {
        return $this->dedupKey;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tz = Config::string('app.timezone');

        return (new MailMessage)
            ->subject('【'.Config::string('app.name').'】まもなく次回のお支払いです')
            ->greeting("{$this->organizationName} 様")
            ->line('次回のお支払い日が近づいています。')
            ->line('次回請求日: '.$this->effectiveDate->timezone($tz)->translatedFormat('Y年n月j日'))
            ->action('請求内容を確認する', $this->billingUrl)
            ->line('お支払い方法のご確認は上記リンクから行えます。');
    }

    /** queued job の実送信失敗で delivery record を failed に確定する。 */
    public function failed(Throwable $e): void
    {
        BillingNotificationRecorder::markFailedByDedupKey($this->deliveryType(), $this->dedupKey, $e);
    }
}
