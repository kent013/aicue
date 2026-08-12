<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Http\NotFoundMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webmozart\Assert\Assert;

/**
 * JSON 404 の body。**形は Laravel 既定と同じ `{"message": "…"}` に保つ** (T158)。
 *
 * 撮影 PWA のクライアントは `lib/capture/http.ts` が `record.message` を、
 * `lib/capture/upload-queue.ts` が `body.code` を読むため、
 * `api/*` の封筒形 (`{error: {...}}`) に変えるとクライアントが壊れる。
 *
 * @mixin NotFoundMessage
 */
class NotFoundMessageResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array{message: string} */
    public function toArray(Request $request): array
    {
        Assert::isInstanceOf($this->resource, NotFoundMessage::class);

        return ['message' => $this->resource->message];
    }
}
