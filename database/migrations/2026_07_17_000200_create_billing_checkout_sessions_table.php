<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * サブスク契約 Checkout の追跡行 (`BillingAccess::state()` が PendingCheckout /
 * ExpiredCheckout を読む状態モデルの一部)。
 *
 * - attempt_token: 画面 render ごとに固定される ULID。browser-back / 二重 submit で
 *   同じ token が再送されても新規 Checkout を発行しない (= 二重課金防止)。
 * - checkout_url: Pending 行の再生 (同じ Checkout に戻す) のために URL を保持する。
 * - unique(organization_id, intent, attempt_token): 契約 attempt 単位の冪等を DB invariant で
 *   最終保証する。複合 unique 内の NULL は重複許容のため、token を持たない行は抵触しない。
 * - initiated_by_user_id: 購入意図を起こした user (nullable FK→users, nullOnDelete)。
 *
 * 席 (seats) / チケット枚数・単価 (credit_count・unit_amount。`ticket_checkout_sessions` が担う) /
 * signup funding・campaign・trial 列は AI-CUE に対象機構が無いため列ごと非移植。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_checkout_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('intent', 32); // subscription_start|setup_payment_method
            $table->string('plan_code', 32)->nullable();
            $table->string('stripe_session_id')->unique();
            $table->string('idempotency_key', 128)->unique();
            $table->string('attempt_token')->nullable();
            $table->string('checkout_url', 2048)->nullable();
            $table->string('status', 16)->default('pending'); // pending|completed|failed|expired
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'intent', 'status']);
            $table->unique(
                ['organization_id', 'intent', 'attempt_token'],
                'billing_checkout_sessions_org_intent_attempt_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_checkout_sessions');
    }
};
