<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Manual\VideoManualStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoManual>
 */
class VideoManualFactory extends Factory
{
    /**
     * project / creator 未指定なら親 Factory に連鎖する。
     * category_id は既定 null (未分類)。forCategory() state で付与する。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'created_by' => User::factory(),
            'category_id' => null,
            'title' => fake()->words(3, true),
            'status' => VideoManualStatus::Draft->value,
            'scenario_version' => 0,
        ];
    }

    /** 指定プロジェクト配下に作る */
    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    /** 指定カテゴリに分類する (project の一致は呼び出し側の責務) */
    public function forCategory(Category $category): static
    {
        return $this->state(fn () => ['category_id' => $category->id]);
    }

    /**
     * 公開済み (レンダ完了) の状態。
     * $totalLengthMs = null は「総尺が記録されていない published 行」の再現
     * (一覧の duration_ms が null になるケース)。
     */
    public function published(?int $totalLengthMs = null): static
    {
        return $this->state(fn (): array => [
            'status' => VideoManualStatus::Published->value,
            'total_length_ms' => $totalLengthMs,
        ]);
    }

    /** 作成者を指定する */
    public function createdBy(User $user): static
    {
        return $this->state(fn () => ['created_by' => $user->id]);
    }
}
