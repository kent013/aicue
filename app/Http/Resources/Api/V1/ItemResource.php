<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webmozart\Assert\Assert;

/**
 * REST API v1 の Item 表現。
 *
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array{id: int, project_id: int, name: string, note: string|null, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        Assert::isInstanceOf($this->resource, Item::class);
        $item = $this->resource;

        return [
            'id' => $item->id,
            'project_id' => $item->project_id,
            'name' => $item->name,
            'note' => $item->note,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
