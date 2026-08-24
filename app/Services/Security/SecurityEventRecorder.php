<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Services\OAuth\OrganizationAccessRevoker;

/**
 * security_audit_events への記録の唯一の窓口。
 *
 * 既定 ({@see record()}) は best-effort で、記録の失敗が主処理を巻き込まない。
 * 失効の監査だけは握り潰さない版 ({@see recordOrFail()}) を使う。
 */
class SecurityEventRecorder
{
    /**
     * 監査記録 (best-effort)。**既存の意味は変えない** — 記録の失敗で主処理を巻き込まない。
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(SecurityEventType $type, ?User $user, array $metadata = []): void
    {
        try {
            $this->write($type, $user, $metadata);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * 監査記録 (握り潰さない)。**書けなければ呼び出し元のトランザクションごと巻き戻る**。
     *
     * 「状態は変わったのに、その事実が監査に残っていない」を作らないための版である。
     * 使ってよいのは**確定した変更を同じトランザクションの中で記録する経路**だけである:
     *
     * - 組織アクセスの失効 ({@see OrganizationAccessRevoker})
     *   — 「資格情報は失効したが監査に残っていない」を作らない
     * - メールアドレスの昇格の確定 (`App\Services\Auth\EmailPromotionService` の第 2 段)
     *   — 「メールは変わったが監査に残っていない」を作らない
     *
     * ★2 件目を `{@see}` で書かないのは、`fully_qualified_strict_types` が
     *   **`use` 文を生成してしまう**ためである。この窓口は退会 (アカウント削除) 経路の
     *   依存閉包に入っており (`AccountDeletionPathGateTest` 検査 1)、
     *   参照を 1 つ足すだけで昇格まわりのクラス 14 件が閉包へ流れ込む。
     *   **説明のための言及が本物の依存になってはいけない**。
     *
     * 逆に、**観測でしかない記録**にこれを使ってはならない。ログイン失敗・
     * ログイン成功のような認証の試行そのものの記録は {@see record()} を使う —
     * 監査の失敗でログインそのものを落とすことになるためである。
     * (昇格の確定はログインの経路ではなく、**利用者の属性を変える操作**である。)
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordOrFail(SecurityEventType $type, ?User $user, array $metadata = []): void
    {
        $this->write($type, $user, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function write(SecurityEventType $type, ?User $user, array $metadata): void
    {
        $event = new SecurityAuditEvent([
            'event_type' => $type->value,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => request()->ip(),
            'occurred_at' => now(),
        ]);
        if ($user !== null) {
            $event->user()->associate($user);
        }
        $event->save();
    }
}
