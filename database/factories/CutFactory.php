<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Manual\CutType;
use App\Enums\Manual\ShotType;
use App\Models\Cut;
use App\Models\VideoManual;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cut>
 */
class CutFactory extends Factory
{
    /**
     * videoManual 未指定なら VideoManualFactory に連鎖する (親 Factory 連鎖の規約)。
     * parent_cut_id / adopted_take_id は既定 null (フェーズ1では常に null)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_manual_id' => VideoManual::factory(),
            'parent_cut_id' => null,
            'adopted_take_id' => null,
            'type' => CutType::Step->value,
            'shot_type' => ShotType::Hiki->value,
            'material_type' => null,
            'sort_order' => 0,
            'scene' => fake()->realText(30),
            'shooting_point' => null,
            'narration' => fake()->realText(40),
            'subtitle_primary' => null,
            'subtitle_secondary' => fake()->realText(30),
            'static_display_seconds' => null,
            'cut_length_ms' => null,
        ];
    }

    /** 指定マニュアル配下に作る */
    public function forManual(VideoManual $manual): static
    {
        return $this->state(fn () => ['video_manual_id' => $manual->id]);
    }
}
