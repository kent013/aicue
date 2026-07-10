<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectRole;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * プロジェクト。CustomTeam 配下固定 (custom_team_id NOT NULL)。
 * teams_visible=false のアプリでは組織の Default Team に自動所属する。
 * custom_team_id は所有権キーのため $fillable 外 (Service が明示代入)。
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<CustomTeam, $this>
     */
    public function customTeam(): BelongsTo
    {
        return $this->belongsTo(CustomTeam::class);
    }

    /**
     * @return HasOneThrough<Organization, CustomTeam, $this>
     */
    public function organization(): HasOneThrough
    {
        return $this->hasOneThrough(
            Organization::class,
            CustomTeam::class,
            'id',
            'id',
            'custom_team_id',
            'organization_id',
        );
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * 動画マニュアル。relation 名は route パラメータ {manual} の scopeBindings 推論
     * ($project->manuals()) と一致させる (IDOR 防御の前提。videoManuals() は使わない)。
     *
     * @return HasMany<VideoManual, $this>
     */
    public function manuals(): HasMany
    {
        return $this->hasMany(VideoManual::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function memberRole(User $user): ?ProjectRole
    {
        /** @var User|null $member */
        $member = $this->members()->whereKey($user->getKey())->first();
        $pivot = $member?->getRelationValue('pivot');
        $role = $pivot instanceof Pivot
            ? $pivot->getAttribute('role')
            : null;

        return is_string($role) ? ProjectRole::from($role) : null;
    }
}
