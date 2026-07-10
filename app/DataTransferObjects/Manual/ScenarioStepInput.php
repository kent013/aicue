<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\MaterialType;
use App\Enums\Manual\ShotType;

/**
 * シナリオ保存 payload の手順 (step) 1 行 + 配下の急所 (id=null は新規行)。
 */
final readonly class ScenarioStepInput
{
    /** @param list<ScenarioPointInput> $points */
    public function __construct(
        public ?int $id,
        public string $scene,
        public ShotType $shotType,
        public ?string $shootingPoint,
        public string $narration,
        public ?string $subtitlePrimary,
        public string $subtitleSecondary,
        public ?MaterialType $materialType,
        public ?int $staticDisplaySeconds,
        public array $points,
    ) {}
}
