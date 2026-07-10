<?php

declare(strict_types=1);

namespace App\Http\Resources\Capture;

use App\DataTransferObjects\Capture\CaptureCutData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * カットの JSON 応答 (adopt = 採用状態の返却)。shape は CaptureCutData に一元化。
 *
 * @property-read CaptureCutData $resource
 */
final class CaptureCutResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
