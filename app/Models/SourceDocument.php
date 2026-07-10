<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SourceDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SourceDocument (VideoManual 配下の SOP ファイル)。Tier B: schema 先取り。
 * route / Controller / UI は SOP アップロードフェーズで張る (それまで外部到達不可)。
 *
 * - video_manual_id は所有権キーのため $fillable 外 (relation 経由で代入)
 *
 * @property int $id
 * @property int $video_manual_id
 * @property string $file_path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property array<array-key, mixed>|null $extracted_json
 */
class SourceDocument extends Model
{
    /** @use HasFactory<SourceDocumentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'file_path',
        'original_name',
        'mime',
        'size_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extracted_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<VideoManual, $this>
     */
    public function videoManual(): BelongsTo
    {
        return $this->belongsTo(VideoManual::class);
    }
}
