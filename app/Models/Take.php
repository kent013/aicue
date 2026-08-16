<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Manual\TakeStatus;
use Database\Factories\TakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Take (Cut 配下の撮影素材)。Tier B: schema 先取り。
 * route / Controller / UI は撮影 PWA フェーズで張る (それまで外部到達不可)。
 *
 * - cut_id は所有権キーのため $fillable 外 (relation 経由で代入)
 * - (cut_id, client_take_id) UNIQUE は撮影端末からの同期冪等キー
 * - sort_order はテイク登録 Service が採番するため $fillable 外
 * - downloaded_at はサーバ打刻 (POST .../downloaded の ACK トークン検証経由のみ) のため
 *   $fillable 外。非 null は削除不可 (概念設計 D6)
 * - thumbnail_path / thumbnail_size_bytes は**サーバ生成の会計値**のため $fillable 外。
 *   書き込みは TakeThumbnailPipeline の条件付き UPDATE (query builder) だけである
 *
 * @property int $id
 * @property int $cut_id
 * @property string $client_take_id
 * @property string $video_path
 * @property string|null $thumbnail_path
 * @property int|null $thumbnail_size_bytes
 * @property int $size_bytes
 * @property int|null $duration_ms
 * @property TakeStatus $status
 * @property string|null $comment
 * @property Carbon|null $captured_at
 * @property int $sort_order
 * @property Carbon|null $downloaded_at
 */
class Take extends Model
{
    /** @use HasFactory<TakeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'client_take_id',
        'video_path',
        'size_bytes',
        'duration_ms',
        'comment',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TakeStatus::class,
            'captured_at' => 'datetime',
            'downloaded_at' => 'datetime',
            // 読み取り型を driver 依存にしない (DTO / Resource / PHPStan が int|null で安定する)。
            // size_bytes 側には cast を足さない — 既存の比較箇所への影響を本タスクへ持ち込まないため。
            // 非対称は意図的である
            'thumbnail_size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cut, $this>
     */
    public function cut(): BelongsTo
    {
        return $this->belongsTo(Cut::class);
    }
}
