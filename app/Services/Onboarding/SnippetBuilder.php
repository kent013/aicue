<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Models\Organization;
use Illuminate\Support\Facades\Config;

/**
 * MCP / CLI 導入オンボーディング画面で表示するセットアップスニペットを集約生成する。
 *
 * 純粋関数の集合: 依存は `app.url` / `app.name` / `template.slug` の config のみ。
 * アプリ名・endpoint をコードにハードコードせず必ず config 経由で埋める
 * (AppNameHardcodeTest 相当の検査に耐える)。平文 API キーは **絶対に埋めない**
 * (常に {@see self::PLAIN_KEY_PLACEHOLDER} placeholder)。同一入力に対し出力は冪等。
 */
final class SnippetBuilder
{
    /** CLI / スニペット中で API キーの実値の代わりに置く placeholder。 */
    public const PLAIN_KEY_PLACEHOLDER = '<YOUR_API_KEY>';

    /** MCP エンドポイント URL (OAuth 2.1 / Streamable HTTP。組織 scope は consent 画面で選択)。 */
    public function mcpEndpointUrl(): string
    {
        return $this->appBaseUrl().'/api/v1/mcp';
    }

    /** REST API v1 のベース URL。 */
    public function restApiBaseUrl(): string
    {
        return $this->appBaseUrl().'/api/v1';
    }

    /**
     * Claude Desktop / Cursor 等の `mcpServers` 設定ブロック相当 (URL を貼るだけの HTTP 接続)。
     *
     * サーバキーは `config('template.slug')` を使い、アプリ名をハードコードしない。
     *
     * @return array{mcpServers: array<string, array{type: string, url: string}>}
     */
    public function mcpConfigArray(): array
    {
        return [
            'mcpServers' => [
                $this->slug() => [
                    'type' => 'http',
                    'url' => $this->mcpEndpointUrl(),
                ],
            ],
        ];
    }

    /** {@see self::mcpConfigArray()} を整形済み JSON 文字列にしたもの (コピペ用)。 */
    public function mcpConfigJson(): string
    {
        return json_encode(
            $this->mcpConfigArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** Claude Code CLI への MCP 登録ワンライナー。 */
    public function mcpClaudeCodeCommand(): string
    {
        return sprintf('claude mcp add --transport http %s %s', $this->slug(), $this->mcpEndpointUrl());
    }

    /** CLI のインストールコマンド (公開配布。ダウンロードに API キーは不要)。 */
    public function cliInstallCommand(): string
    {
        return sprintf('npm install -g @%s/cli@latest', $this->slug());
    }

    /**
     * CLI の profile 登録 + 認証コマンド。
     *
     * 人間の認証はブラウザ OAuth ログインが標準 (API キーは CI / 自動化専用)。
     * profile 名は組織ごとに一意化するため slug を含める。
     */
    public function cliProfileCommands(Organization $organization): string
    {
        $bin = $this->slug();
        $profile = $this->profileName($organization);
        $apiUrl = $this->appBaseUrl();

        return <<<BASH
        # 1. profile を登録 (api-url はアプリのルート URL)
        {$bin} profile:add {$profile} --api-url {$apiUrl}

        # 2. ブラウザでログイン (OAuth。API キーの発行・入力は不要)
        {$bin} auth:login --profile {$profile}
        BASH;
    }

    /**
     * CI / 自動化向け: API キーを環境変数経由で登録するスニペット。
     * 平文キーは埋めず placeholder + シークレット参照で示す。
     */
    public function cliApiKeyLogin(Organization $organization): string
    {
        $bin = $this->slug();
        $profile = $this->profileName($organization);
        $envVar = strtoupper($this->slug()).'_API_KEY';

        return <<<BASH
        # CI / 自動化 (ブラウザを開けない環境) 向け。API キーはシークレットから読む。
        export {$envVar}={$this->plainKeyPlaceholder()}
        printf '%s' "\${$envVar}" | {$bin} auth:login --profile {$profile} --stdin
        BASH;
    }

    /** 平文キー placeholder (テストからの参照用に method でも公開)。 */
    public function plainKeyPlaceholder(): string
    {
        return self::PLAIN_KEY_PLACEHOLDER;
    }

    private function slug(): string
    {
        $slug = Config::get('template.slug', 'app');

        return is_string($slug) && $slug !== '' ? $slug : 'app';
    }

    private function appBaseUrl(): string
    {
        $url = Config::get('app.url', 'http://localhost');

        return rtrim(is_string($url) ? $url : 'http://localhost', '/');
    }

    /** 組織ごとに一意な CLI profile 名 (id ベースの簡易スラッグ。ユーザーは後で変更可)。 */
    private function profileName(Organization $organization): string
    {
        return sprintf('org-%d', $organization->id);
    }
}
