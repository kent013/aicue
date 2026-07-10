<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomTeam;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomTeam>
 */
class CustomTeamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->word().'部',
            'is_default' => false,
        ];
    }
}
