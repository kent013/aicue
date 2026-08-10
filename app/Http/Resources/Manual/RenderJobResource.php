<?php

declare(strict_types=1);

namespace App\Http\Resources\Manual;

use App\DataTransferObjects\Manual\RenderJobData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RenderJob の JSON 応答 (render/preview 201 / ポーリング 200 の共通 shape)。
 * AnalysisJobResource と同じく DTO を包む JsonResource ($wrap = null)。
 * 署名 URL・output_path は一切含めない (成果物アクセスは playback / download route に分離)。
 *
 * @property-read RenderJobData $resource
 */
final class RenderJobResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{id: int, kind: string, status: string, step: string|null, progress: int|null,
     *   error: string|null, error_code: string|null, manual_status: string,
     *   placeholder_cut_count: int|null}
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
