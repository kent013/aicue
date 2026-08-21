<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\Auth\AuthMethodChangeEvent;
use App\Models\User;
use App\Notifications\Auth\AuthMethodChangedNotification;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * 認証手段変更通知 (T110) の発火の唯一の窓口。
 *
 * `SecurityEventRecorder::record()` と同型の best-effort 契約 — 通知の queue 投入失敗
 * (DB 接続断等) が呼び出し元の認証操作を失敗させないよう、例外は `report()` して継続する。
 *
 * **呼び出し元がどの transaction 文脈にいるかは本クラスの関心事ではない**。
 * `notify()` はその場で `$user->notify()` を呼ぶだけであり、キュー投入の原子性
 * (AGENTS.md ドメイン規約 11) を満たすかどうかは呼び出し元の文脈で決まる —
 * 業務トランザクションの内側から呼ばれれば queue の `jobs` 行がそのトランザクションに
 * 参加し (`config/queue.php` の既定接続は `after_commit=false`)、トランザクション外
 * (vendor イベントが業務トランザクションの外で発火する経路) から呼ばれれば
 * その場で即時 enqueue される。いずれの場合も afterCommit の類には依存しない。
 */
class AuthMethodChangeNotifier
{
    /**
     * 受けた文脈のままその場で queue へジョブを投入する (best-effort)。
     * 実際のメール配送は worker が非同期に行う。
     */
    public function notify(User $user, AuthMethodChangeEvent $event, ?string $context = null): void
    {
        try {
            $user->notify(new AuthMethodChangedNotification($event, CarbonImmutable::now(), $context));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
