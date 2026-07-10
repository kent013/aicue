<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * 組織招待。token は平文を保存せず sha256 ハッシュ (token_hash) のみ。
 * email は CipherSweet 暗号化 + blind index。
 * token_hash / organization_id / invited_by_user_id は $fillable 外。
 * 取り消しは行削除ではなく revoked_at による論理失効 (spirux 方式)。
 */
class OrganizationInvitation extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    use UsesCipherSweet;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'role',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * 招待 token (平文) を生成する。URL 埋め込み用途のみで DB には保存しない。
     * DB には hashToken() の sha256 を token_hash 列に保存する。
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * 平文 token を at-rest 保存用の sha256 hash に変換する。
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('email')
            ->addBlindIndex('email', new BlindIndex('email_index'));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isExpired(): bool
    {
        /** @var Carbon $expiresAt */
        $expiresAt = $this->getAttribute('expires_at');

        return $expiresAt->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Active (受諾可能: 未受諾・未失効・期限内) な招待の query scope。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
