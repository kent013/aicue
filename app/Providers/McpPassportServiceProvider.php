<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\OAuth\CliOAuthScope;
use App\Models\Organization;
use App\Models\User;
use App\Passport\Grants\McpAuthorizationCodeGrant;
use App\Passport\Grants\McpRefreshTokenGrant;
use App\Passport\McpAccessTokenRepository;
use App\Passport\McpAuthCodeRepository;
use DateInterval;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

/**
 * Passport の AuthCode / RefreshToken grant を自前版に差し替える Service Provider。
 *
 * laravel/passport は composer.json の dont-discover で自動 discovery を無効化し、
 * 本 Provider (bootstrap/providers.php) が唯一の Passport 登録点になる。
 * PassportServiceProvider::buildAuthCodeGrant() / makeRefreshTokenGrant() を override し、
 * PKCE S256 強制かつ organization_id / session_id 継承を行う grant を有効化する。
 *
 * repositories (AuthCodeRepository / AccessTokenRepository) の差替は register() で
 * container bind し直す。
 */
final class McpPassportServiceProvider extends PassportServiceProvider
{
    public function register(): void
    {
        parent::register();

        // Passport 標準の bind を上書きして Mcp 版 Repository を resolve させる。
        $this->app->singleton(AuthCodeRepository::class, McpAuthCodeRepository::class);
        $this->app->singleton(AccessTokenRepository::class, McpAccessTokenRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->configurePassport();
    }

    /**
     * MCP 経路用 Passport 設定 (scope / consent view / TTL / rotation)。
     */
    private function configurePassport(): void
    {
        // Scope は MCP 経路の `mcp:use` + CLI user token 用の CliOAuthScope 群
        // (`cli:use` / `read` / `write` / `session.revoke`)。tool 粒度の認可は
        // runtime 再評価で行う。アプリ固有 ability を追加する場合は
        // App\Enums\OAuth\CliOAuthScope に同じ string 値の case を足してここに追記する。
        Passport::tokensCan([
            'mcp:use' => 'MCP サーバーを利用する',
            CliOAuthScope::CliUse->value => 'CLI から REST API v1 を利用する',
            CliOAuthScope::Read->value => '読み取り (REST read ability 相当)',
            CliOAuthScope::Write->value => '書き込み (REST write ability 相当)',
            CliOAuthScope::SessionRevoke->value => 'CLI セッションの失効 (logout)',
        ]);

        // consent 画面は組織選択付きの standalone Blade (resources/views/mcp/authorize.blade.php)。
        // 選択候補はログインユーザーが所属する組織のみ (改ざんは McpConsentOrganizationBinder が最終防御)。
        Passport::authorizationView(function (array $parameters): Response {
            $user = $parameters['user'] ?? null;
            Assert::isInstanceOf($user, User::class, 'Passport authorize view requires an authenticated User.');

            $organizations = $user->organizations()
                ->orderBy('name')
                ->get()
                ->map(fn (Organization $org): array => [
                    'id' => $org->id,
                    'name' => $org->name,
                ])
                ->values();

            return response()->make(view('mcp.authorize', array_merge($parameters, [
                'organizations' => $organizations,
            ]))->render());
        });

        // Token TTL は access 30 日 / refresh 90 日 (運用で調整)。
        Passport::tokensExpireIn(new DateInterval('P30D'));
        Passport::refreshTokensExpireIn(new DateInterval('P90D'));

        // OAuth 2.1 契約 — refresh token rotation 明示。
        // Passport v13 default は `$revokeRefreshTokenAfterUse = true` だが、
        // 明示的に固定することで upgrade 耐性と設計意図の可視化を担保する。
        // property_exists ガード: Passport v14+ で property 名が変わった場合に
        // fatal error ではなく明示的な RuntimeException で upgrade task を示唆する。
        if (property_exists(Passport::class, 'revokeRefreshTokenAfterUse')) {
            Passport::$revokeRefreshTokenAfterUse = true;
        } else {
            throw new RuntimeException(
                'Passport::$revokeRefreshTokenAfterUse が見つかりません。'
                .'laravel/passport の upgrade に伴い refresh rotation の設定方法が変わった可能性があります。',
            );
        }
    }

    protected function buildAuthCodeGrant(): AuthCodeGrant
    {
        // league/oauth2-server v9 は `requireCodeChallengeForPublicClients = true` が
        // デフォルトなので PKCE は public client に対して自動強制される (OAuth 2.1)。
        // S256 限定 (plain 拒否) は McpAuthorizationCodeGrant 側で上乗せする。
        return new McpAuthorizationCodeGrant(
            $this->app->make(AuthCodeRepository::class),
            $this->app->make(RefreshTokenRepository::class),
            new DateInterval('PT10M'),
        );
    }

    protected function makeRefreshTokenGrant(): RefreshTokenGrant
    {
        $grant = new McpRefreshTokenGrant(
            $this->app->make(RefreshTokenRepository::class),
        );
        $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());

        return $grant;
    }
}
