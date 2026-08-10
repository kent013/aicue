<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Smoke;

use App\Enums\Smoke\SmokeFailureClass;
use App\Enums\Smoke\SmokeStage;

/**
 * pipeline smoke の段 1 つの結果。
 *
 * `detail` は**診断のための自由文**であり、機械判定には使わない (判定は ok / failureClass)。
 */
final readonly class SmokeStageResultData
{
    /**
     * @param  int<0, max>  $elapsedMs
     * @param  ?SmokeFailureClass  $failureClass  成功段では null (分類しない)
     */
    public function __construct(
        public SmokeStage $stage,
        public bool $ok,
        public int $elapsedMs,
        public string $detail,
        public ?SmokeFailureClass $failureClass,
    ) {}

    /**
     * @return array{stage: string, ok: bool, elapsed_ms: int, detail: string, failure_class: string|null}
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage->value,
            'ok' => $this->ok,
            'elapsed_ms' => $this->elapsedMs,
            'detail' => $this->detail,
            'failure_class' => $this->failureClass?->value,
        ];
    }
}
