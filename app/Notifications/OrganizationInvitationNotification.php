<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 組織招待メール (on-demand: Notification::route('mail', $email) で送る)。
 *
 * acceptUrl には平文 token が載る (DB には sha256 のみ保存)。
 * 受諾はログイン必須のため署名なし URL でよい。
 */
class OrganizationInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $acceptUrl,
    ) {}

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
            ->subject("【{$appName}】「{$this->organizationName}」への招待")
            ->line("{$appName} の組織「{$this->organizationName}」に招待されました。")
            ->line('下のボタンから招待を受諾してください (ログインまたはアカウント登録が必要です)。')
            ->action('招待を受諾する', $this->acceptUrl)
            ->line('この招待の有効期限は 7 日間です。期限を過ぎた場合は再度招待を依頼してください。')
            ->line('心当たりがない場合は、このメールを破棄してください。');
    }
}
