<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Onboarding\SnippetBuilder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MCP / CLI 導入の最小オンボーディング画面 (組織メンバー向け)。
 *
 * endpoint URL / 設定 JSON スニペットは {@see SnippetBuilder} が config('app.url') /
 * config('template.slug') から生成する (アプリ名・URL をハードコードしない)。
 * 認証は各ユーザー自身の OAuth ログインで行うため、閲覧は組織メンバーなら可 (view)。
 */
class OrganizationOnboardingController extends Controller
{
    public function __construct(private readonly SnippetBuilder $snippets) {}

    /** MCP (Claude Desktop / Claude Code 等) 接続手順。 */
    public function mcp(Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        return Inertia::render('Organizations/Onboarding/Mcp', [
            'organization' => $this->organizationProps($organization),
            'mcpEndpointUrl' => $this->snippets->mcpEndpointUrl(),
            'mcpConfigJson' => $this->snippets->mcpConfigJson(),
            'claudeCodeCommand' => $this->snippets->mcpClaudeCodeCommand(),
        ]);
    }

    /** CLI 導入手順。 */
    public function cli(Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        return Inertia::render('Organizations/Onboarding/Cli', [
            'organization' => $this->organizationProps($organization),
            'apiBaseUrl' => $this->snippets->restApiBaseUrl(),
            'installCommand' => $this->snippets->cliInstallCommand(),
            'profileCommands' => $this->snippets->cliProfileCommands($organization),
            'apiKeyLoginCommand' => $this->snippets->cliApiKeyLogin($organization),
        ]);
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function organizationProps(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
        ];
    }
}
