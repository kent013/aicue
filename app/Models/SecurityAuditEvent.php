<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SecurityEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 認証・セキュリティイベントの監査ログ (監査 3 層の Layer 2)。
 * 記録は App\Services\Security\SecurityEventRecorder 経由のみとする。
 * user_id は所有権キーのため $fillable 外 (relation 経由で設定)。
 */
class SecurityAuditEvent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'event_type',
        'metadata',
        'ip_address',
        'occurred_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => SecurityEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
