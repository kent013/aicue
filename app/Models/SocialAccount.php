<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SSO プロバイダ (Google 等) との連携アカウント。
 * provider + provider_user_id で一意。token 類は保存しない (都度 OAuth フローを踏む)。
 */
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'provider_user_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
