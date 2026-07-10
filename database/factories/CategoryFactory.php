<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Webmozart\Assert\Assert;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * project 未指定なら ProjectFactory に連鎖する (親 Factory 連鎖の規約)。
     * name は (project_id, name) 複合 unique のため unique() で生成する。
     * sort_order は $fillable 外だが Factory の属性注入は unguarded で入る
     * (HTTP 入力経路の mass-assignment 制約とは別境界)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        Assert::string($name);

        return [
            'project_id' => Project::factory(),
            'name' => mb_substr($name, 0, 50),
            'sort_order' => 0,
        ];
    }

    /** 指定プロジェクト配下に作る */
    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }
}
