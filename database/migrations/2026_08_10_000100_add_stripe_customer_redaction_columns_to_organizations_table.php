<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T141 (標準形 v1 / 裁定 AG-128 の必須 (1)): 決済事業者側 customer の redaction **実施記録**。
 *
 * ★アプリは redaction を**実行しない**。人手 (決済事業者ダッシュボード) で行った操作を
 *   `billing:mark-stripe-customer-redacted` が自 DB に記録するだけである
 *   (退会経路から決済事業者 API を呼ばない原則 = T115)。
 *
 * ★**記録は 2 列セット**である:
 *   - `stripe_customer_redacted_at`: 実施日時
 *   - `stripe_customer_redacted_id`: 記録時点の `stripe_id` の写し
 *   日時だけだと「**どの** customer を redact 済みと記録したか」が事後に検証できず、
 *   `stripe_id` が差し替わる経路が将来できたときに監査列として意味を失う。
 *
 * ★両列は**同時に埋まり同時に NULL** である。この不変条件はアプリ層だけでなく
 *   **PostgreSQL の CHECK 制約**で守る (将来の別コマンドや直接 UPDATE で片側だけ書けると
 *   監査証跡として意味を失うため)。
 */
return new class extends Migration
{
    private const string CONSTRAINT = 'organizations_stripe_customer_redaction_pair_check';

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('stripe_customer_redacted_at')->nullable();
            $table->string('stripe_customer_redacted_id')->nullable();
        });

        DB::statement(
            'ALTER TABLE organizations ADD CONSTRAINT '.self::CONSTRAINT.' CHECK ('
            .'(stripe_customer_redacted_at IS NULL AND stripe_customer_redacted_id IS NULL)'
            .' OR (stripe_customer_redacted_at IS NOT NULL AND stripe_customer_redacted_id IS NOT NULL))',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['stripe_customer_redacted_at', 'stripe_customer_redacted_id']);
        });
    }
};
