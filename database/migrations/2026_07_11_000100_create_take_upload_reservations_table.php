<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * テイクアップロード予約 (doc/10 §10.8-4/-7、概念設計 D2/D3 の真実源)。
     *
     * - presigned PUT 発行時に容量 Quota (bytes_pending) を予約する
     * - status: pending → verifying → completed / released (アプリ層 enum cast)
     * - organization_id は org 集計用の非正規化キー (サーバ導出・protected。join 4 段を避ける)
     */
    public function up(): void
    {
        Schema::create('take_upload_reservations', function (Blueprint $table): void {
            $table->id();
            // cut 配下の予約 (サーバ導出・protected)。cut 削除で予約も無効化 (S3 掃除は cron が拾う)
            $table->foreignId('cut_id')->constrained()->cascadeOnDelete();
            // bytes_pending の org 集計用の非正規化キー (サーバ導出・protected)
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('client_take_id', 26);          // 端末生成 ULID (照合用)
            $table->string('video_path');                  // サーバ生成 S3 キー
            $table->unsignedBigInteger('size_bytes');      // クライアント申告 → HeadObject で確定照合
            $table->string('content_type', 100);
            $table->string('checksum_sha256', 44);         // blob SHA-256 の base64 表現 (44 文字固定)。presign 署名で内容を固定 (D2b)
            $table->string('status', 20)->default('pending'); // string enum + アプリ層 cast (既存規約)
            $table->timestamp('expires_at');               // チケット TTL と同値
            $table->timestamps();
            $table->index(['organization_id', 'status', 'expires_at']); // bytes_pending 集計・stale 掃除
            $table->index(['cut_id', 'client_take_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('take_upload_reservations');
    }
};
