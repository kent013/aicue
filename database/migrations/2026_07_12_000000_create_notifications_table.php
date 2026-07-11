<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * notifications: Laravel 標準 database 通知スキーマ + organization_id first-class 列。
     *
     * - type には NotificationType enum の value を格納する (クラス名を DB に置かない。
     *   規約は InAppNotificationTypeInvariantTest が全 AppNotification 派生に強制する)
     * - organization_id は org 文脈のサーバ導出列 (OrganizationScopedDatabaseChannel が埋める)。
     *   org 削除で通知ごと消える (cascade)。org 判定・クエリには data (jsonb) を使わない
     *   (data は表示用 payload 限定)
     * - 複合 index (notifiable_type, notifiable_id, read_at) は未読数 1 クエリの担保
     *   (標準 morphs index は read_at を含まない)
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
