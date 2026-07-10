<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailSuppressionReason;
use Carbon\CarbonImmutable;
use Database\Factories\EmailSuppressionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * メール送信抑止 (サプレッション) リストの 1 行 = 1 email の抑止状態。
 *
 * SES の Permanent バウンス / 苦情 (complaint) を受けたアドレスを記録し、送信前チェック
 * (FilterSuppressedRecipients) で再送を止める。`email` (正規化平文) を UNIQUE 正本にし、
 * `email_hash` は検索補助 + ログ用 (平文を露出させないため)。APP_KEY rotation でも
 * email 正本は不変なので lookup は壊れない。
 *
 * @property int $id
 * @property string $email
 * @property string $email_hash
 * @property EmailSuppressionReason $reason
 * @property string|null $provider_message_id
 * @property CarbonImmutable $suppressed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class EmailSuppression extends Model
{
    /** @use HasFactory<EmailSuppressionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'email_hash',
        'reason',
        'provider_message_id',
        'suppressed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => EmailSuppressionReason::class,
            'suppressed_at' => 'immutable_datetime',
        ];
    }
}
