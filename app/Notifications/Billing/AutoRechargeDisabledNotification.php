<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Enums\Billing\BillingNotificationType;
use App\Notifications\Billing\Concerns\TracksBillingReminderDelivery;
use App\Support\Billing\BillingNotificationRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * P8a: 連続失敗によるオートリチャージ自動停止の通知。
 * dedup_key = auto_recharge_disabled:{attempt_ulid} (停止イベント単位)。
 */
class AutoRechargeDisabledNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
{
    use Queueable;

    public function __construct(
        public readonly string $dedupKey,
        public readonly string $organizationName,
        public readonly string $billingUrl,
    ) {}

    public function deliveryType(): BillingNotificationType
    {
        return BillingNotificationType::AutoRechargeDisabled;
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
        return (new MailMessage)
            ->subject('【'.Config::string('app.name').'】オートリチャージを停止しました')
            ->greeting("{$this->organizationName} 様")
            ->line('チケットの自動購入 (オートリチャージ) の決済が続けて失敗したため、オートリチャージを自動的に停止しました。')
            ->line('お支払いカードを更新のうえ、請求設定からオートリチャージを再度有効にしてください。')
            ->action('請求設定を開く', $this->billingUrl)
            ->line('停止中はチケットの自動補充は行われません。残高にご注意ください。');
    }

    public function failed(Throwable $e): void
    {
        BillingNotificationRecorder::markFailedByDedupKey($this->deliveryType(), $this->dedupKey, $e);
    }
}
