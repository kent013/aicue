<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DataTransferObjects\Api\MeDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webmozart\Assert\Assert;

/**
 * GET /api/v1/me (whoami) の payload。認証済み actor とその組織のメタ情報を返す。
 *
 * @mixin MeDto
 */
class MeResource extends JsonResource
{
    /**
     * @return array{
     *     organization: array{id: int, name: string},
     *     actor_kind: string,
     *     api_key: array{id: int, name: string, abilities: list<string>}|null,
     *     session: array{id: string, scopes: list<string>}|null
     * }
     */
    public function toArray(Request $request): array
    {
        Assert::isInstanceOf($this->resource, MeDto::class);

        return $this->resource->toArray();
    }
}
