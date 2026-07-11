<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * チケットスポット購入の Stripe Checkout Session 追跡 (冪等マシンの真実源)。
 *
 * - attempt_token: 画面 render ごとに発行される ULID。UNIQUE(org, attempt_token) で
 *   同一 attempt の再送を同一 session に収束させる
 * - unit_amount / currency: 作成時単価の pin (webhook 金額照合の出典)
 * - expires_at: Stripe session expires_at の pin (「live pending」判定の決定基準)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_checkout_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // 監査行のため user 削除でも行は残す (null 化)
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('ticket_count');
            $table->unsignedInteger('unit_amount');
            $table->string('currency', 8);
            $table->string('stripe_session_id')->unique();
            $table->string('attempt_token', 26);
            $table->string('checkout_url', 2048);
            $table->string('status'); // pending / completed / expired (アプリ層 enum cast)
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'attempt_token']);
            $table->index(['organization_id', 'initiated_by_user_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_checkout_sessions');
    }
};
