<?php

declare(strict_types=1);

namespace App\Http\Resources\Capture;

use App\DataTransferObjects\Capture\TakeUploadTicketData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * upload-url 発行応答 ({ upload_url, headers, ticket, client_take_id, expires_at })。
 * TS 側 types/capture.ts の UploadTicket と対で保守する。
 *
 * @property-read TakeUploadTicketData $resource
 */
final class TakeUploadTicketResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{upload_url: string, headers: array<string, string>, ticket: string, client_take_id: string, expires_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'upload_url' => $this->resource->presigned->url,
            'headers' => $this->resource->presigned->headers,
            'ticket' => $this->resource->ticket,
            'client_take_id' => $this->resource->clientTakeId,
            'expires_at' => $this->resource->presigned->expiresAt->toIso8601String(),
        ];
    }
}
