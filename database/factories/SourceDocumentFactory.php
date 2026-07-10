<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SourceDocument;
use App\Models\VideoManual;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceDocument>
 */
class SourceDocumentFactory extends Factory
{
    /**
     * videoManual 未指定なら VideoManualFactory に連鎖する (親 Factory 連鎖の規約)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_manual_id' => VideoManual::factory(),
            'file_path' => 'source-documents/'.fake()->uuid().'.pdf',
            'original_name' => fake()->word().'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1_000, 5_000_000),
            'extracted_json' => null,
        ];
    }

    /** 指定マニュアル配下に作る */
    public function forManual(VideoManual $manual): static
    {
        return $this->state(fn () => ['video_manual_id' => $manual->id]);
    }
}
