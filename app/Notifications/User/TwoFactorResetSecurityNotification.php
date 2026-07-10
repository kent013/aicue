<?php

declare(strict_types=1);

namespace App\Notifications\User;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 組織管理者によって 2FA が解除された際、対象ユーザー本人へ送るセキュリティ通知。
 *
 * 2FA 解除はアカウント全体の第二要素を外す操作のため、本人が必ず検知できるようにする
 * (EmailChangedSecurityNotification と同じ「乗っ取り・誤操作の検知導線」防御)。
 * 心当たりがない場合の即時パスワード変更を案内する (悪用時の封じ込め初期緩和)。
 */
class TwoFactorResetSecurityNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $organizationName) {}

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
            ->subject("【{$appName}】2 段階認証が解除されました")
            ->line("組織「{$this->organizationName}」の管理者により、お客様のアカウントの 2 段階認証が解除されました。")
            ->line('このアカウントは現在、パスワード (またはソーシャルログイン) のみで保護されています。')
            ->line('お心当たりがある場合: セキュリティ設定画面からいつでも 2 段階認証を再設定できます。')
            ->line('お心当たりがない場合: ただちにパスワードを変更し、サポートまでご連絡ください。');
    }
}
