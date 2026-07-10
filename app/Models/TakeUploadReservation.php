<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Capture\TakeUploadReservationStatus;
use Database\Factories\TakeUploadReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * テイクアップロード予約 (概念設計 D2/D3 の真実源)。
 *
 * - cut_id / organization_id は保護キーのため $fillable 外 (relation / forceFill で代入)
 * - bytes_pending = org 単位の「pending & 未失効」+「verifying 全件」の size_bytes 合計
 *   (StorageUsageService::bytesPending)
 * - status 遷移は TakeUploadService (insert) / TakeRegistrationService (claim/CAS) /
 *   StaleUploadReservationSweeper (released 化) のみが行う
 *
 * @property int $id
 * @property int $cut_id
 * @property int $organization_id
 * @property string $client_take_id
 * @property string $video_path
 * @property int $size_bytes
 * @property string $content_type
 * @property string $checksum_sha256
 * @property TakeUploadReservationStatus $status
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TakeUploadReservation extends Model
{
    /** @use HasFactory<TakeUploadReservationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'client_take_id',
        'video_path',
        'size_bytes',
        'content_type',
        'checksum_sha256',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TakeUploadReservationStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Cut, $this>
     */
    public function cut(): BelongsTo
    {
        return $this->belongsTo(Cut::class);
    }

    /**
     * 集計用非正規化キーの relation (可読性・型補助)。
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
