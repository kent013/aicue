<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency-Key の保存先 (REST API v1 の write エンドポイント用)。
     *
     * - actor 単位 × route で一意: API キー actor は (api_key_id, route_name, key)、
     *   OAuth user-token actor は (user_id, route_name, key)。api_key_id / user_id は
     *   どちらか一方のみ非 NULL (IdempotentRequest middleware が actor 種別で書き分ける)
     * - request_hash はメソッド + パス + body の sha256 (同一 key + 別 body は 409)
     * - response_status / response_body に成功レスポンスを保存し、再送時に再生する
     * - expires_at 超過エントリは未使用扱い (TTL。index は掃除バッチ / lookup 用)
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('route_name');
            $table->string('key');
            $table->string('request_hash');
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();

            // NULL は unique 制約で常に distinct (pgsql / sqlite 共通) のため、
            // 2 本の unique が actor 種別ごとの一意性をそれぞれ担保する
            $table->unique(['api_key_id', 'route_name', 'key']);
            $table->unique(['user_id', 'route_name', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
