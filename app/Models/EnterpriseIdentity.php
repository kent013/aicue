<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EnterpriseIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * IdP の身元 (接続 × subject) と利用者の対応。
 *
 * @property int $id
 * @property int $organization_oidc_connection_id
 * @property int $user_id
 * @property string $subject
 * @property string|null $claimed_email_encrypted
 * @property CarbonImmutable|null $last_login_at
 */
class EnterpriseIdentity extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<EnterpriseIdentityFactory> */
    use HasFactory;

    use UsesCipherSweet;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = [
        'claimed_email_encrypted',
    ];

    /**
     * ★**メールアドレスで利用者を引かない** (正典 v1 / I1)。
     *   引き当ての鍵は **(organization_oidc_connection_id, 生の subject)** だけである
     *   (subject 列は `COLLATE "C"` で byte 一致。**指紋にしない** = 鍵のローテーションに依存しない)。
     *   申告メールは暗号化して持つが **blind index を付けない** —
     *   索引があると「メールで引ける」経路が復活する。
     *   これは tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest が
     *   記法の走査と **「申告メールを含む索引が 0 本」のスキーマ検査** の二層で固定する。
     */
    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        // ★列は nullable なので `addOptionalTextField` を使う
        //   (`addField` は null で fieldNotOptional 例外になる = Inquiry / Organization の先例)。
        //   メールを出さない IdP があるため null は正常な値である。
        // ★addBlindIndex を **呼ばない**。これが不変条件の実体である。
        $encryptedRow->addOptionalTextField('claimed_email_encrypted');
    }

    /** @return BelongsTo<OrganizationOidcConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(OrganizationOidcConnection::class, 'organization_oidc_connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
