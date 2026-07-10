<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashier の subscriptions テーブル (vendor:publish を organization billable 向けに調整)。
     *
     * 調整内容: user_id → organization_id。Cashier 16 は FK 列名を customer model の
     * getForeignKey() から導出するため、列名を合わせるだけで relation はそのまま動く
     * (Laravel\Cashier\Subscription::owner() 参照)。
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            // 次回更新日時 (renewal reminder の真実源。StripeWebhookProcessor が webhook から同期)
            $table->timestamp('current_period_end')->nullable();
            // Subscription Schedule の 2 段 API call (create → update phases) の部分完了追跡
            // (billing:reconcile-schedules が復旧する。App\Enums\Billing\ScheduleSetupStatus)
            $table->string('stripe_schedule_id')->nullable();
            $table->string('schedule_setup_status', 16)->default('none');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'stripe_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
