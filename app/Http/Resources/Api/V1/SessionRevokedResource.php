<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DataTransferObjects\Api\SessionRevokedDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webmozart\Assert\Assert;

/**
 * `DELETE /api/v1/me/session` の payload。
 *
 * @mixin SessionRevokedDto
 */
class SessionRevokedResource extends JsonResource
{
    /** @return array{session_id: string, revoked: bool} */
    public function toArray(Request $request): array
    {
        Assert::isInstanceOf($this->resource, SessionRevokedDto::class);

        return $this->resource->toArray();
    }
}
