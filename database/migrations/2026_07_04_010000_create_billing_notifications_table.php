<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 請求通知の delivery record (通知台帳)。送信の冪等を複合 UNIQUE で構造保証する:
     * - 支払い通知 (invoice 起点): (type, invoice_id)
     * - 予告 reminder (cron 起点): (type, dedup_key)。dedup_key は {stripe_id}:{該当日時 epoch}
     *
     * 片方の経路では他方の列が null (NULL は UNIQUE 上 distinct のため衝突しない)。
     */
    public function up(): void
    {
        Schema::create('billing_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);                 // BillingNotificationType value
            $table->string('invoice_id')->nullable();   // 支払い通知の自然キー (Stripe invoice ID、突合用)
            $table->string('dedup_key')->nullable();    // 予告 (reminder) の汎用冪等キー
            $table->string('status', 16);               // queued / sent / failed
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 512)->nullable();   // 例外クラス名 + 短縮メッセージ (PII 抑制)
            $table->timestamps();

            // 連結文字列キーでなく複合 UNIQUE で冪等を構造保証。同一 invoice の成功/失敗は
            // 別種別 = 別行、同一 (種別, invoice) / (種別, dedup_key) の再送は 1 行のみ成立。
            $table->unique(['type', 'invoice_id']);
            $table->unique(['type', 'dedup_key']);
            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_notifications');
    }
};
