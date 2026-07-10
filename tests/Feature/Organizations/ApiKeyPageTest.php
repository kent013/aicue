<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\ApiKey\ApiKeyPermissionService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 専用の API キー管理画面 (ApiKeys/Index) と MCP/CLI オンボーディング画面の描画・認可。
 */

test('owner は API キー一覧画面を開ける (発行済みキーが並ぶ)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$apiKey] = issueApiKey($organization, $owner, ['read'], 'CI 用キー');

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/api-keys")
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Organizations/ApiKeys/Index')
            ->where('organization.slug', $organization->slug)
            ->has('apiKeys', 1)
            ->where('apiKeys.0.id', $apiKey->id)
            ->where('apiKeys.0.name', 'CI 用キー'));
});

test('直接付与のない一般メンバーは API キー画面が 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)
        ->get("/organizations/{$organization->slug}/api-keys")
        ->assertForbidden();
});

test('manage-api-keys を直接付与された一般メンバーは API キー画面を開ける', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    app(ApiKeyPermissionService::class)->grant($member, $organization);

    $this->actingAs($member->fresh())
        ->get("/organizations/{$organization->slug}/api-keys")
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('Organizations/ApiKeys/Index'));
});

test('MCP オンボーディング画面は endpoint / 設定 JSON を config から埋めて描画する', function (): void {
    config(['app.url' => 'https://example.test', 'template.slug' => 'myapp']);
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/onboarding/mcp")
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Organizations/Onboarding/Mcp')
            ->where('mcpEndpointUrl', 'https://example.test/api/v1/mcp')
            ->where('claudeCodeCommand', 'claude mcp add --transport http myapp https://example.test/api/v1/mcp')
            ->has('mcpConfigJson'));
});

test('CLI オンボーディング画面はインストール / profile スニペットを描画する', function (): void {
    config(['app.url' => 'https://example.test', 'template.slug' => 'myapp']);
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/onboarding/cli")
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Organizations/Onboarding/Cli')
            ->where('apiBaseUrl', 'https://example.test/api/v1')
            ->where('installCommand', 'npm install -g @myapp/cli@latest')
            ->has('profileCommands')
            ->has('apiKeyLoginCommand'));
});

test('オンボーディング画面は一般メンバーも閲覧できる (view 境界)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($member)
        ->get("/organizations/{$organization->slug}/onboarding/mcp")
        ->assertOk();
    $this->actingAs($member)
        ->get("/organizations/{$organization->slug}/onboarding/cli")
        ->assertOk();
});

test('非メンバーはオンボーディング画面が 404 (クロステナント秘匿)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get("/organizations/{$organization->slug}/onboarding/mcp")
        ->assertNotFound();
});
