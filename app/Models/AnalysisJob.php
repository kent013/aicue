<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Manual\AnalysisStep;
use App\Enums\Manual\JobStatus;
use App\Models\Billing\TicketReservation;
use Database\Factories\AnalysisJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AnalysisJob (VideoManual 配下の AI 解析ジョブ)。doc/10 §10.1。
 *
 * - video_manual_id / source_document_id / ticket_reservation_id は保護キーのため $fillable 外
 * - status / step / progress / result_json / error は AnalysisJobService / AnalysisPipeline が
 *   管理する状態のため $fillable を持たない (TicketReservation と同じ明示代入のみの規約)
 *
 * @property int $id
 * @property int $video_manual_id
 * @property int|null $source_document_id
 * @property JobStatus $status
 * @property AnalysisStep|null $step
 * @property int|null $progress
 * @property int|null $ticket_reservation_id
 * @property int|null $triggered_by
 * @property array<array-key, mixed>|null $result_json
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AnalysisJob extends Model
{
    /** @use HasFactory<AnalysisJobFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'step' => AnalysisStep::class,
            'progress' => 'integer',
            'result_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<VideoManual, $this>
     */
    public function videoManual(): BelongsTo
    {
        return $this->belongsTo(VideoManual::class);
    }

    /**
     * @return BelongsTo<SourceDocument, $this>
     */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class);
    }

    /**
     * @return BelongsTo<TicketReservation, $this>
     */
    public function ticketReservation(): BelongsTo
    {
        return $this->belongsTo(TicketReservation::class);
    }

    /**
     * ジョブ実行者 (通知宛先の導出用。Auth からの明示代入のみ = 保護キー)。
     *
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
