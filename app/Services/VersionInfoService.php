<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Api\VersionInfoDto;
use App\Enums\OAuth\OAuthClientKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webmozart\Assert\Assert;

/**
 * `GET /api/v1/version` の payload (capability negotiation) の single source。
 *
 * 意図的に stateless / cheap に保つ: 毎呼び出し config() を読むので
 * テストの `config([...])` override が即反映される。
 */
final class VersionInfoService
{
    /**
     * semver.org 2.0.0 準拠の正規表現。
     *
     * 公式ドキュメント推奨パターン
     * (https://semver.org/#is-there-a-suggested-regular-expression-regex-to-check-a-semver-string)
     * を PHP の PCRE 形式にアンカー付きで移植し、pre-release と build metadata
     * の両方を許容する。private const に切り出しておくことで将来の更新時に
     * diff が明確になる。
     */
    private const SEMVER_REGEX = '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
        .'(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)'
        .'(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?'
        .'(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

    /**
     * version descriptor を構築する。
     *
     * semver 検証は **fail-fast**: 設定ミス (`TEMPLATE_SERVER_VERSION=invalid` 等) は
     * CLI の version negotiation を静かに壊すのではなく、リクエスト時に大きく壊して
     * デプロイ時に発覚させる。
     */
    public function getVersionInfo(): VersionInfoDto
    {
        $name = config('app.name');
        Assert::string($name);
        $templateVersion = config('template.template_version');
        Assert::string($templateVersion);

        $serverVersion = config('template.api.server_version', '0.1.0');
        $minCliVersion = config('template.api.min_cli_version', '0.1.0');
        Assert::string($serverVersion, 'TEMPLATE_SERVER_VERSION must be a string');
        Assert::string($minCliVersion, 'TEMPLATE_MIN_CLI_VERSION must be a string');
        Assert::regex($serverVersion, self::SEMVER_REGEX, 'TEMPLATE_SERVER_VERSION must be valid semver');
        Assert::regex($minCliVersion, self::SEMVER_REGEX, 'TEMPLATE_MIN_CLI_VERSION must be valid semver');

        return new VersionInfoDto(
            name: $name,
            apiVersion: 'v1',
            templateVersion: $templateVersion,
            serverVersion: $serverVersion,
            minCliVersion: $minCliVersion,
            capabilities: $this->resolveCapabilities(),
            cliOauthClientId: $this->resolveCliOAuthClientId(),
        );
    }

    /**
     * `/version` で公開する first-party CLI の public client id を決定的に解決する。
     *
     * 不変条件: public CLI client は高々 1 つ。`client_kind='cli' AND revoked=false
     * AND secret IS NULL` が **ちょうど 1 行** 一致した場合のみ id を返す。
     * 0 行 / 2 行以上は null にフォールバックする (fail-safe — CLI は明示指定で
     * 動作を続けられ、推測で誤 client に誘導しない)。
     *
     * public PKCE client の client_id は非秘密の識別子なので、未認証の `/version`
     * で公開しても安全 (public OAuth client は設計上 client_id を公開する)。
     */
    private function resolveCliOAuthClientId(): ?string
    {
        $ids = DB::table('oauth_clients')
            ->where('client_kind', OAuthClientKind::Cli->value)
            ->where('revoked', false)
            ->whereNull('secret')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->all();

        if (count($ids) !== 1) {
            if (count($ids) > 1) {
                // 複数 public CLI client は設定ミスの早期シグナル。log のみに留める。
                Log::warning(
                    'Multiple public CLI OAuth clients detected; /version omits cli_oauth_client_id.',
                    ['detected_at_least' => count($ids)],
                );
            }

            return null;
        }

        $id = $ids[0];

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * @return list<string>
     */
    private function resolveCapabilities(): array
    {
        $caps = config('template.api.capabilities', []);
        Assert::isArray($caps);
        Assert::allString($caps);

        // array_values で再インデックスし、list<string> を保証する。
        return array_values($caps);
    }
}
