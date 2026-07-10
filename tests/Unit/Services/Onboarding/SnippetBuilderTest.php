<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Onboarding\SnippetBuilder;

/*
 * SnippetBuilder は endpoint / 設定 JSON を config('app.url') / config('template.slug') から
 * 生成する純粋関数の集合。アプリ名をハードコードしないこと・平文キーを埋めないことを固定する。
 */

function makeOrg(int $id): Organization
{
    $org = new Organization;
    $org->forceFill(['id' => $id, 'name' => 'Acme', 'slug' => 'acme']);

    return $org;
}

beforeEach(function (): void {
    config(['app.url' => 'https://example.test', 'template.slug' => 'myapp']);
});

test('mcpEndpointUrl / restApiBaseUrl は app.url を基点に組み立てる', function (): void {
    $b = new SnippetBuilder;

    expect($b->mcpEndpointUrl())->toBe('https://example.test/api/v1/mcp');
    expect($b->restApiBaseUrl())->toBe('https://example.test/api/v1');
});

test('末尾スラッシュ付き app.url でも二重スラッシュにならない', function (): void {
    config(['app.url' => 'https://example.test/']);
    $b = new SnippetBuilder;

    expect($b->mcpEndpointUrl())->toBe('https://example.test/api/v1/mcp');
});

test('mcpConfigArray は template.slug をサーバキーに使い endpoint を埋める', function (): void {
    $b = new SnippetBuilder;
    $config = $b->mcpConfigArray();

    expect($config['mcpServers'])->toHaveKey('myapp');
    expect($config['mcpServers']['myapp'])->toBe([
        'type' => 'http',
        'url' => 'https://example.test/api/v1/mcp',
    ]);
});

test('mcpConfigJson は valid JSON で slug / endpoint を含む', function (): void {
    $b = new SnippetBuilder;
    $json = $b->mcpConfigJson();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBe($b->mcpConfigArray());
    expect($json)->toContain('myapp')->toContain('https://example.test/api/v1/mcp');
    // pretty print (改行を含む) でコピペしやすい
    expect($json)->toContain("\n");
});

test('CLI スニペットは slug ベースのバイナリ名 / profile 名を使う', function (): void {
    $b = new SnippetBuilder;
    $org = makeOrg(7);

    expect($b->cliInstallCommand())->toBe('npm install -g @myapp/cli@latest');
    expect($b->cliProfileCommands($org))
        ->toContain('myapp profile:add org-7')
        ->toContain('myapp auth:login --profile org-7')
        ->toContain('--api-url https://example.test');
});

test('CI 向け API キーログインは placeholder を使い平文キーを埋めない', function (): void {
    $b = new SnippetBuilder;
    $org = makeOrg(7);
    $snippet = $b->cliApiKeyLogin($org);

    expect($snippet)
        ->toContain(SnippetBuilder::PLAIN_KEY_PLACEHOLDER)
        ->toContain('MYAPP_API_KEY')
        ->toContain('--stdin');
    // 実キーを埋めていないこと (placeholder 以外の "秘密っぽい" 値がないことの最低限の確認)
    expect($snippet)->not->toContain('sk-');
});

test('アプリ名をハードコードせず template.slug の変更に追従する', function (): void {
    config(['template.slug' => 'zzz']);
    $b = new SnippetBuilder;

    expect($b->cliInstallCommand())->toContain('@zzz/cli');
    expect($b->mcpConfigArray()['mcpServers'])->toHaveKey('zzz');
    expect($b->mcpClaudeCodeCommand())->toContain('claude mcp add --transport http zzz');
});
