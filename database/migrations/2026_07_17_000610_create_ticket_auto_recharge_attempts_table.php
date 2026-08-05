<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P8a: リチャージ試行の状態機械 (pending → paid | failed | canceled)。
 *
 * quantity は attempt 作成時に一度だけ確定し以降の真実源。unit_amount は
 * webhook amount cross-check の pin。
 */
return new class extends Migration
{
    private const string PENDING_INDEX_NAME = 'tar_attempts_org_pending_unique';

    public function up(): void
    {
        Schema::create('ticket_auto_recharge_attempts', function (Blueprint $table): void {
            $table->id();
            // Stripe idempotency key / invoice metadata に載せる外部識別子 (連番 id を漏らさない)
            $table->ulid('attempt_ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_amount');
            $table->string('stripe_price_id', 64);
            $table->string('stripe_invoice_id', 64)->nullable()->unique();
            $table->string('stripe_payment_intent_id', 64)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // 「org に pending は同時に 1 つまで」の hard invariant。
        // 部分 UNIQUE index の driver guard は既存前例
        // (2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries) に揃える
        // = 非対応 driver は fail-closed (黙って invariant を落とさない)。
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("部分 UNIQUE index 未対応の driver: {$driver} (pgsql/sqlite のみ対応)");
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::PENDING_INDEX_NAME.
            " ON ticket_auto_recharge_attempts (organization_id) WHERE status = 'pending'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::PENDING_INDEX_NAME);
        Schema::dropIfExists('ticket_auto_recharge_attempts');
    }
};
