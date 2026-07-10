<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomTeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 部門 (CustomTeam)。組織内のプロジェクトのグルーピングであり、
 * **認可のスコープではない** (認可の軸は Organization の Laratrust team と
 * project_members のみ。docs/default-team-pattern.md)。
 *
 * teams_visible=false のアプリでは Default Team (is_default=true) のみが存在し
 * UI に露出しない。is_default / organization_id は $fillable 外。
 */
class CustomTeam extends Model
{
    /** @use HasFactory<CustomTeamFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
