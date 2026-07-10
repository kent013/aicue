<?php

declare(strict_types=1);

namespace App\Http\Resources\Manual;

use App\DataTransferObjects\Manual\AnalysisJobData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AnalysisJob の JSON 応答 (analyze 201 / ポーリング 200 の共通 shape)。
 * ScenarioResource と同じく DTO を包む JsonResource ($wrap = null)。
 *
 * @property-read AnalysisJobData $resource
 */
final class AnalysisJobResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{id: int, status: string, step: string|null, progress: int|null,
     *   error: string|null, manual_status: string}
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
