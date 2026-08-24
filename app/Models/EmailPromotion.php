<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EmailPromotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * メールアドレスの昇格の確認待ち。
 *
 * ★トークンは**原文を保存せず指紋だけ**。★確定するまでは users のメールではないので
 *   blind index も付けない (引き当てに使う理由が無い)。
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_fingerprint
 * @property string|null $email_encrypted
 * @property CarbonImmutable $expires_at
 */
class EmailPromotion extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<EmailPromotionFactory> */
    use HasFactory;

    use UsesCipherSweet;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = [
        'email_encrypted',
        'token_fingerprint',
    ];

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        // addBlindIndex を **呼ばない** (メールで引く経路を作らない)。
        $encryptedRow->addField('email_encrypted');
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
            'expires_at' => 'immutable_datetime',
        ];
    }
}
