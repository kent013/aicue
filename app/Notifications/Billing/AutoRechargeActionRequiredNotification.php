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
 * P8a: オートリチャージ課金の SCA (3D セキュア) 認証要求通知。
 * dedup_key = auto_recharge_sca:{invoice_id}:{JST date} (日次で再通知を許す — 放置での失効を防ぐ)。
 * action URL は invoice の hosted_invoice_url (Stripe ホストページで認証完了できる)。
 */
class AutoRechargeActionRequiredNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
{
    use Queueable;

    public function __construct(
        public readonly string $dedupKey,
        public readonly string $organizationName,
        public readonly string $actionUrl,
    ) {}

    public function deliveryType(): BillingNotificationType
    {
        return BillingNotificationType::AutoRechargeActionRequired;
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
            ->subject('【'.Config::string('app.name').'】チケット自動購入に本人認証 (3D セキュア) が必要です')
            ->greeting("{$this->organizationName} 様")
            ->line('チケットのオートリチャージ (自動購入) を完了するために、カード発行会社による本人認証 (3D セキュア / SCA) が必要です。')
            ->line('期限内に認証が完了しない場合、今回の自動購入はキャンセルされます。')
            ->action('お支払いを完了する', $this->actionUrl);
    }

    public function failed(Throwable $e): void
    {
        BillingNotificationRecorder::markFailedByDedupKey($this->deliveryType(), $this->dedupKey, $e);
    }
}
