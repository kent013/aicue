<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Mcp\ToolName;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request as McpRequest;
use Webmozart\Assert\Assert;

/**
 * whoami: 認証済み principal (User) と bound organization のエコー。
 */
final class WhoamiTool extends AppMcpTool
{
    protected string $description = 'Return the authenticated user and the organization bound to the access token.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function toolName(): ToolName
    {
        return ToolName::Whoami;
    }

    protected function runTool(McpRequest $request, McpAuthorizationContext $ctx): array
    {
        $role = $ctx->user->organizationRole($ctx->organization);
        Assert::notNull($role, 'User must be a member of the bound organization.');

        return [
            'user' => [
                'id' => $ctx->user->id,
                'name' => (string) $ctx->user->name,
            ],
            'organization' => [
                'id' => $ctx->organization->id,
                'name' => $ctx->organization->name,
                'role' => $role->value,
            ],
        ];
    }
}
