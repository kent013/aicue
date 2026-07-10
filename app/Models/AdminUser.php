<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdminUserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * Filament 管理画面専用の運用者アカウント (admin guard。一般 User とは別モデル・別テーブル)。
 *
 * PII (name / email) は User と同様 CipherSweet で暗号化する。email は平文 where() では
 * 検索できないため、必ず whereBlind('email', 'email_index', $value) を使うこと
 * (認証経路は config/auth.php providers.admin_users.driver = 'encrypted-eloquent' が担う)。
 * email の一意性は blind_indexes テーブル側の partial unique index
 * (blind_indexes_type_name_value_unique) で DB 層でも担保する。
 *
 * MFA (TOTP) は Filament 標準の app authentication を使う。secret / recovery codes は
 * サーバ管理の secret のため $fillable 外 + hidden + encrypted cast で保持する。
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $app_authentication_secret
 * @property array<string>|null $app_authentication_recovery_codes
 */
class AdminUser extends Authenticatable implements CipherSweetEncrypted, FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory;

    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Notifiable;
    use UsesCipherSweet;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /**
     * CipherSweet の暗号化フィールド定義 (User と同じ構成)。
     * email は blind index (email_index) で exact-match 検索できる。
     */
    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('email')
            ->addBlindIndex('email', new BlindIndex('email_index'));

        $encryptedRow->addField('name');
    }

    /**
     * admin guard で認証済みの AdminUser は全員 panel にアクセスできる
     * (運用者ロール分割が必要になったらここで判定を足す)。
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            // MFA secret は暗号化して保持 (trait の mergeCasts と同値だが契約として明示)
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }
}
