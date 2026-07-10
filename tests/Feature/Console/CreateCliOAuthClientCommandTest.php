<?php

declare(strict_types=1);

use App\Enums\OAuth\OAuthClientKind;
use Illuminate\Support\Facades\DB;

/*
 * cli:client — first-party CLI 用 public OAuth client 発行コマンド。
 * public (secret なし) + client_kind='cli' snapshot + RFC 8252 loopback redirect と、
 * 再実行時の冪等再利用を検証する。
 */

test('cli:client は public client を client_kind=cli で発行する', function (): void {
    $this->artisan('cli:client')->assertSuccessful();

    $client = DB::table('oauth_clients')
        ->where('client_kind', OAuthClientKind::Cli->value)
        ->first();

    expect($client)->not->toBeNull();
    expect($client->secret)->toBeNull();
    expect((bool) $client->revoked)->toBeFalse();

    $appName = config('app.name');
    expect($client->name)->toBe("{$appName} CLI");

    // RFC 8252 loopback redirect (IPv4/IPv6 のポート省略形) が既定で登録される
    $redirects = json_decode((string) $client->redirect_uris, true);
    expect($redirects)->toBe(['http://127.0.0.1/callback', 'http://[::1]/callback']);
});

test('cli:client は再実行時に既存 client を再利用する (冪等)', function (): void {
    $this->artisan('cli:client')->assertSuccessful();

    $firstId = DB::table('oauth_clients')
        ->where('client_kind', OAuthClientKind::Cli->value)
        ->value('id');

    $this->artisan('cli:client')
        ->expectsOutputToContain('reused')
        ->assertSuccessful();

    $rows = DB::table('oauth_clients')
        ->where('client_kind', OAuthClientKind::Cli->value)
        ->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->id)->toBe($firstId);
});

test('cli:client は --name / --redirect オプションを反映する', function (): void {
    $this->artisan('cli:client', [
        '--name' => 'Custom CLI',
        '--redirect' => ['https://cli.example/callback'],
    ])->assertSuccessful();

    $client = DB::table('oauth_clients')
        ->where('client_kind', OAuthClientKind::Cli->value)
        ->first();

    expect($client->name)->toBe('Custom CLI');
    $redirects = json_decode((string) $client->redirect_uris, true);
    expect($redirects)->toBe(['https://cli.example/callback']);
});
