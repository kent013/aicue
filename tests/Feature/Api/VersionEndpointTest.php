<?php

declare(strict_types=1);

use App\Enums\OAuth\OAuthClientKind;
use Illuminate\Support\Facades\DB;
use Tests\Support\OAuthTestHelpers;

/*
 * GET /api/v1/version の capability negotiation payload。
 * server_version / min_cli_version の semver fail-fast と
 * cli_oauth_client_id の決定的解決 (0/1/2+ 件) を検証する。
 */

test('version は negotiation フィールドを返す (既定値)', function (): void {
    $this->getJson('/api/v1/version')
        ->assertOk()
        ->assertJsonPath('data.api_version', 'v1')
        ->assertJsonPath('data.server_version', '0.1.0')
        ->assertJsonPath('data.min_cli_version', '0.1.0')
        ->assertJsonPath('data.capabilities', [])
        ->assertJsonPath('data.cli_oauth_client_id', null)
        ->assertJsonStructure(['data' => [
            'name', 'api_version', 'template_version',
            'server_version', 'min_cli_version', 'capabilities', 'cli_oauth_client_id',
        ]]);
});

test('version は config の server_version / min_cli_version / capabilities を反映する', function (): void {
    config()->set('template.api.server_version', '1.2.3-beta.1');
    config()->set('template.api.min_cli_version', '1.0.0');
    config()->set('template.api.capabilities', ['rest.v1', 'mcp.tools']);

    $this->getJson('/api/v1/version')
        ->assertOk()
        ->assertJsonPath('data.server_version', '1.2.3-beta.1')
        ->assertJsonPath('data.min_cli_version', '1.0.0')
        ->assertJsonPath('data.capabilities', ['rest.v1', 'mcp.tools']);
});

test('server_version が semver でない場合は fail-fast (500)', function (): void {
    config()->set('template.api.server_version', 'not-semver');

    $this->getJson('/api/v1/version')->assertStatus(500);
});

test('min_cli_version が semver でない場合は fail-fast (500)', function (): void {
    config()->set('template.api.min_cli_version', '1.0');

    $this->getJson('/api/v1/version')->assertStatus(500);
});

test('cli_oauth_client_id は public CLI client がちょうど 1 つのとき解決される', function (): void {
    $this->artisan('cli:client')->assertSuccessful();

    $clientId = DB::table('oauth_clients')
        ->where('client_kind', OAuthClientKind::Cli->value)
        ->value('id');

    $this->getJson('/api/v1/version')
        ->assertOk()
        ->assertJsonPath('data.cli_oauth_client_id', (string) $clientId);
});

test('cli_oauth_client_id は public CLI client が複数のとき null に落ちる (fail-safe)', function (): void {
    $this->artisan('cli:client')->assertSuccessful();

    // 2 つ目の public CLI client を直接作成する (設定ミスの再現)
    $second = OAuthTestHelpers::createMcpClient(name: 'Second CLI');
    DB::table('oauth_clients')
        ->where('id', $second->getKey())
        ->update(['client_kind' => OAuthClientKind::Cli->value]);

    $this->getJson('/api/v1/version')
        ->assertOk()
        ->assertJsonPath('data.cli_oauth_client_id', null);
});

test('revoked な CLI client は cli_oauth_client_id の解決対象にならない', function (): void {
    $this->artisan('cli:client')->assertSuccessful();
    DB::table('oauth_clients')
        ->where('client_kind', OAuthClientKind::Cli->value)
        ->update(['revoked' => true]);

    $this->getJson('/api/v1/version')
        ->assertOk()
        ->assertJsonPath('data.cli_oauth_client_id', null);
});
