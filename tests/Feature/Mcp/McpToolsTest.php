<?php

declare(strict_types=1);

use App\Enums\Mcp\ToolName;
use App\Enums\OrganizationRole;
use App\Models\Item;
use App\Models\Project;
use Illuminate\Testing\TestResponse;
use Tests\Support\OAuthTestHelpers;
use Tests\TestCase;

/*
 * MCP tools (whoami / list-projects / show-project / list-items) の動作と
 * org スコープ (access token に bound な organization 以外は見えない)、
 * scope 境界 (mcp:use 必須)、membership 剥奪の即時反映を検証する。
 *
 * 認証は OAuth 2.1 (auth:mcp-oauth)。token は authorize → consent → exchange の
 * full flow で取得する (OAuthTestHelpers::exchangeForTokens)。
 */

beforeEach(function (): void {
    [$this->org, $this->user] = createOrganizationWithOwner('MCP組織');

    $this->pkce = OAuthTestHelpers::generatePkcePair();
    $this->client = OAuthTestHelpers::createMcpClient();
    $this->redirectUri = 'https://test.example/callback';

    config()->set('mcp.allowed_origins', ['https://claude.ai']);
    config()->set('mcp.strict_transport', true);
});

/**
 * tools/call を JSON-RPC over HTTP で叩く helper。
 *
 * @param  array<string, mixed>  $arguments
 */
function callMcpTool(string $accessToken, string $tool, array $arguments = []): TestResponse
{
    /** @var TestCase $testCase */
    $testCase = test();

    return $testCase->withHeaders([
        'Origin' => 'https://claude.ai',
        'Authorization' => "Bearer {$accessToken}",
    ])->postJson('/api/v1/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
        'id' => 1,
    ]);
}

/** tools/list を叩く helper。 */
function listMcpTools(string $accessToken): TestResponse
{
    /** @var TestCase $testCase */
    $testCase = test();

    return $testCase->withHeaders([
        'Origin' => 'https://claude.ai',
        'Authorization' => "Bearer {$accessToken}",
    ])->postJson('/api/v1/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'tools/list',
        'id' => 1,
    ]);
}

test('tools/list に ToolName の read 系 4 tool が並ぶ', function (): void {
    ['access_token' => $accessToken] = OAuthTestHelpers::exchangeForTokens($this);

    $response = listMcpTools($accessToken)->assertOk();

    /** @var list<array{name: string}> $tools */
    $tools = $response->json('result.tools');
    $names = array_column($tools, 'name');
    sort($names);

    $expected = array_map(static fn (ToolName $t): string => $t->value, ToolName::cases());
    sort($expected);
    expect($names)->toBe($expected);
});

test('whoami は認証済み user と bound organization / role を返す', function (): void {
    ['access_token' => $accessToken] = OAuthTestHelpers::exchangeForTokens($this);

    $response = callMcpTool($accessToken, 'whoami')->assertOk();

    /** @var string $json */
    $json = $response->json('result.content.0.text');
    $payload = json_decode($json, true);
    expect(data_get($payload, 'user.id'))->toBe($this->user->id);
    expect(data_get($payload, 'organization.id'))->toBe($this->org->id);
    expect(data_get($payload, 'organization.name'))->toBe('MCP組織');
    expect(data_get($payload, 'organization.role'))->toBe(OrganizationRole::Owner->value);
});

test('list-projects は bound organization のプロジェクトのみ返す', function (): void {
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($this->org)->create();
    Project::factory()->forOrganization($organizationB)->create();

    ['access_token' => $accessToken] = OAuthTestHelpers::exchangeForTokens($this);

    $response = callMcpTool($accessToken, 'list-projects')->assertOk();

    /** @var string $json */
    $json = $response->json('result.content.0.text');
    $payload = json_decode($json, true);
    /** @var list<array{id: int}> $projects */
    $projects = data_get($payload, 'projects');
    expect(array_column($projects, 'id'))->toBe([$projectA->id]);
});

test('show-project / list-items は cross-org をエラーにする (存在を漏らさない)', function (): void {
    [$organizationB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($organizationB)->create();
    Item::factory()->forProject($projectB)->create();

    ['access_token' => $accessToken] = OAuthTestHelpers::exchangeForTokens($this);

    $show = callMcpTool($accessToken, 'show-project', ['project_id' => $projectB->id])->assertOk();
    expect($show->json('result.isError'))->toBeTrue();

    $list = callMcpTool($accessToken, 'list-items', ['project_id' => $projectB->id])->assertOk();
    expect($list->json('result.isError'))->toBeTrue();
});

test('list-items は bound organization プロジェクトの items を返す', function (): void {
    $project = Project::factory()->forOrganization($this->org)->create();
    $item = Item::factory()->forProject($project)->create(['name' => 'MCP アイテム']);

    ['access_token' => $accessToken] = OAuthTestHelpers::exchangeForTokens($this);

    $response = callMcpTool($accessToken, 'list-items', ['project_id' => $project->id])->assertOk();

    /** @var string $json */
    $json = $response->json('result.content.0.text');
    $payload = json_decode($json, true);
    expect(data_get($payload, 'project_id'))->toBe($project->id);
    expect(data_get($payload, 'items.0.id'))->toBe($item->id);
    expect(data_get($payload, 'items.0.name'))->toBe('MCP アイテム');
});

test('mcp:use scope の無い token (CLI scope のみ) は tool を利用できない', function (): void {
    ['access_token' => $accessToken] = OAuthTestHelpers::exchangeForTokens($this, scope: 'cli:use read');

    // tools/list からは全 tool が除外される (shouldRegister が false)
    $list = listMcpTools($accessToken)->assertOk();
    expect($list->json('result.tools'))->toBe([]);

    // tools/call も到達不能 (未登録 tool として JSON-RPC error)
    $call = callMcpTool($accessToken, 'whoami');
    expect($call->json('error'))->not->toBeNull();
});

test('membership 剥奪後は tool 呼び出しが即時拒否される', function (): void {
    ['access_token' => $accessToken] = OAuthTestHelpers::exchangeForTokens($this);

    // token 発行後に組織から外す → shouldRegister が false になり tool 自体が見えなくなる
    $this->org->users()->detach($this->user);

    $list = listMcpTools($accessToken)->assertOk();
    expect($list->json('result.tools'))->toBe([]);

    $call = callMcpTool($accessToken, 'whoami');
    expect($call->json('error'))->not->toBeNull();
});
