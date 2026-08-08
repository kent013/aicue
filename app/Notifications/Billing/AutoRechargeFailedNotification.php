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
 * P8a: オートリチャージ課金失敗の通知。dedup_key = auto_recharge_failed:{attempt_ulid}
 * (attempt 単位 — 同一 attempt の webhook 再送で再通知しない)。
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
class AutoRechargeFailedNotification extends Notification implements ShouldQueue, TracksBillingReminderDelivery
{
    use Queueable;

    public function __construct(
        public readonly string $dedupKey,
        public readonly string $organizationName,
        public readonly string $billingUrl,
        public readonly string $purchaseUrl,
    ) {}

    public function deliveryType(): BillingNotificationType
    {
        return BillingNotificationType::AutoRechargeFailed;
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
            ->subject('【'.Config::string('app.name').'】チケットの自動購入に失敗しました')
            ->greeting("{$this->organizationName} 様")
            ->line('チケットのオートリチャージ (自動購入) の決済に失敗しました。今回の自動購入は行われていません (課金は発生していません)。')
            ->line('お支払いカードの有効期限や利用限度額をご確認のうえ、カード情報の更新をお願いします。')
            ->action('請求設定を確認する', $this->billingUrl)
            ->line("すぐにチケットが必要な場合は、手動での追加購入もご利用いただけます: {$this->purchaseUrl}");
    }

    public function failed(Throwable $e): void
    {
        BillingNotificationRecorder::markFailedByDedupKey($this->deliveryType(), $this->dedupKey, $e);
    }
}
