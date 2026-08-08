<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Enums\Billing\BillingNotificationType;
use App\Notifications\Billing\Concerns\TracksBillingReminderDelivery;
use App\Support\Billing\BillingNotificationRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * P8a: 連続失敗によるオートリチャージ自動停止の通知。
 * dedup_key = auto_recharge_disabled:{attempt_ulid} (停止イベント単位)。
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
class AutoRechargeDisabledNotification extends Notification implements ShouldQueue, TracksBillingReminderDelivery
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
