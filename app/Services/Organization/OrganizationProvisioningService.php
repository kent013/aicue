<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Enums\OrganizationRole;
use App\Models\CustomTeam;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 組織生成の唯一の窓口。あらゆる経路 (画面 / 登録 / シーダー / Factory) がここを通ることで
 * Default Team パターンの不変条件「どの Organization にも Default Team がちょうど 1 つ」を
 * 構造的に担保する (docs/default-team-pattern.md)。
 */
class OrganizationProvisioningService
{
    /**
     * 組織 + Laratrust Team + Default Team を原子的に生成し、creator を Owner にする。
     */
    public function provision(User $creator, string $name, bool $personal = false): Organization
    {
        return DB::transaction(function () use ($creator, $name, $personal): Organization {
            $team = new Team;
            $team->name = 'org-'.Str::lower(Str::random(12));
            $team->display_name = $name;
            $team->save();

            $organization = new Organization([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
            ]);
            $organization->laratrustTeam()->associate($team);
            $organization->forceFill(['is_personal' => $personal]);
            $organization->save();

            // Default Team (不変条件: 組織ごとにちょうど 1 つ。is_default は $fillable 外)
            $defaultTeam = new CustomTeam(['name' => $name]);
            $defaultTeam->organization()->associate($organization);
            $defaultTeam->forceFill(['is_default' => true]);
            $defaultTeam->save();

            $organization->users()->attach($creator);
            $creator->addRole(OrganizationRole::Owner->value, $organization->laratrust_team_id);

            if ($creator->current_organization_id === null) {
                $creator->forceFill(['current_organization_id' => $organization->id])->save();
            }

            return $organization;
        });
    }

    /**
     * 登録時の個人用組織生成 (冪等: 既に個人組織を持っていれば no-op)。
     */
    public function provisionPersonalOrganization(User $user): Organization
    {
        /** @var Organization|null $existing */
        $existing = $user->organizations()->where('is_personal', true)->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->provision($user, "{$user->name} の組織", personal: true);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';

        return $base.'-'.Str::lower(Str::random(6));
    }
}
