<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Template Configuration
|--------------------------------------------------------------------------
|
| テンプレート由来アプリの「環境座標」を集約する config。
| アプリ名・prefix 等をコードにハードコードせず、必ずここを参照すること。
| (テンプレート設計判断: devnotes/20260611-template-extraction/08 §5)
|
*/

return [

    /*
    | アプリの slug。API キーの prefix、CLI 設定ディレクトリ名などの機械可読な
    | 識別子に使う。小文字英数のみ。表示名は app.name を使うこと。
    */
    'slug' => env('TEMPLATE_APP_SLUG', 'app'),

    /*
    | このアプリが生成された時点のテンプレートバージョン。
    | テンプレート更新の取り込み判断に使う。
    */
    'template_version' => '0.1.0-dev',

    /*
    | 有効化する SSO プロバイダ。key がプロバイダ名 (Socialite driver 名)。
    | 追加時は config/services.php に client_id/secret/redirect を定義し、
    | resources/js の SSO ボタン表示名を確認すること。
    |
    | capability: recent-auth (step-up 再認証) の再SSO satisfier としての保証レベル
    | (App\Enums\ProviderCapability の value)。テンプレート標準の Socialite フローは
    | id_token の auth_time を検証しないため fresh_auth_prompt_only が上限。
    | auth_time を返さない OAuth-only provider (GitHub 等) は identity_only にして
    | satisfier から除外すること。未宣言は identity_only 扱い (fail-closed)。
    |
    | email_trust: IdP の主張する email を検証済みとして扱ってよいか
    | (App\Enums\EmailTrustLevel の value)。未宣言は unconfirmed 扱い (fail-closed)。
    | confirmed を名乗ってよいのは「provider が email 所有を検証済み」かつ
    | 「テナント管理者が任意の email を claim できない」の 2 条件を満たす provider のみ。
    | 宣言漏れは tests/Architecture/SocialProviderTrustPolicyTest.php が検出する。
    */
    'social_providers' => [
        'google' => [
            'label' => 'Google',
            'capability' => 'fresh_auth_prompt_only',
            // Google は Gmail / Workspace とも email 所有を検証しており、管理者は
            // 所有権を証明したドメイン外を claim できないため confirmed。
            // Microsoft (Entra ID) は管理者が未検証 email claim を設定できる (nOAuth) ため
            // 追加する場合は必ず unconfirmed から始めること。
            'email_trust' => 'confirmed',
        ],
    ],

    /*
    | 組織の部門 (CustomTeam) 層を UI に露出するか。
    | false の場合も スキーマは 3 階層のまま、各組織の Default Team に
    | プロジェクトが自動所属する (docs/default-team-pattern.md)。
    */
    'teams_visible' => (bool) env('TEMPLATE_TEAMS_VISIBLE', false),

    /*
    | GET /api/v1/version の capability negotiation メタ情報。
    | server_version / min_cli_version は semver 文字列 (VersionInfoService が
    | リクエスト時に fail-fast 検証する。設定ミスは 500 で早期発覚させる)。
    | capabilities はサーバが提供する機能の機械可読フラグ (アプリで追記する。
    | CLI は未知の capability を無視し、必要な capability の欠落で機能を無効化する)。
    */
    'api' => [
        'server_version' => env('TEMPLATE_SERVER_VERSION', '0.1.0'),
        'min_cli_version' => env('TEMPLATE_MIN_CLI_VERSION', '0.1.0'),
        'capabilities' => [],
    ],

    /*
    | first-party CLI の OAuth token TTL。App\Support\OAuth\CliTokenTtl が
    | 安全域に clamp して適用する (access: 5〜1440 分 / refresh: 1〜90 日)。
    | Passport global TTL (MCP 経路の 30/90 日) には波及しない。
    */
    'cli_oauth' => [
        'access_ttl_minutes' => (int) env('TEMPLATE_CLI_ACCESS_TTL_MINUTES', 60),
        'refresh_ttl_days' => (int) env('TEMPLATE_CLI_REFRESH_TTL_DAYS', 30),
    ],

];
