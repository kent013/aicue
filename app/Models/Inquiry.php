<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Inquiry\InquirySource;
use App\Enums\Inquiry\InquiryStatus;
use App\Enums\Inquiry\InquiryType;
use Carbon\Carbon;
use Database\Factories\InquiryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * 公開問い合わせフォームの受付レコード。
 *
 * PII (name / email / company_name / message) は CipherSweet で暗号化保存し、
 * email の検索は必ず whereBlind('email', 'inquiry_email_index', $normalized) を使う
 * (保存時に EmailNormalizer で正規化するため、検索入力も同一正規化を通すこと)。
 * 削除は DeleteInquiryAction 経由の model 単位 hard delete のみ
 * (query builder 一括 delete は CipherSweet deleting フック非発火のため禁止)。
 *
 * @property int $id
 * @property InquiryType $type
 * @property InquiryStatus $status
 * @property InquirySource|null $source
 * @property string $name
 * @property string $email
 * @property string|null $company_name
 * @property string $message
 * @property int|null $assigned_admin_id
 * @property string|null $internal_note
 * @property Carbon|null $terms_accepted_at
 * @property string|null $consent_version
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Inquiry extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<InquiryFactory> */
    use HasFactory;

    use UsesCipherSweet;

    /**
     * 公開フォーム入力列のみ fillable。運用列 (status / assigned_admin_id /
     * internal_note / closed_at / terms_accepted_at / consent_version) は guarded とし、
     * CreateInquiryAction / Filament が明示代入する。owner key は存在しない (組織非依存)。
     *
     * @var list<string>
     */
    protected $fillable = ['type', 'source', 'name', 'email', 'company_name', 'message'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => InquiryType::class,
            'status' => InquiryStatus::class,
            'source' => InquirySource::class,
            'terms_accepted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * 担当 Admin (任意割当)。assigned_admin_id は actor 系のため $fillable 外、
     * Filament / コードで明示代入する。
     *
     * @return BelongsTo<AdminUser, $this>
     */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('name')
            ->addField('email')
            // company_name は任意 (nullable)。addField (TYPE_TEXT) は null で
            // EncryptedRow::encryptRow が fieldNotOptional 例外を投げ contact.store が
            // 500 になるため、optional 型で null を素通しする。
            ->addOptionalTextField('company_name')
            ->addField('message')
            ->addBlindIndex('email', new BlindIndex('inquiry_email_index'));
    }

    /**
     * status→Closed 遷移時に closed_at を自動集約する (reopen で null に戻す)。
     *
     * updating フックなので新規 INSERT 時には発火しない (新規は Action が status を明示代入)。
     * 注意: bulk update (`Inquiry::query()->update([...])`) は Eloquent イベントを通らないため
     * 本フックが発火しない。status 変更は Filament の handleRecordUpdate (model 単位 save) と
     * CreateInquiryAction のみで行い、bulk update は使わない。
     */
    protected static function booted(): void
    {
        static::updating(function (Inquiry $inquiry): void {
            if (! $inquiry->isDirty('status')) {
                return;
            }

            if ($inquiry->status === InquiryStatus::Closed && $inquiry->closed_at === null) {
                $inquiry->closed_at = now();

                return;
            }

            if ($inquiry->status !== InquiryStatus::Closed) {
                $inquiry->closed_at = null;
            }
        });
    }
}
