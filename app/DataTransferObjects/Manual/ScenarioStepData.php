<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\MaterialType;
use App\Models\Cut;

/**
 * シナリオの手順 (step) 1 行 + 配下の急所 (points)。
 * TS 側 types/manual.ts の ScenarioStep と対で保守する。
 */
final readonly class ScenarioStepData
{
    /** @param list<ScenarioPointData> $points */
    public function __construct(
        public int $id,
        public string $scene,
        public string $shotType,
        public ?string $shootingPoint,
        public string $narration,
        public ?string $subtitlePrimary,
        public string $subtitleSecondary,
        public ?MaterialType $materialType,
        public ?int $staticDisplaySeconds,
        public array $points,
    ) {}

    /** @param list<ScenarioPointData> $points */
    public static function fromCut(Cut $cut, array $points): self
    {
        return new self(
            id: $cut->id,
            scene: $cut->scene,
            shotType: $cut->shot_type->value,
            shootingPoint: $cut->shooting_point,
            narration: $cut->narration,
            subtitlePrimary: $cut->subtitle_primary,
            subtitleSecondary: $cut->subtitle_secondary,
            materialType: $cut->material_type,
            staticDisplaySeconds: $cut->static_display_seconds,
            points: $points,
        );
    }

    /**
     * @return array{id: int, scene: string, shot_type: string, shooting_point: string|null,
     *   narration: string, subtitle_primary: string|null, subtitle_secondary: string,
     *   material_type: string|null, static_display_seconds: int|null,
     *   points: list<array{id: int, scene: string, shot_type: string, shooting_point: string|null,
     *     narration: string, subtitle_primary: string|null, subtitle_secondary: string,
     *     material_type: string|null, static_display_seconds: int|null}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scene' => $this->scene,
            'shot_type' => $this->shotType,
            'shooting_point' => $this->shootingPoint,
            'narration' => $this->narration,
            'subtitle_primary' => $this->subtitlePrimary,
            'subtitle_secondary' => $this->subtitleSecondary,
            'material_type' => $this->materialType?->value,
            'static_display_seconds' => $this->staticDisplaySeconds,
            'points' => array_map(
                static fn (ScenarioPointData $point): array => $point->toArray(),
                $this->points,
            ),
        ];
    }
}
