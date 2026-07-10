<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\MaterialType;
use App\Enums\Manual\ShotType;

/**
 * シナリオ保存 payload の急所 (point) 1 行 (id=null は新規行)。
 * UpdateScenarioRequest::toScenarioSaveInput() が validated() から組み上げる
 * (FormRequest → Service の型付き受け渡し)。
 */
final readonly class ScenarioPointInput
{
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
    ) {}
}
