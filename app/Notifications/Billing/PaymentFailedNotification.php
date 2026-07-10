<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Enums\Billing\BillingNotificationType;
use App\Notifications\Billing\Concerns\TracksBillingDelivery;
use App\Support\Billing\BillingNotificationRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * 支払い失敗通知。invoice.payment_failed 受信で組織の請求宛先へ送る。
 *
 * queue 送信 + DB commit 後発火 (webhook 本処理を巻き込まない)。
 */
class PaymentFailedNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingDelivery
{
    use Queueable;

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $organizationName,
        public readonly string $billingUrl,
    ) {}

    public function deliveryType(): BillingNotificationType
    {
        return BillingNotificationType::PaymentFailed;
    }

    public function deliveryInvoiceId(): string
    {
        return $this->invoiceId;
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
            ->subject('【'.Config::string('app.name').'】お支払いに失敗しました')
            ->greeting("{$this->organizationName} 様")
            ->line('サブスクリプションのお支払いに失敗しました。')
            ->line('サービスを継続してご利用いただくため、お支払い方法 (カード情報) のご確認・更新をお願いします。')
            ->action('お支払い方法を更新する', $this->billingUrl)
            ->line('ご不明な点がございましたらサポートまでお問い合わせください。');
    }

    /** queued job の実送信失敗で delivery record を failed に確定する。 */
    public function failed(Throwable $e): void
    {
        BillingNotificationRecorder::markFailed($this->deliveryType(), $this->invoiceId, $e);
    }
}
