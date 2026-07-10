<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP 書き込み系 tool 用の冪等性ストア。
 *
 * 既存 `idempotency_keys` (REST 向け) とは独立。unique scope は
 * `(organization_id, user_id, tool_name, idempotency_key)` で、
 * 同一 user の純粋 retry を replay しつつ、同一 org の別 user の
 * 偶発衝突を防ぐ。`user_id` を unique に使うことで refresh token
 * で access token が回転しても replay 継続する。
 * (利用側 McpIdempotencyService は WP25 で導入)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool_name', 64);
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64); // sha256 hex
            $table->json('response_body')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at');

            $table->unique(
                ['organization_id', 'user_id', 'tool_name', 'idempotency_key'],
                'mcp_idempotency_unique',
            );
            $table->index('expires_at'); // TTL sweep 用
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_idempotency_keys');
    }
};
