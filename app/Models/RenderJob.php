<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;
use App\Models\Billing\TicketReservation;
use Database\Factories\RenderJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * RenderJob (VideoManual 配下のレンダ / プレビュージョブ)。doc/10 §10.1。
 *
 * - video_manual_id / ticket_reservation_id は保護キーのため $fillable 外
 * - status / step / progress / kind / scenario_version / output_path / error / error_code は
 *   RenderJobService / RenderPipeline が管理する状態のため $fillable を持たない
 *   (AnalysisJob と同じ明示代入のみの規約)
 *
 * @property int $id
 * @property int $video_manual_id
 * @property RenderKind $kind
 * @property JobStatus $status
 * @property RenderStep|null $step
 * @property int|null $progress
 * @property int $scenario_version
 * @property int|null $ticket_reservation_id
 * @property int|null $triggered_by
 * @property string|null $output_path
 * @property string|null $error
 * @property RenderErrorCode|null $error_code
 * @property int|null $scenario_version_at_terminal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RenderJob extends Model
{
    /** @use HasFactory<RenderJobFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RenderKind::class,
            'status' => JobStatus::class,
            'step' => RenderStep::class,
            'progress' => 'integer',
            'scenario_version' => 'integer',
            'error_code' => RenderErrorCode::class,
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
