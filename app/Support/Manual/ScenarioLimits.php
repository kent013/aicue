<?php

declare(strict_types=1);

namespace App\Support\Manual;

/**
 * シナリオの有界値 (DoS guard / DB 桁と一致)。
 * 手動保存 (UpdateScenarioRequest) と AI 生成 DTO 検証 (GeneratedScenarioData 等) の共通定数。
 */
final class ScenarioLimits
{
    public const int MAX_STEPS = 100;

    public const int MAX_POINTS_PER_STEP = 20;

    public const int MAX_SCENE_CHARS = 1000;

    public const int MAX_NARRATION_CHARS = 2000;

    /** DB string(100) と一致 */
    public const int MAX_SUBTITLE_PRIMARY_CHARS = 100;

    public const int MAX_SUBTITLE_SECONDARY_CHARS = 2000;
}
