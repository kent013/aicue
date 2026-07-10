<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * API キー (organization スコープ。Q8 決定: flat ability)。
     *
     * - 平文キーは "{slug}_{prefix8}_{secret40}" 形式。保存しない。
     *   secret の Argon2id ハッシュ (key_hash) のみ保存する
     * - key_prefix は表示・検索用の非機密 prefix ("{slug}_{prefix8}"。認証時の indexed lookup キー)
     * - abilities は flat な文字列配列 (read / write。将来 'items:run' 型を追加可能)
     * - 失効は revoked_at (行削除しない = 監査痕跡を残す)、期限は expires_at (null = 無期限)
     */
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_prefix')->index();
            $table->string('key_hash');
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
