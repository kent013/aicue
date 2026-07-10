<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stripe webhook の冪等マシン (不変条件 7: webhook は冪等マシン経由)。
     *
     * event_id UNIQUE で同一イベントの二重処理を防ぐ。Stripe は同一イベントを
     * 再送し得る (タイムアウト・5xx 応答時) ため、受信記録と処理状態を分離する。
     */
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            // received | processed | failed (App\Enums\Billing\WebhookEventStatus)
            $table->string('status')->default('received');
            $table->json('payload');
            // 処理失敗の再試行回数 (failed→received 復帰時にインクリメント)。
            // MAX_PROCESSING_ATTEMPTS 到達で terminal-ack し Stripe の自動再送を打ち切る
            $table->unsignedInteger('attempts')->default(0);
            // 直近の処理失敗理由 (運用調査用。成功時は null に戻す)
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
