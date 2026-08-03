<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P8a: オートリチャージ設定 (1 org 1 行)。
 *
 * 無料パーソナル (Stripe サブスクなし) でも持てるため subscription FK は持たない。
 * 同意スナップショット (consent_*) は off-session mandate の記録 (再同意判定の出典)。
 * **既定 off の opt-in**: 行が無い org の挙動は完全不変。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_auto_recharges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('threshold_count');
            $table->unsignedInteger('max_count');
            // 保存 PM の表示用 snapshot (真実源は Stripe customer の invoice_settings.default_payment_method)
            $table->string('stripe_payment_method_id', 64)->nullable();
            $table->unsignedTinyInteger('failure_count')->default(0);
            $table->string('disabled_reason', 32)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_version', 16)->nullable();
            $table->unsignedInteger('consented_max_count')->nullable();
            $table->unsignedInteger('consented_max_amount')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // CHECK は sqlite の ALTER TABLE ADD CONSTRAINT 非対応のため driver guard。
        // 全 driver 共通の防御はアプリ層 (FormRequest + Service Assert) が担う。
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            DB::statement('ALTER TABLE ticket_auto_recharges ADD CONSTRAINT ticket_auto_recharges_max_gt_threshold CHECK (max_count > threshold_count)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_auto_recharges');
    }
};
