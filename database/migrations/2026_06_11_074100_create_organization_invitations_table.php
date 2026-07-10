<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 組織招待。token は平文を保存せず sha256 ハッシュのみ (token_hash)。
     * email は招待メール送信用の平文を持たない (CipherSweet 暗号化カラム + blind index)。
     */
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            // 論理失効 (取り消し)。行削除ではなく revoked_at で無効化する (監査痕跡を残す)
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            // active scope (未受諾・未失効・期限内) の lookup 用複合 index
            $table->index(
                ['organization_id', 'revoked_at', 'accepted_at', 'expires_at'],
                'org_invitations_active_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
