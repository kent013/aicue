<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * メールアドレス変更時に「旧アドレス」へ送るセキュリティ通知。
 *
 * 新アドレスは旧アドレス保持者に開示しない (説明は最小限・検知導線のみ)。
 * 本人による変更なら無視してよく、心当たりがなければ問い合わせへ誘導する。
 */
class EmailChangedSecurityNotification extends Notification
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config()->string('app.name');

        return (new MailMessage)
            ->subject("【{$appName}】メールアドレスが変更されました")
            ->line("お使いの {$appName} アカウントのメールアドレスが変更されました。")
            ->line('このメールは変更前のメールアドレスにお送りしています。')
            ->line('ご自身で変更された場合、このメールに対応は不要です。')
            ->line('心当たりがない場合は、アカウントが不正に操作された可能性があります。至急サポートまでご連絡ください。');
    }
}
