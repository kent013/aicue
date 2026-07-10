<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Cut;
use App\Models\VideoManual;
use Illuminate\Support\Collection;

/**
 * シナリオ document 全体 (steps→points ツリー + 楽観ロック version)。
 * edit 画面の Inertia props と保存成功応答の共通 shape で、
 * fromManual() が sort_order 順に step/point を組み上げる唯一の変換点。
 */
final readonly class ScenarioDocumentData
{
    /** @param list<ScenarioStepData> $steps */
    public function __construct(
        public int $scenarioVersion,
        public array $steps,
    ) {}

    public static function fromManual(VideoManual $manual): self
    {
        // 1 パス整形: parent_cut_id で groupBy し O(n) で組み上げる (per-step where の O(n^2) 回避)。
        // トップレベル (parent_cut_id = null) は key 0 に寄せる (cut id は 1 始まりのため衝突しない)
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->orderBy('sort_order')->get();
        /** @var Collection<int, Collection<int, Cut>> $grouped */
        $grouped = $cuts->toBase()->groupBy(static fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
        /** @var Collection<int, Cut> $empty */
        $empty = new Collection;

        $steps = [];
        foreach ($grouped->get(0) ?? $empty as $step) {
            $points = array_values(($grouped->get($step->id) ?? $empty)
                ->map(static fn (Cut $cut): ScenarioPointData => ScenarioPointData::fromCut($cut))
                ->all());
            $steps[] = ScenarioStepData::fromCut($step, $points);
        }

        return new self($manual->scenario_version, $steps);
    }

    /**
     * @return array{scenario_version: int, steps: list<array{id: int, scene: string,
     *   shot_type: string, shooting_point: string|null, narration: string,
     *   subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
     *   static_display_seconds: int|null, points: list<array{id: int, scene: string,
     *     shot_type: string, shooting_point: string|null, narration: string,
     *     subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
     *     static_display_seconds: int|null}>}>}
     */
    public function toArray(): array
    {
        return [
            'scenario_version' => $this->scenarioVersion,
            'steps' => array_map(
                static fn (ScenarioStepData $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }
}
