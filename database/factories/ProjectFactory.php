<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * team 未指定なら新規組織の Default Team に割り当てる
     * (docs/default-team-pattern.md の Factory 規約)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'custom_team_id' => fn () => Organization::factory()->create()->defaultTeam()->id,
            'name' => fake()->words(3, true),
            'description' => fake()->realText(40),
        ];
    }

    /** 指定組織の Default Team 配下に作る */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['custom_team_id' => $organization->defaultTeam()->id]);
    }
}
