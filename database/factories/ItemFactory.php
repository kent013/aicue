<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * project 未指定なら ProjectFactory に連鎖する (親 Factory 連鎖の規約)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->words(2, true),
            'note' => fake()->realText(30),
        ];
    }

    /** 指定プロジェクト配下に作る */
    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }
}
