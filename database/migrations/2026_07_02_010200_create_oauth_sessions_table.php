<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth セッション集約テーブル (WP24)。
 *
 * 「認可承認 1 回 = 1 セッション」の集約ルート。CLI の consent approve 時に 1 行作成され、
 * 発行される access token / refresh token の rotation chain 全体が `session_id` で
 * このテーブルを参照する (継承経路は oauth_access_tokens.organization_id と同一パターン)。
 *
 * - 一覧表示 / 失効操作はセッション単位 (Web UI / `DELETE /api/v1/me/session`)
 * - 失効の実体は配下 token の revoke (Passport guard が既存機構で拒否)。
 *   `revoked_at` は表示・冪等化・actor 解決の拒否判定用
 * - `client_kind` は作成時点の snapshot (`oauth_clients.client_kind`)。
 *   REST dual guard の「CLI セッション由来 token か」の二重判定に使う
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->string('client_kind', 32);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'revoked_at']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_sessions');
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
