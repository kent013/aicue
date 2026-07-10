<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Mcp\ToolName;
use App\Models\Project;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request as McpRequest;

/**
 * list-projects: bound organization のプロジェクト一覧。
 */
final class ListProjectsTool extends AppMcpTool
{
    protected string $description = 'List projects in the organization bound to the access token.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number (1..1000)'),
            'per_page' => $schema->integer()->description('Items per page (1..100)'),
        ];
    }

    protected function toolName(): ToolName
    {
        return ToolName::ListProjects;
    }

    protected function runTool(McpRequest $request, McpAuthorizationContext $ctx): array
    {
        [$page, $perPage] = $this->paginationParams($request);

        // HasManyThrough (CustomTeam 経由) のため両テーブルに存在するカラムは修飾する
        $paginator = $ctx->organization->projects()
            ->orderByDesc('projects.created_at')
            ->orderByDesc('projects.id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->paginatedResponse(
            $paginator,
            'projects',
            $page,
            $perPage,
            static fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'created_at' => $project->created_at?->toIso8601String(),
            ],
        );
    }
}
