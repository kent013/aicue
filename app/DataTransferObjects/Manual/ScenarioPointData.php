<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Cut;

/**
 * シナリオの急所 (point) 1 行。edit 画面 props / 保存成功応答の共通 shape。
 * TS 側 types/manual.ts の ScenarioPoint と対で保守する。
 * id は常に int (サーバ確定 id)。未保存行はクライアント専用型 (DraftPoint) が担う。
 */
final readonly class ScenarioPointData
{
    public function __construct(
        public int $id,
        public string $scene,
        public string $shotType,
        public ?string $shootingPoint,
        public string $narration,
        public ?string $subtitlePrimary,
        public string $subtitleSecondary,
        public ?string $materialType,
        public ?int $staticDisplaySeconds,
    ) {}

    public static function fromCut(Cut $cut): self
    {
        return new self(
            id: $cut->id,
            scene: $cut->scene,
            shotType: $cut->shot_type->value,
            shootingPoint: $cut->shooting_point,
            narration: $cut->narration,
            subtitlePrimary: $cut->subtitle_primary,
            subtitleSecondary: $cut->subtitle_secondary,
            materialType: $cut->material_type?->value,
            staticDisplaySeconds: $cut->static_display_seconds,
        );
    }

    /**
     * @return array{id: int, scene: string, shot_type: string, shooting_point: string|null,
     *   narration: string, subtitle_primary: string|null, subtitle_secondary: string,
     *   material_type: string|null, static_display_seconds: int|null}
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
            'material_type' => $this->materialType,
            'static_display_seconds' => $this->staticDisplaySeconds,
        ];
    }
}
