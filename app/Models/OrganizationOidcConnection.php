<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedSecretCast;
use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Services\EnterpriseSso\OidcConnectionTransitionService;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationOidcConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 組織の OIDC 接続 (企業 IdP との結び付け)。
 *
 * ★`$fillable` は**空**である。生成・更新は
 *   {@see OidcConnectionTransitionService} が明示的に組み立てる
 *   (mass assignment を作らない)。
 * ★`client_secret_encrypted` は {@see EncryptedSecretCast} を通して
 *   {@see ConnectionSecret} でしか出し入れできない (素の文字列を代入する道が型で無い)。
 * ★`credentials_revision` は **cast も `$hidden` も要らない** (秘密ではない)。
 *   ただし画面へ出す DTO には**載せない** — これは D1 の内部の比較子であって、
 *   画面が使う値ではない。
 *
 * @property int $id
 * @property int $organization_id
 * @property string $login_slug
 * @property string $display_name
 * @property string $issuer
 * @property string $client_id
 * @property ConnectionSecret|null $client_secret_encrypted
 * @property OidcConnectionStatus $status
 * @property CarbonImmutable|null $verified_at
 * @property int $credentials_revision
 */
class OrganizationOidcConnection extends Model
{
    /** @use HasFactory<OrganizationOidcConnectionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = [
        'client_secret_encrypted',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<EnterpriseIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(EnterpriseIdentity::class, 'organization_oidc_connection_id');
    }

    /** @return HasMany<EnterpriseSsoLoginAttempt, $this> */
    public function loginAttempts(): HasMany
    {
        return $this->hasMany(EnterpriseSsoLoginAttempt::class, 'organization_oidc_connection_id');
    }

    /**
     * 保存された client secret。
     *
     * ★**復号する唯一の読み出し口**である。一覧の生成 (D2 の DTO) はここを通らない。
     */
    public function clientSecret(): ConnectionSecret
    {
        $secret = $this->client_secret_encrypted;

        if (! $secret instanceof ConnectionSecret) {
            throw new \RuntimeException('接続に client secret が保存されていません。');
        }

        return $secret;
    }

    /** 秘密が保存されているか (★**復号しない**。暗号文の有無だけを見る)。 */
    public function hasClientSecret(): bool
    {
        $raw = $this->getRawOriginal('client_secret_encrypted');

        return is_string($raw) && $raw !== '';
    }

    /**
     * 保存されている暗号文そのものの digest (★復号しない)。
     *
     * D1 の `verify` が「外向き取得の間に秘密が書き換わっていないか」を
     * **平文に触れずに**見るための比較子である。
     */
    public function clientSecretCiphertextDigest(): string
    {
        $raw = $this->getRawOriginal('client_secret_encrypted');

        return hash('sha256', is_string($raw) ? $raw : '');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OidcConnectionStatus::class,
            'verified_at' => 'immutable_datetime',
            'client_secret_encrypted' => EncryptedSecretCast::class,
        ];
    }
}
