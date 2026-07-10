<?php

declare(strict_types=1);

namespace App\Http\Resources\Capture;

use App\DataTransferObjects\Capture\CaptureSyncResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * sync 応答 ({ pending_upload, manual })。TS 側 types/capture.ts の SyncResult と対で保守。
 *
 * @property-read CaptureSyncResultData $resource
 */
final class CaptureSyncResultResource extends JsonResource
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
