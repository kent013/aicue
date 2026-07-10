<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 認証・セキュリティイベントの監査ログ (監査 3 層のうち Layer 2)。
     * 操作ログ (AuditLog) / 管理属性 diff (ModelAudit) とは責務を分ける
     * (devnotes/20260611-template-extraction/11 §4)。
     */
    public function up(): void
    {
        Schema::create('security_audit_events', function (Blueprint $table) {
            $table->id();
            // 退会後もイベント自体は監査証跡として残す
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'event_type']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_audit_events');
    }
};
