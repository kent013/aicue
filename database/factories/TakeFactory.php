<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Manual\MaterialType;
use App\Enums\Manual\TakeStatus;
use App\Models\Cut;
use App\Models\Take;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Take>
 */
class TakeFactory extends Factory
{
    /**
     * cut 未指定なら CutFactory に連鎖する (親 Factory 連鎖の規約)。
     * client_take_id は撮影端末生成の ULID (同期冪等キー) を模す。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cut_id' => Cut::factory(),
            'client_take_id' => (string) Str::ulid(),
            'video_path' => 'takes/'.fake()->uuid().'.mp4',
            // 既定は動画 (既存テイクは全件動画。既存テストの意味を変えない)
            'material_type' => MaterialType::Video->value,
            'thumbnail_path' => null,
            'thumbnail_size_bytes' => null,
            'size_bytes' => fake()->numberBetween(100_000, 50_000_000),
            'duration_ms' => fake()->numberBetween(1_000, 60_000),
            'status' => TakeStatus::Ready->value,
            'comment' => null,
            'captured_at' => null,
            'sort_order' => 0,
        ];
    }

    /** 指定カット配下に作る */
    public function forCut(Cut $cut): static
    {
        return $this->state(fn () => ['cut_id' => $cut->id]);
    }

    /** 静止画テイク (画像キー + duration_ms は null) */
    public function still(): static
    {
        return $this->state(fn (): array => [
            'video_path' => 'takes/'.fake()->uuid().'.jpg',
            'material_type' => MaterialType::Still->value,
            'duration_ms' => null,
        ]);
    }

    /** サムネイル生成済み (容量集計・一覧表示のテスト用) */
    public function withThumbnail(int $sizeBytes = 40_000): static
    {
        return $this->state(fn (): array => [
            'thumbnail_path' => 'takes/thumbnails/'.fake()->uuid().'.jpg',
            'thumbnail_size_bytes' => $sizeBytes,
        ]);
    }

    /** DL 済み ACK 打刻済み (削除不可) の状態 (概念設計 D6) */
    public function downloaded(): static
    {
        return $this->state(fn () => ['downloaded_at' => now()]);
    }
}
