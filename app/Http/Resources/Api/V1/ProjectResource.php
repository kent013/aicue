<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webmozart\Assert\Assert;

/**
 * REST API v1 の Project 表現。
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, description: string|null, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        Assert::isInstanceOf($this->resource, Project::class);
        $project = $this->resource;

        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }
}
