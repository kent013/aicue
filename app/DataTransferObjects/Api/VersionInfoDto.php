<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Api;

/**
 * `GET /api/v1/version` (capability negotiation) のレスポンス shape。
 *
 * CLI / クライアントは認証前にこの payload で互換性を判定する:
 * - `min_cli_version` 未満の CLI は自ら update を促して停止する
 * - `capabilities` は機能フラグ (未知は無視、必要フラグの欠落で機能を無効化)
 * - `cli_oauth_client_id` は first-party CLI の public client id (非秘密)。
 *   未発行 / 複数検出時は null (CLI 側は明示指定にフォールバックする)
 */
final class VersionInfoDto
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public readonly string $name,
        public readonly string $apiVersion,
        public readonly string $templateVersion,
        public readonly string $serverVersion,
        public readonly string $minCliVersion,
        public readonly array $capabilities,
        public readonly ?string $cliOauthClientId,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     api_version: string,
     *     template_version: string,
     *     server_version: string,
     *     min_cli_version: string,
     *     capabilities: list<string>,
     *     cli_oauth_client_id: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'api_version' => $this->apiVersion,
            'template_version' => $this->templateVersion,
            'server_version' => $this->serverVersion,
            'min_cli_version' => $this->minCliVersion,
            'capabilities' => $this->capabilities,
            'cli_oauth_client_id' => $this->cliOauthClientId,
        ];
    }
}
