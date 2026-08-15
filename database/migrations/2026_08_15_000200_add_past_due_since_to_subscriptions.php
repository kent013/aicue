<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * past_due_since: 支払い失敗 (stripe_status='past_due') を**観測した**時刻。
 *
 * `PaymentGracePolicy` が猶予期限を計算する起点で、`SubscriptionService::deriveEntitlement`
 * が猶予切れの遮断に使う。**Stripe 側で実際に失敗した時刻ではない** (webhook 欠落時は
 * 日次突き合わせが観測した時刻になる)。書込は SubscriptionService に閉じる
 * (PastDueSinceWriteInvariantTest)。既存行は分離した data migration が埋める。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('past_due_since')->nullable()->after('has_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('past_due_since');
        });
    }
};
