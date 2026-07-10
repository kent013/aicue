<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passport v13 の oauth テーブル群 (MCP OAuth 2.1 用)。
 *
 * vendor 標準の 5 テーブルに、テンプレートの組織バインド設計で必要な列を
 * 最初から畳み込んで作成する:
 * - oauth_auth_codes.organization_id / session_id:
 *   consent で選ばれた組織を auth code 段階で永続化し、token 交換時に
 *   McpAuthorizationCodeGrant → McpAccessTokenRepository が access token へ継承する。
 * - oauth_access_tokens.organization_id / session_id: 継承先。
 * - oauth_clients.client_kind: client の種別 ('mcp' / 'cli' 等)。DCR で登録された
 *   MCP client と first-party CLI client を scope 非依存で識別する (WP25 で利用)。
 *
 * organization_id は nullable: 将来の platform-scoped token 等で null を許容する
 * 余地を残す (mcp:use scope を含む token は application 層で NOT NULL を強制する)。
 * session_id は FK を張らない (session revoke は論理無効化で、token を physical
 * delete しない方針。OauthSession モデルは WP24 で導入)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_auth_codes', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->index();
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();
            $table->uuid('session_id')->nullable()->index();
            $table->foreignUuid('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();

            $table->index('organization_id');
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();
            $table->uuid('session_id')->nullable()->index();
            $table->foreignUuid('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();

            $table->index('organization_id');
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->char('access_token_id', 80)->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->string('client_kind', 32)->nullable();
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            $table->timestamps();
        });

        Schema::create('oauth_device_codes', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignUuid('client_id')->index();
            $table->char('user_code', 8)->unique();
            $table->text('scopes');
            $table->boolean('revoked');
            $table->dateTime('user_approved_at')->nullable();
            $table->dateTime('last_polled_at')->nullable();
            $table->dateTime('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_device_codes');
        Schema::dropIfExists('oauth_clients');
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_auth_codes');
    }

    /**
     * oauth_* テーブルは passport.connection に従う (未設定なら default connection)。
     */
    public function getConnection(): ?string
    {
        if (is_string($this->connection)) {
            return $this->connection;
        }

        $connection = config('passport.connection');

        return is_string($connection) ? $connection : null;
    }
};
