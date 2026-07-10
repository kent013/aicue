<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual\Analysis;

use App\Support\Manual\LlmJson;
use App\Support\Manual\ScenarioLimits;

/**
 * work-decomposition プロンプトの出力 (`{ steps: [{ no, action, points[] }] }`) の検証済み DTO。
 * 有界性 (steps 1..100 / points 0..20) は ScenarioLimits と同値 = 手動保存と同じ上限。
 * analysis_jobs.result_json へは toArray() を write-only 保存する (監査スナップショット)。
 */
final readonly class WorkDecompositionData
{
    /** @param list<WorkDecompositionStepData> $steps */
    public function __construct(public array $steps) {}

    public static function fromLlmText(string $text): self
    {
        $decoded = LlmJson::decode($text);

        $rawSteps = $decoded['steps'] ?? null;
        if (! is_array($rawSteps) || ! array_is_list($rawSteps)) {
            throw LlmJson::schemaViolation('steps は配列でなければなりません');
        }
        if (count($rawSteps) < 1) {
            throw LlmJson::schemaViolation('steps は 1 件以上でなければなりません');
        }
        if (count($rawSteps) > ScenarioLimits::MAX_STEPS) {
            throw LlmJson::schemaViolation('steps が上限 ('.ScenarioLimits::MAX_STEPS.') を超えています');
        }

        $steps = [];
        foreach ($rawSteps as $index => $rawStep) {
            if (! is_array($rawStep)) {
                throw LlmJson::schemaViolation("steps.{$index} は object でなければなりません");
            }
            $no = $rawStep['no'] ?? null;
            if (! is_int($no)) {
                throw LlmJson::schemaViolation("steps.{$index}.no は整数でなければなりません");
            }
            $action = $rawStep['action'] ?? null;
            if (! is_string($action) || trim($action) === '') {
                throw LlmJson::schemaViolation("steps.{$index}.action は非空文字列でなければなりません");
            }
            $rawPoints = $rawStep['points'] ?? [];
            if (! is_array($rawPoints) || ! array_is_list($rawPoints)) {
                throw LlmJson::schemaViolation("steps.{$index}.points は配列でなければなりません");
            }
            if (count($rawPoints) > ScenarioLimits::MAX_POINTS_PER_STEP) {
                throw LlmJson::schemaViolation("steps.{$index}.points が上限 (".ScenarioLimits::MAX_POINTS_PER_STEP.') を超えています');
            }
            $points = [];
            foreach ($rawPoints as $pointIndex => $rawPoint) {
                if (! is_string($rawPoint) || trim($rawPoint) === '') {
                    throw LlmJson::schemaViolation("steps.{$index}.points.{$pointIndex} は非空文字列でなければなりません");
                }
                $points[] = $rawPoint;
            }

            $steps[] = new WorkDecompositionStepData($no, $action, $points);
        }

        return new self($steps);
    }

    /** 次段プロンプトへ渡す正規化 JSON */
    public function toJsonString(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{steps: list<array{no: int, action: string, points: list<string>}>} result_json 保存用
     */
    public function toArray(): array
    {
        return [
            'steps' => array_map(
                static fn (WorkDecompositionStepData $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }
}
