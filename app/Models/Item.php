<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item (Project 配下のサンプルリソース)。テンプレート規約を満たした「正しい追加」の見本。
 *
 * project_id は tenant キー (MassAssignmentProtectedKeys に登録済み) のため $fillable 外。
 * 代入は必ず relation 経由 ($project->items()->create(...)) で行う。
 */
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'note',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
