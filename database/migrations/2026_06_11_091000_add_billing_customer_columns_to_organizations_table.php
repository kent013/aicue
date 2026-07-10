<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashier の customer columns (vendor:publish した create_customer_columns を
     * users → organizations に調整したもの)。課金主体 (billable) は Organization
     * (AppServiceProvider の Cashier::useCustomerModel)。
     *
     * plan_code は「現在の契約プラン」の状態キー (webhook で同期)。
     * クライアント入力で書き換えてはならないため $fillable 外 (明示代入のみ)。
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Cashier 標準 customer columns
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            // 現在プラン (plans.code 参照。null = 未契約 → config/quota.php の fallback_plan)
            $table->string('plan_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);

            $table->dropColumn([
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at',
                'plan_code',
            ]);
        });
    }
};
