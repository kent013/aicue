<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Smoke;

use App\DataTransferObjects\LlmCostReportData;
use App\Enums\Smoke\SmokeFailureClass;

/**
 * pipeline smoke 1 回分の結果。`--json` は本 DTO の `toArray()` を 1 経路で出す
 * (public property の並びを外部契約にしない。`response()->json()` は使わない)。
 */
final readonly class SmokeRunResultData
{
    /**
     * @param  bool  $checkOnly  preflight だけ実行した (`--check`) か
     * @param  array<string, string>  $context  実行対象の表示 (env / db / org / ffmpeg 版など)
     * @param  list<SmokeStageResultData>  $stages
     * @param  ?LlmCostReportData  $cost  この実行分のコスト (`--check` では null)
     * @param  int<0, max>  $totalElapsedMs
     */
    public function __construct(
        public bool $passed,
        public bool $checkOnly,
        public array $context,
        public array $stages,
        public ?SmokeFailureClass $failureClass,
        public ?LlmCostReportData $cost,
        public int $totalElapsedMs,
    ) {}

    /**
     * @return array{
     *     passed: bool,
     *     check_only: bool,
     *     failure_class: string|null,
     *     total_elapsed_ms: int,
     *     context: array<string, string>,
     *     stages: list<array<string, mixed>>,
     *     cost: array<string, mixed>|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'check_only' => $this->checkOnly,
            'failure_class' => $this->failureClass?->value,
            'total_elapsed_ms' => $this->totalElapsedMs,
            'context' => $this->context,
            'stages' => array_map(
                static fn (SmokeStageResultData $stage): array => $stage->toArray(),
                $this->stages,
            ),
            // コスト部は LlmCostReportData::toArray() をそのまま埋め込む (二重定義しない)
            'cost' => $this->cost?->toArray(),
        ];
    }
}
