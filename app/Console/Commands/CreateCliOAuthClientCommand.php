<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OAuth\OAuthClientKind;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Webmozart\Assert\Assert;

/**
 * first-party CLI 用 OAuth client を発行する。
 *
 * - public client (secret なし) + PKCE 前提 (`McpAuthorizationCodeGrant` が S256 を強制)
 * - redirect URI は既定で `http://127.0.0.1/callback` / `http://[::1]/callback`
 *   (ポート省略形)。実際の認可リクエストでは CLI が動的ポートを使う。
 *   league/oauth2-server の `RedirectUriValidator` が RFC 8252 §7.3 に従い
 *   loopback URI (http + 127.0.0.1/[::1]) に限りポートを除外して比較する
 *   (scheme / host / path / query は厳密一致)。`--redirect` で差し替え可能
 * - `oauth_clients.client_kind = 'cli'` を snapshot し、REST dual guard の
 *   user-token 経路 / CLI session 発行 (McpAuthCodeRepository) がこの kind を参照する
 * - **冪等**: `client_kind='cli'` の非 revoked client が既に存在する場合は再利用して
 *   既存 client id を表示する (重複作成しない。`--redirect` 指定があっても無視される)
 *
 * client id は秘密ではないため `/api/v1/version` の `cli_oauth_client_id` で公開され、
 * CLI は実行時に取得する。
 */
final class CreateCliOAuthClientCommand extends Command
{
    protected $signature = 'cli:client
        {--name= : client 表示名 (省略時は "<APP_NAME> CLI")}
        {--redirect=* : 追加 redirect URI (省略時は RFC 8252 loopback default)}';

    protected $description = 'first-party CLI 用の OAuth client (public, PKCE, client_kind=cli) を発行する (冪等)';

    public function handle(ClientRepository $clients): int
    {
        $existingId = DB::table('oauth_clients')
            ->where('client_kind', OAuthClientKind::Cli->value)
            ->where('revoked', false)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('id');

        if ($existingId !== null) {
            Assert::string($existingId);
            $this->info(sprintf('CLI OAuth client already exists: %s (reused)', $existingId));

            return self::SUCCESS;
        }

        $name = $this->option('name');
        if (! is_string($name) || $name === '') {
            $appName = config('app.name');
            Assert::string($appName);
            $name = "{$appName} CLI";
        }

        /** @var list<string> $redirects */
        $redirects = array_values(array_filter(
            (array) $this->option('redirect'),
            static fn (mixed $uri): bool => is_string($uri) && $uri !== '',
        ));
        if ($redirects === []) {
            // RFC 8252 §7.3: loopback の可変ポートを許容するため IPv4/IPv6 両 host を登録。
            $redirects = [
                'http://127.0.0.1/callback',
                'http://[::1]/callback',
            ];
        }

        $client = $clients->createAuthorizationCodeGrantClient(
            name: $name,
            redirectUris: $redirects,
            confidential: false,
        );

        $clientId = $client->getKey();
        Assert::scalar($clientId);

        DB::table('oauth_clients')
            ->where('id', $clientId)
            ->update(['client_kind' => OAuthClientKind::Cli->value]);

        $this->info(sprintf('CLI OAuth client created: %s', (string) $clientId));
        $this->line('Client kind: '.OAuthClientKind::Cli->value);
        $this->line('Redirect URIs: '.implode(', ', $redirects));

        return self::SUCCESS;
    }
}
