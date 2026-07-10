<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\AnalysisStep;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Models\AnalysisJob;
use App\Models\VideoManual;

/**
 * AnalysisJob の表示 shape (show props / ポーリング応答 / analyze 応答の共通 DTO)。
 * manual_status を含めることでフロントは succeeded 時に ready を確認してリロードできる。
 * TS 側 types/manual.ts の AnalysisJobProps と対で保守する。
 */
final readonly class AnalysisJobData
{
    public function __construct(
        public int $id,
        public JobStatus $status,
        public ?AnalysisStep $step,
        public ?int $progress,
        public ?string $error,
        public VideoManualStatus $manualStatus,
    ) {}

    public static function fromJob(AnalysisJob $job, VideoManual $manual): self
    {
        return new self(
            id: $job->id,
            status: $job->status,
            step: $job->step,
            progress: $job->progress,
            error: $job->error,
            manualStatus: $manual->status,
        );
    }

    /**
     * @return array{id: int, status: string, step: string|null, progress: int|null,
     *   error: string|null, manual_status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'step' => $this->step?->value,
            'progress' => $this->progress,
            'error' => $this->error,
            'manual_status' => $this->manualStatus->value,
        ];
    }
}
