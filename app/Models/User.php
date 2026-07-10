<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Enums\TwoFactorStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laratrust\Contracts\LaratrustUser;
use Laratrust\Traits\HasRolesAndPermissions;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\Transformation\Lowercase;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

class User extends Authenticatable implements CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable
{
    // Passport OAuth guard (mcp-oauth / api-oauth) が withAccessToken() / token() を要求する
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRolesAndPermissions, Notifiable, TwoFactorAuthenticatable, UsesCipherSweet;

    /**
     * PII (email / name) は CipherSweet で暗号化するため、平文 where() では検索できない。
     * email は whereBlind('email', 'email_index', $value)、name は
     * whereBlind('name', 'name_index', $value) を使うこと
     * (認証経路は App\Auth\EncryptedUserProvider が担う)。
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * 同意系 (terms_accepted_at / consent_version) は $fillable 外。
     * 登録 Action が forceFill で明示的に記録する。
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('email')
            ->addBlindIndex('email', new BlindIndex('email_index'));

        // name も blind index 化し、管理画面 (Filament) の暗号化氏名検索を成立させる。
        // blind index は値全体ハッシュ = 完全一致のみ。Lowercase transformer で大文字小文字差を
        // 吸収する (case-insensitive 完全一致)。blind index は共有 blind_indexes morph テーブルに
        // 入るため列 migration は不要。unique 制約は email_index 限定の partial unique のため
        // 非ユニークな name_index (同姓同名) を追加しても安全。
        $encryptedRow
            ->addField('name')
            ->addBlindIndex('name', new BlindIndex('name_index', [new Lowercase]));
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * password が設定されているか (recent-auth の password satisfier 可否)。
     *
     * テンプレート標準では SSO 登録ユーザーにもランダム password が設定されるため常に true
     * だが、password 未設定 (null / 空) を許すアプリはこの判定で SSO-only ユーザーを
     * password 経路から fail-closed で除外できる。
     */
    public function hasPassword(): bool
    {
        // スキーマ上 nullable (SSO-only ユーザーは password 未設定)。
        // raw attribute (mixed) で判定する
        $password = $this->getAttribute('password');

        return is_string($password) && $password !== '';
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withTimestamps();
    }

    /**
     * 組織のメンバーか (pivot 行の存在判定)。
     * REST dual guard の user-token actor 解決が毎リクエスト再検証に使う。
     */
    public function isMemberOf(Organization $organization): bool
    {
        return $this->organizations()->whereKey($organization->getKey())->exists();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /**
     * TOTP 2FA の 3 値状態。secret の有無と confirmed_at で disabled / pending / enabled を
     * 導出する。pending (secret 生成済・TOTP 未確認) を bool で潰すと設定ページ再訪時に
     * secret を再生成して認証アプリ側エントリを無効化してしまう。
     */
    public function twoFactorStatus(): TwoFactorStatus
    {
        if ($this->two_factor_confirmed_at !== null) {
            return TwoFactorStatus::Enabled;
        }

        return $this->getAttribute('two_factor_secret') === null
            ? TwoFactorStatus::Disabled
            : TwoFactorStatus::Pending;
    }

    /**
     * このユーザーが所属する 2FA 必須組織 (最小 id)。現在の 2FA 状態に依存しない単一述語。
     *
     * - RequireTwoFactorForEnforcedOrganizations (未準拠ゲート) は「Enabled でない」判定と
     *   組み合わせて使う
     * - BlockTwoFactorDisableForEnforcedOrganizations (self-disable 禁止) は準拠済み
     *   (Enabled) でも縛りが残るため、状態非依存の本 method を単独で使う
     */
    public function firstTwoFactorRequiringOrganization(): ?Organization
    {
        return $this->organizations()
            ->where('two_factor_required', true)
            ->orderBy('organizations.id')
            ->first();
    }

    /**
     * 組織内ロールの判定 helper。
     * 権限判定は常に組織の laratrust_team_id を明示する (strict_check=true 前提)。
     */
    public function organizationRole(Organization $organization): ?OrganizationRole
    {
        foreach (OrganizationRole::cases() as $role) {
            if ($this->hasRole($role->value, $organization->laratrust_team_id)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // two_factor_secret / two_factor_recovery_codes には `encrypted` cast を追加しない。
        // Fortify の EnableTwoFactorAuthentication Action が明示的に暗号化しており、
        // cast を足すと二重暗号化になって TwoFactorAuthenticatable trait が壊れる
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
