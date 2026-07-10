<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyAbility;
use Database\Factories\ApiKeyFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Webmozart\Assert\Assert;

/**
 * API キー (organization スコープ。Q8 決定: flat ability)。
 *
 * 平文キーは "{slug}_{prefix8}_{secret40}" の 3 分割形式で発行直後に 1 度だけ表示し、
 * DB には secret の Argon2id ハッシュ (key_hash) のみ保存する。key_prefix
 * ("{slug}_{prefix8}") は非機密の検索・表示キーで、認証時の indexed lookup に使う。
 *
 * 認証主体は User ではなく ApiKey 自体 (auth guard 'api-key' が返す principal)。
 * Gate::forUser($apiKey) で認可統合できるよう Authenticatable を実装する。
 *
 * organization_id (ownership) / created_by_user_id (actor) / key_prefix / key_hash
 * (サーバ生成値) は $fillable 外。発行は createForOrganization() が唯一の正規経路。
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property array<array-key, mixed>|null $abilities can() 経由で安全にアクセスすること
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApiKey extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;

    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    /** key_prefix に含める乱数部の長さ (非機密の検索キー) */
    public const PREFIX_RANDOM_LENGTH = 8;

    /** Argon2id でハッシュする secret 部の長さ */
    public const SECRET_RANDOM_LENGTH = 40;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'abilities',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<IdempotencyKey, $this>
     */
    public function idempotencyKeys(): HasMany
    {
        return $this->hasMany(IdempotencyKey::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * 有効期限切れか。scopeActive() の `expires_at > now` と境界条件を揃えるため、
     * expires_at == now は expire 扱いとする。
     */
    public function isExpired(?Carbon $now = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
    }

    public function isActive(?Carbon $now = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($now);
    }

    /**
     * Scope: 失効済み・期限切れを除外する (認証経路で使う。管理一覧では外して全件表示する)。
     *
     * @param  Builder<ApiKey>  $query
     * @return Builder<ApiKey>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }

    /**
     * 指定 ability を持つかを判定する (flat ability。階層なし: write は read を含意しない)。
     *
     * fail-secure: 未知の ability 文字列、abilities カラムの不正型 (DB 不整合) は
     * すべて false を返す (500 化させない)。
     */
    public function can(string $ability): bool
    {
        $value = ApiKeyAbility::tryFrom($ability)?->value;
        if ($value === null) {
            return false;
        }

        $abilities = $this->abilities;
        if (! is_array($abilities)) {
            return false;
        }

        $stringAbilities = array_values(array_filter(
            $abilities,
            static fn (mixed $a): bool => is_string($a),
        ));

        return in_array($value, $stringAbilities, true);
    }

    /**
     * 平文キーを生成する。形式: "{slug}_{prefix8}_{secret40}" (slug = config/template.php)。
     *
     * 平文は呼び出し元が発行直後に 1 度だけ表示し、DB には hashSecret(secret) のみ保存する。
     * prefix は "{slug}_{prefix8}" で、indexed lookup と UI 表示に使う非機密値。
     *
     * @return array{plain: string, prefix: string, secret: string}
     */
    public static function generatePlainKey(): array
    {
        $slug = config('template.slug');
        Assert::stringNotEmpty($slug);

        $prefixRandom = Str::random(self::PREFIX_RANDOM_LENGTH);
        $secret = Str::random(self::SECRET_RANDOM_LENGTH);

        return [
            'plain' => "{$slug}_{$prefixRandom}_{$secret}",
            'prefix' => "{$slug}_{$prefixRandom}",
            'secret' => $secret,
        ];
    }

    /**
     * 平文 secret を Argon2id でハッシュする (key_hash に保存する値)。
     */
    public static function hashSecret(string $secret): string
    {
        return password_hash($secret, PASSWORD_ARGON2ID);
    }

    /**
     * 組織スコープで API キーを発行する named creation method。
     *
     * organization_id / created_by_user_id / key_prefix / key_hash は $fillable 外の
     * ため mass assignment では設定できない。本メソッドが明示代入する唯一の正規経路。
     *
     * @param  list<string>  $abilities  ApiKeyAbility の value のみ
     */
    public static function createForOrganization(
        Organization $organization,
        User $createdBy,
        string $name,
        array $abilities,
        string $keyPrefix,
        string $keyHash,
        ?Carbon $expiresAt = null,
    ): self {
        $apiKey = new self;
        $apiKey->fill([
            'name' => $name,
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);
        $apiKey->organization_id = $organization->id;
        $apiKey->created_by_user_id = $createdBy->id;
        $apiKey->key_prefix = $keyPrefix;
        $apiKey->key_hash = $keyHash;
        $apiKey->save();

        return $apiKey;
    }
}
