<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

/**
 * 詳細画面の「生成結果の確認」パネルの props。
 *
 * 2 つの出所を **1 つの型に束ねるが混ぜない**:
 * - verdict: LLM が手順書に下した所見 (解析時点のスナップショット。null = 所見なし)
 * - stepCount / pointCount / findings: 現在の cuts から算出した決定的な値 (常に最新)
 */
final readonly class ScenarioReportData
{
    /** @param list<ScenarioRuleFindingData> $findings */
    public function __construct(
        public ?ScenarioVerdictViewData $verdict,
        public int $stepCount,
        public int $pointCount,
        public array $findings,
    ) {}

    /**
     * @return array{verdict: array{verdict: string, reason: string, works: list<string>,
     *   work_count: int, split_recommended: bool, is_current_document: bool}|null,
     *   counts: array{steps: int, points: int, total: int},
     *   findings: list<array{code: string, count: int,
     *     positions: list<array{step: int, point: int|null}>}>}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict?->toArray(),
            'counts' => [
                'steps' => $this->stepCount,
                'points' => $this->pointCount,
                'total' => $this->stepCount + $this->pointCount,
            ],
            'findings' => array_map(
                static fn (ScenarioRuleFindingData $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }

    /** 所見も指摘も無く cut も無い = 出す価値が何も無い (Builder が null を返す判定) */
    public function isEmpty(): bool
    {
        return $this->stepCount === 0 && $this->pointCount === 0 && $this->verdict === null;
    }
}
