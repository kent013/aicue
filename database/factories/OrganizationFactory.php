<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomTeam;
use App\Models\Organization;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $team = new Team;
        $team->name = 'org-'.Str::lower(Str::random(12));
        $team->display_name = $name;
        $team->save();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'laratrust_team_id' => $team->id,
        ];
    }

    /**
     * 不変条件「どの Organization にも Default Team がちょうど 1 つ」を Factory でも担保する
     * (docs/default-team-pattern.md)。
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            if (! $organization->customTeams()->where('is_default', true)->exists()) {
                $defaultTeam = new CustomTeam(['name' => $organization->name]);
                $defaultTeam->organization()->associate($organization);
                $defaultTeam->forceFill(['is_default' => true]);
                $defaultTeam->save();
            }
        });
    }

    public function personal(): static
    {
        return $this->state(fn () => ['is_personal' => true]);
    }
}
