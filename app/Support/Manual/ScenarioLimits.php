<?php

declare(strict_types=1);

namespace App\Support\Manual;

/**
 * シナリオの有界値 (DoS guard / DB 桁と一致)。
 * 手動保存 (UpdateScenarioRequest) と AI 生成 DTO 検証 (GeneratedScenarioData 等) の共通定数。
 */
final class ScenarioLimits
{
    /** LLM 生成/手動編集の「手順 step」上限 (生成 DTO 検証が強制。DoS/桁 guard)。 */
    public const int MAX_STEPS = 100;

    /**
     * 手動保存で許容する top-level cut 総数上限 (生成 100 手順 + 導入/総括 2 の
     * materialized をそのまま再保存できる)。内訳 (通常/定型) は v1 では強制しない。
     */
    public const int MAX_TOP_LEVEL_CUTS = self::MAX_STEPS + 2;

    public const int MAX_POINTS_PER_STEP = 20;

    public const int MAX_SCENE_CHARS = 1000;

    public const int MAX_NARRATION_CHARS = 2000;

    /** DB string(100) と一致 */
    public const int MAX_SUBTITLE_PRIMARY_CHARS = 100;

    public const int MAX_SUBTITLE_SECONDARY_CHARS = 2000;
}
