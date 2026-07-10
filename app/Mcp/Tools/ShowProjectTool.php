<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Mcp\ToolName;
use App\Models\Project;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request as McpRequest;

/**
 * show-project: bound organization 内のプロジェクト詳細。
 * 解決は org relation 経由のみ (cross-org は存在しないものとして扱う)。
 */
final class ShowProjectTool extends AppMcpTool
{
    protected string $description = 'Show a project in the organization bound to the access token.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project ID')->required(),
        ];
    }

    protected function toolName(): ToolName
    {
        return ToolName::ShowProject;
    }

    protected function runTool(McpRequest $request, McpAuthorizationContext $ctx): array
    {
        $projectId = $this->requireIntParam($request, 'project_id', min: 1);

        // org-scoped 解決: relation 経由のみで fetch する (cross-org は not found)
        $project = $ctx->organization->projects()->whereKey($projectId)->first();
        if (! $project instanceof Project) {
            throw ValidationException::withMessages(['project_id' => 'Project not found.']);
        }

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'items_count' => $project->items()->count(),
                'created_at' => $project->created_at?->toIso8601String(),
            ],
        ];
    }
}
