<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Enums\Auth\AuthMethodChangeEvent;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * 認証手段 (パスワード・2FA・パスキー・SSO 連携) の変更を本人へ知らせるセキュリティ通知 (T110)。
 *
 * 対象・発火点・保証しないものの正本は docs/architecture.md
 * §認証手段変更のメール通知ポリシー。秘密情報 (トークン・コード・パスキーの識別子詳細) は
 * 一切載せない。配信先は送信時点 (worker 実行時) の現在の登録メールアドレス —
 * queued notification を包む queue job 側の直列化 (Illuminate の標準機構。個別の実装は
 * 持たない) が worker 実行時に User を ID から再取得するため、CipherSweet の復号も
 * 通常どおり働く。
 *
 * queue 投入自体の失敗を吸収する契約は本クラスではなく呼び出し元
 * (`App\Services\Security\AuthMethodChangeNotifier`) が持つ。
 */
class AuthMethodChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly AuthMethodChangeEvent $event,
        private readonly CarbonImmutable $occurredAt,
        private readonly ?string $context = null,
    ) {}

    /** イベント種別。テストで enum とメール内容の対応を直接固定するための getter。 */
    public function event(): AuthMethodChangeEvent
    {
        return $this->event;
    }

    /** 発生時刻。テスト用 getter。 */
    public function occurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }

    /** SSO 連携時の provider 表示名等。テスト用 getter。 */
    public function context(): ?string
    {
        return $this->context;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = Config::string('app.name');
        $headline = $this->event->headline();
        $occurredAtLabel = $this->occurredAt->timezone('Asia/Tokyo')->isoFormat('YYYY-MM-DD HH:mm');

        $detail = $this->event === AuthMethodChangeEvent::SocialAccountLinked
            ? sprintf('外部ログイン (%s) が連携されました。', $this->context ?? '外部サービス')
            : "{$headline}。";

        return (new MailMessage)
            ->subject("【{$appName}】{$headline}")
            ->line("お使いの {$appName} アカウントで次の変更がありました。")
            ->line($detail)
            ->line("変更時刻: {$occurredAtLabel} (JST)")
            ->line('ご自身の操作であれば対応不要です。')
            ->line('心当たりがない場合は、直ちにパスワードを再設定し、サポートまでご連絡ください。')
            ->action('パスワードを再設定する', route('password.request'));
    }
}
