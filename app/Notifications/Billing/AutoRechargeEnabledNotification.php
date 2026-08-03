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
 * P8a: カード登録完了によるオートリチャージ自動有効化の事後通知。
 *
 * 同意の代替ではない (同意成立はオンボーディング画面の affirmative action)。有効化された
 * 条件 (閾値 / 補充後枚数 / 1 回の上限額 = 同意時金額) と停止方法を明記する。
 * dedup_key = auto_recharge_enabled:{org_id}:{payment_method_id} (setup 完了イベント単位)。
 */
class AutoRechargeEnabledNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
{
    use Queueable;

    public function __construct(
        public readonly string $dedupKey,
        public readonly string $organizationName,
        public readonly int $thresholdCount,
        public readonly int $maxCount,
        public readonly ?int $consentedMaxAmountJpy,
        public readonly ?string $paymentMethodLast4,
        public readonly string $billingUrl,
    ) {}

    public function deliveryType(): BillingNotificationType
    {
        return BillingNotificationType::AutoRechargeEnabled;
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
        $mail = (new MailMessage)
            ->subject('【'.Config::string('app.name').'】オートリチャージを有効にしました')
            ->greeting("{$this->organizationName} 様")
            ->line('お支払いカードの設定が完了したため、ご同意いただいた内容でチケットのオートリチャージを有効にしました。')
            ->line(sprintf(
                '設定内容: チケット残高が %d 枚を下回ると、%d 枚になるまで自動購入します。',
                $this->thresholdCount,
                $this->maxCount,
            ));

        if ($this->consentedMaxAmountJpy !== null) {
            $mail->line(sprintf('1 回の自動購入の上限額: ¥%s (税込)', number_format($this->consentedMaxAmountJpy)));
        }

        if ($this->paymentMethodLast4 !== null) {
            $mail->line("お支払いカード: 末尾 {$this->paymentMethodLast4}");
        }

        return $mail
            ->action('請求設定を開く', $this->billingUrl)
            ->line('オートリチャージは請求設定からいつでも停止できます。');
    }

    public function failed(Throwable $e): void
    {
        BillingNotificationRecorder::markFailedByDedupKey($this->deliveryType(), $this->dedupKey, $e);
    }
}
