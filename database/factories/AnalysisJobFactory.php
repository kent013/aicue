<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Manual\AnalysisStep;
use App\Enums\Manual\JobStatus;
use App\Models\AnalysisJob;
use App\Models\SourceDocument;
use App\Models\VideoManual;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisJob>
 */
class AnalysisJobFactory extends Factory
{
    /**
     * videoManual 未指定なら VideoManualFactory に連鎖する (親 Factory 連鎖の規約)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_manual_id' => VideoManual::factory(),
            'source_document_id' => null,
            'status' => JobStatus::Queued->value,
            'step' => null,
            'progress' => null,
            'ticket_reservation_id' => null,
            'result_json' => null,
            'error' => null,
        ];
    }

    /** 指定マニュアル配下に作る */
    public function forManual(VideoManual $manual): static
    {
        return $this->state(fn () => ['video_manual_id' => $manual->id]);
    }

    /** 解析対象の SourceDocument を指定する (manual の一致は呼び出し側の責務) */
    public function forDocument(SourceDocument $document): static
    {
        return $this->state(fn () => ['source_document_id' => $document->id]);
    }

    /** 実行中 (extract 段) の状態 */
    public function running(): static
    {
        return $this->state(fn () => [
            'status' => JobStatus::Running->value,
            'step' => AnalysisStep::Extract->value,
            'progress' => 10,
        ]);
    }

    /** 失敗確定の状態 */
    public function failed(string $error = '解析に失敗しました'): static
    {
        return $this->state(fn () => [
            'status' => JobStatus::Failed->value,
            'error' => $error,
        ]);
    }

    /** 成功確定の状態 */
    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => JobStatus::Succeeded->value,
            'progress' => 100,
        ]);
    }
}
