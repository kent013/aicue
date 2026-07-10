<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Mcp\ToolName;
use App\Models\Item;
use App\Models\Project;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request as McpRequest;

/**
 * list-items: bound organization 内プロジェクトの Item 一覧。
 */
final class ListItemsTool extends AppMcpTool
{
    protected string $description = 'List items of a project in the organization bound to the access token.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project ID')->required(),
            'page' => $schema->integer()->description('Page number (1..1000)'),
            'per_page' => $schema->integer()->description('Items per page (1..100)'),
        ];
    }

    protected function toolName(): ToolName
    {
        return ToolName::ListItems;
    }

    protected function runTool(McpRequest $request, McpAuthorizationContext $ctx): array
    {
        $projectId = $this->requireIntParam($request, 'project_id', min: 1);
        [$page, $perPage] = $this->paginationParams($request);

        // org-scoped 解決: relation 経由のみで fetch する (cross-org は not found)
        $project = $ctx->organization->projects()->whereKey($projectId)->first();
        if (! $project instanceof Project) {
            throw ValidationException::withMessages(['project_id' => 'Project not found.']);
        }

        $paginator = $project->items()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $response = $this->paginatedResponse(
            $paginator,
            'items',
            $page,
            $perPage,
            static fn (Item $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'note' => $item->note,
                'created_at' => $item->created_at?->toIso8601String(),
            ],
        );

        return ['project_id' => $project->id] + $response;
    }
}
