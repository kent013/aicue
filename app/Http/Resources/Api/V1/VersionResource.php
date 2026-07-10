<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DataTransferObjects\Api\VersionInfoDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webmozart\Assert\Assert;

/**
 * GET /api/v1/version の payload。resource は {@see VersionInfoDto}
 * (VersionInfoService が組み立てる)。
 */
class VersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        Assert::isInstanceOf($this->resource, VersionInfoDto::class);

        return $this->resource->toArray();
    }
}
