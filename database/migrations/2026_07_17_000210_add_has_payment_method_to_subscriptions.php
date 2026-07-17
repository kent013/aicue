<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * has_payment_method: PM 登録済みか (monotonic snapshot・true から false へ戻さない)。
 *
 * `SubscriptionService::deriveEntitlement` の入力。既定は false (移植元と同値) で、
 * 既存行は分離した data migration (backfill_has_payment_method_on_subscriptions) が
 * true へ倒す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->boolean('has_payment_method')->default(false)->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('has_payment_method');
        });
    }
};
