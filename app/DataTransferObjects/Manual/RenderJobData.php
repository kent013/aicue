<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;
use App\Enums\Manual\VideoManualStatus;
use App\Models\RenderJob;
use App\Models\VideoManual;

/**
 * RenderJob の表示 shape (show props / ポーリング応答 / トリガー 201 の共通 DTO)。
 * **成果物 URL / output_path は持たない** (ポーリングと成果物アクセスの権限分離 = 概念設計 §7)。
 * TS 側 types/manual.ts の RenderJobProps と対で保守する。
 */
final readonly class RenderJobData
{
    public function __construct(
        public int $id,
        public RenderKind $kind,
        public JobStatus $status,
        public ?RenderStep $step,
        public ?int $progress,
        public ?string $error,
        public ?RenderErrorCode $errorCode,
        public VideoManualStatus $manualStatus,
        /**
         * 生成物に含まれたプレースホルダ (黒背景) クリップ数。
         * null = その動画について言えることが無い (未完了 / T148 以前の succeeded 行)。
         * **null を 0 と同一視しない** (0 は「黒背景ゼロで生成された」という積極的な事実)。
         */
        public ?int $placeholderCutCount,
    ) {}

    public static function fromJob(RenderJob $job, VideoManual $manual): self
    {
        return new self(
            id: $job->id,
            kind: $job->kind,
            status: $job->status,
            step: $job->step,
            progress: $job->progress,
            error: $job->error,
            errorCode: $job->error_code,
            manualStatus: $manual->status,
            placeholderCutCount: $job->placeholder_cut_count,
        );
    }

    /**
     * @return array{id: int, kind: string, status: string, step: string|null, progress: int|null,
     *   error: string|null, error_code: string|null, manual_status: string,
     *   placeholder_cut_count: int|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'step' => $this->step?->value,
            'progress' => $this->progress,
            'error' => $this->error,
            'error_code' => $this->errorCode?->value,
            'manual_status' => $this->manualStatus->value,
            'placeholder_cut_count' => $this->placeholderCutCount,
        ];
    }
}
