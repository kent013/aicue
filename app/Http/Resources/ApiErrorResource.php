<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Api\ApiError;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webmozart\Assert\Assert;

/**
 * 統一 API エラー envelope。既定の `data` ラッパーは使わず `{error: {...}}` で返す。
 *
 * @mixin ApiError
 */
class ApiErrorResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array{error: array{code: string, message: string, status: int, details?: array<string, mixed>}} */
    public function toArray(Request $request): array
    {
        Assert::isInstanceOf($this->resource, ApiError::class);

        return [
            'error' => $this->resource->toArray(),
        ];
    }
}
