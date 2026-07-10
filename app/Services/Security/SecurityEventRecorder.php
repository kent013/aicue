<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use App\Models\User;

/**
 * security_audit_events への記録の唯一の窓口。
 * 監査記録の失敗で主処理を巻き込まない (記録は best-effort、例外は report のみ)。
 */
class SecurityEventRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(SecurityEventType $type, ?User $user, array $metadata = []): void
    {
        try {
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
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
