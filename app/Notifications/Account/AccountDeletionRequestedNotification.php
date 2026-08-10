<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\DataTransferObjects\Account\AccountDeletionStateDto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * 退会 (猶予期間つき削除) を予約したことのメール通知。
 *
 * 本人が意図していない予約 (セッション奪取 / 誤操作) に**気づく**ための経路であり、
 * 取消の期日と導線を必ず載せる。
 *
 * 【`ShouldQueue` + 予約 tx 内 dispatch】
 * AGENTS.md ドメイン規約 11 に従い、業務状態の保存とキュー投入は同一トランザクション内で行う
 * (`afterCommit` に依存しない)。`ShouldBeUnique` は使わない — unique lock は dispatch 時に
 * 取得され rollback で解放されないため業務 tx 内 dispatch と両立しない
 * (`AutoRechargeTriggerJob` から撤去済みの先例がある)。送達台帳も新設しない。
 *
 * 【保証範囲 (誇張しない)】
 * 保証するのは **「予約操作からの job 生成は最大 1 件」**だけである
 * (`OrganizationMembershipService::requestAccountDeletion()` が予約中なら冪等 no-op で
 * 通知を発火しないため、二重 POST でも job は 1 つしか作られない)。
 * **job の実行と外部配送は重複しうる best-effort** — 外部メールサービスが受理した後に
 * worker が完了記録の前で停止すれば retry で再送されうる。「at-most-once」ではないし、
 * 「同一 payload の job を 2 つ投入しても 1 通」でもない。
 * 再確認は**秒精度**の値一致で行うため、**同一秒内の取消 → 再予約**は区別できない
 * (ただしその場合は新旧の期日が同一なので、誤った期日が届くことはない)。
 */
final class AccountDeletionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CarbonImmutable $requestedAt,
        private readonly CarbonImmutable $purgeAfter,
    ) {}

    /**
     * 送信直前に予約の生存を再確認する。**これは誤通知の防止であって dedup ではない**。
     *
     * dispatch の位置だけでは誤通知を防げない — 「dispatch がどこか」と「job が参照する状態・
     * 実行可能時点」は別問題である。aicue は `QueueDispatchAtomicityGuard` が
     * driver=database / キュー DB = 業務 DB / after_commit=false を全環境の起動時に
     * fail-closed 検査するため commit 前実行は構造的に起きないが、**それは前提であって
     * 保証ではない**。
     *
     * ★**フォールバックしない**。`fresh()` が null = 執行済みで user 行が無い、という意味なので、
     *   シリアライズ済みの削除前スナップショットへ倒すと「執行済みなのに送る」逆転が起きる。
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $fresh = $notifiable->fresh();
        if (! $fresh instanceof User) {
            return [];
        }

        return AccountDeletionStateDto::fromUser($fresh)->matches($this->requestedAt, $this->purgeAfter)
            ? ['mail']
            : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = Config::string('app.name');
        $deadline = $this->purgeAfter->format('Y年n月j日 H:i');

        return (new MailMessage)
            ->subject('【'.$appName.'】退会のお手続きを受け付けました')
            ->line("{$appName} の退会 (アカウント削除) を受け付けました。")
            ->line("削除を実行する予定日時: {$deadline}")
            ->line('それまでは設定画面からいつでも取り消せます。心当たりがない場合は、'
                .'取り消したうえでパスワードの変更をご検討ください。')
            ->action('退会を取り消す', route('settings'))
            ->line('削除後はデータを復元できません。');
    }
}
