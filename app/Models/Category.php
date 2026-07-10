<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Category (Project 配下の動画マニュアル分類)。
 *
 * - project_id は tenant キーのため $fillable 外 (relation 経由で代入)
 * - sort_order は CategoryService のみが採番/並べ替えする契約のため $fillable 外
 *   (Store/Update payload から触らせない。forceFill で明示代入)
 * - name は project 内ユニーク (複合 unique + Service のロック後再検査)
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property int $sort_order
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<VideoManual, $this>
     */
    public function manuals(): HasMany
    {
        return $this->hasMany(VideoManual::class);
    }
}
