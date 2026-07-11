<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Dashboard;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;

/**
 * 進行中ジョブ 1 行 (analyzing/rendering の manual + 進行中 job のスナップショット)。
 * TS 側 types/dashboard.ts の InProgressManual と対で保守する。
 */
final readonly class InProgressManualData
{
    public function __construct(
        public int $manualId,
        public string $title,
        public VideoManualStatus $manualStatus,
        public ?JobStatus $jobStatus,     // null = job 行が見つからない過渡状態 (表示は「準備中」)
        public ?int $progress,
        public ?string $jobUpdatedAt,     // 「最終更新」表示 (Y-m-d H:i)
    ) {}

    /**
     * @return array{manual_id: int, title: string, manual_status: string,
     *   job_status: string|null, progress: int|null, job_updated_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'manual_id' => $this->manualId,
            'title' => $this->title,
            'manual_status' => $this->manualStatus->value,
            'job_status' => $this->jobStatus?->value,
            'progress' => $this->progress,
            'job_updated_at' => $this->jobUpdatedAt,
        ];
    }
}
