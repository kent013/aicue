<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Enums\Billing\BillingNotificationType;
use App\Notifications\Billing\Concerns\TracksBillingDelivery;
use App\Support\Billing\BillingNotificationRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * 支払い失敗通知。invoice.payment_failed 受信で組織の請求宛先へ送る。
 *
 * queue 送信 (webhook 本処理を巻き込まない)。
 *
 * 【`ShouldQueueAfterCommit` を持たない理由 — AG-114 確定 1 / AG-126 の 0 件 pin (T137)】
 * 本通知の送信元は BillingNotificationDispatcher 1 経路で、その呼び出し元
 * (StripeWebhookProcessor / AutoRechargeService の通知群 / SendBillingReminders) は
 * **すべて業務 tx の外**である。よって `ShouldQueueAfterCommit` は実行時に何の効果も
 * 持っていなかった (`addCallback` は pending tx が 0 件なら即時実行する)。
 * 一方この interface は「grep afterCommit では見えない宣言的迂回」であり、
 * 将来 tx 内から送ったときに黙って投入を commit 後へずらす。撤去して
 * QueueDispatchAtomicityInventoryTest (D3) の 0 件 pin に載せる。
 * 失敗の吸収は従来どおり dispatcher 側 (insertOrIgnore + markFailed + Log::warning) が担う。
 */
class PaymentFailedNotification extends Notification implements ShouldQueue, TracksBillingDelivery
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
