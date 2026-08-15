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
     * 「資格情報は失効したが、その事実が監査に残っていない」状態を作らないための版である。
     * 組織アクセスの失効 ({@see OrganizationAccessRevoker}) だけがこれを使う。
     * 認証系の記録 (ログイン失敗など) にこれを使ってはならない —
     * 監査の失敗でログインそのものを落とすことになるためである。
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
