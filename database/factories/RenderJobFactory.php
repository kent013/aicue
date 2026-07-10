<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;
use App\Models\RenderJob;
use App\Models\VideoManual;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RenderJob>
 */
class RenderJobFactory extends Factory
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
            'kind' => RenderKind::Render->value,
            'status' => JobStatus::Queued->value,
            'step' => null,
            'progress' => null,
            'scenario_version' => 0,
            'ticket_reservation_id' => null,
            'output_path' => null,
            'error' => null,
            'error_code' => null,
        ];
    }

    /** プレビュージョブとして作る (チケット非消費種別) */
    public function preview(): static
    {
        return $this->state(fn () => ['kind' => RenderKind::Preview->value]);
    }

    /** 指定マニュアル配下に作る */
    public function forManual(VideoManual $manual): static
    {
        return $this->state(fn () => ['video_manual_id' => $manual->id]);
    }

    /** 実行中 (compose 段) の状態 */
    public function running(): static
    {
        return $this->state(fn () => [
            'status' => JobStatus::Running->value,
            'step' => RenderStep::Compose->value,
            'progress' => 5,
        ]);
    }

    /** 成功確定の状態 (output_path 付き) */
    public function succeeded(string $outputPath): static
    {
        return $this->state(fn () => [
            'status' => JobStatus::Succeeded->value,
            'progress' => 100,
            'output_path' => $outputPath,
        ]);
    }

    /** 失敗確定の状態 */
    public function failed(
        RenderErrorCode $code = RenderErrorCode::Internal,
        string $error = '書き出しに失敗しました',
    ): static {
        return $this->state(fn () => [
            'status' => JobStatus::Failed->value,
            'error' => $error,
            'error_code' => $code->value,
        ]);
    }
}
