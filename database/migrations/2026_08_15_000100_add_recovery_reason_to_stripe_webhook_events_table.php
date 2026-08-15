<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 滞留した webhook 記録を回収待ちへ置いた理由 (App\Enums\Billing\WebhookRecoveryReason)
     * と、滞留回収 cron が使う複合 index。
     *
     * 不変条件: recovery_reason が非 NULL ⟺ status = 'recovery_pending'。既存行はすべて NULL
     * (回収待ちの行はこの migration の時点で 1 件も存在しない)。
     * 自由文の failure_reason とは分ける (機械判定できる値と混ぜない)。
     *
     * index: billing:recover-stale-webhook-events が 5 分ごとに
     * `status='received' AND updated_at <= 閾値` を引く。本表は保持期限 (7 年) まで
     * 残るため単調に増える = 全表走査にしない。
     * 監視で使う status='recovery_pending' の件数も同じ index の先頭列で効く。
     */
    public function up(): void
    {
        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->string('recovery_reason')->nullable()->after('failure_reason');
            $table->index(['status', 'updated_at'], 'stripe_webhook_events_status_updated_at_index');
        });

        // CHECK は sqlite の ALTER TABLE ADD CONSTRAINT 非対応のため driver guard
        // (既存 ticket_auto_recharges の CHECK と同じ作法)。
        // 全 driver 共通の防御はアプリ層 (StripeWebhookProcessor の書き込み経路) が担う。
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            DB::statement(
                'ALTER TABLE stripe_webhook_events ADD CONSTRAINT stripe_webhook_events_recovery_reason_state_check '
                ."CHECK ((recovery_reason IS NULL AND status <> 'recovery_pending') "
                ."OR (recovery_reason IS NOT NULL AND status = 'recovery_pending'))",
            );
        }
    }

    public function down(): void
    {
        // CHECK の削除構文は driver で違う (pgsql は DROP CONSTRAINT / mysql は DROP CHECK)。
        // 共通化できないので分けて書く。sqlite は up() で作っていないので何もしない。
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE stripe_webhook_events DROP CONSTRAINT IF EXISTS '
                .'stripe_webhook_events_recovery_reason_state_check',
            );
        }

        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE stripe_webhook_events DROP CHECK '
                .'stripe_webhook_events_recovery_reason_state_check',
            );
        }

        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->dropIndex('stripe_webhook_events_status_updated_at_index');
            $table->dropColumn('recovery_reason');
        });
    }
};
