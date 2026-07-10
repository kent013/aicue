<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Manual\CutType;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\ShotType;
use Database\Factories\CutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cut (VideoManual 配下のシナリオカット)。Tier B: schema 先取り。
 * route / Controller / UI はシナリオ編集フェーズで張る (それまで外部到達不可)。
 *
 * - video_manual_id / parent_cut_id / adopted_take_id は保護キーのため $fillable 外
 * - 後続フェーズの必須条件: adopted_take_id は cut->takes() 経由でのみ解決 (cross-cut は 404)、
 *   parent_cut_id は同一 video_manual 所属を relation 経由で解決 (cross-manual は 404)
 *
 * @property int $id
 * @property int $video_manual_id
 * @property int|null $parent_cut_id
 * @property int|null $adopted_take_id
 * @property CutType $type
 * @property ShotType $shot_type
 * @property MaterialType|null $material_type
 * @property int $sort_order
 * @property string $scene
 * @property string|null $shooting_point
 * @property string $narration
 * @property string|null $subtitle_primary
 * @property string $subtitle_secondary
 * @property int|null $static_display_seconds
 * @property int|null $cut_length_ms
 */
class Cut extends Model
{
    /** @use HasFactory<CutFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'shot_type',
        'material_type',
        'sort_order',
        'scene',
        'shooting_point',
        'narration',
        'subtitle_primary',
        'subtitle_secondary',
        'static_display_seconds',
        'cut_length_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CutType::class,
            'shot_type' => ShotType::class,
            'material_type' => MaterialType::class,
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
     * 急所カットの親手順カット (自己参照)。
     *
     * @return BelongsTo<Cut, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_cut_id');
    }

    /**
     * @return HasMany<Take, $this>
     */
    public function takes(): HasMany
    {
        return $this->hasMany(Take::class);
    }

    /**
     * 採用テイク (循環 FK。採用 API は cut->takes() 経由解決を必須とする =
     * CaptureTakeService::adopt。書き込み経路は ScenarioWritePathInventoryTest 検出 4)。
     *
     * @return BelongsTo<Take, $this>
     */
    public function adoptedTake(): BelongsTo
    {
        return $this->belongsTo(Take::class, 'adopted_take_id');
    }

    /**
     * テイクアップロード予約 (概念設計 D2。予約行は本 relation 経由でのみ再解決する)。
     *
     * @return HasMany<TakeUploadReservation, $this>
     */
    public function uploadReservations(): HasMany
    {
        return $this->hasMany(TakeUploadReservation::class);
    }
}
