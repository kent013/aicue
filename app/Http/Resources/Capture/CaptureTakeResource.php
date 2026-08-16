<?php

declare(strict_types=1);

namespace App\Http\Resources\Capture;

use App\DataTransferObjects\Capture\CaptureTakeData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * テイクの JSON 応答 (store/update/adopt/downloaded)。shape は CaptureTakeData に一元化。
 *
 * @property-read CaptureTakeData $resource
 */
final class CaptureTakeResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{id: int, client_take_id: string, status: string, size_bytes: int,
     *   duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
     *   downloaded: bool, has_thumbnail: bool, playback_url: string|null,
     *   download_ack_token: string|null}
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
