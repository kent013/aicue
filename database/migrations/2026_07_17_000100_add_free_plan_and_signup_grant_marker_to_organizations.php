<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personal-free-plan: org レベル free entitlement と初回付与マーカーを導入する。
 *
 * - free_plan_code: 現在有効な free プラン code ('personal' のみ想定。汎用 framework 化はしない。
 *   値域はアプリ側で PersonalPlanService::FREE_PLAN_CODE 定数経由の書き込みに閉じる)
 * - personal_declared_at / personal_declared_by_user_id: 個人利用の自己申告 (監査証跡)
 * - signup_tickets_granted_at: 初回無償チケット付与の「org 単位で生涯 1 回」マーカー
 *   (free 有効化・paid サブスク成立の両経路で共用する真実源)
 * - partial unique index: 「1 user が declarer である active free personal org は 1 つまで」を
 *   DB で強制する (farming 防止の hard invariant。PostgreSQL / SQLite とも partial index 対応)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('free_plan_code', 32)->nullable();
            $table->timestamp('free_plan_activated_at')->nullable();
            $table->timestamp('personal_declared_at')->nullable();
            $table->foreignId('personal_declared_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('signup_tickets_granted_at')->nullable();

            $table->index('free_plan_code');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX organizations_personal_free_declarer_unique
            ON organizations (personal_declared_by_user_id)
            WHERE free_plan_code = 'personal' AND personal_declared_by_user_id IS NOT NULL
        SQL);

        // backfill (既存付与済 org のマーカー立て) は続く data migration
        // `2026_07_17_000110_backfill_signup_tickets_granted_at` に分離 (単体テスト可能にするため)。
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organizations_personal_free_declarer_unique');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('personal_declared_by_user_id');
            $table->dropIndex(['free_plan_code']);
            $table->dropColumn([
                'free_plan_code',
                'free_plan_activated_at',
                'personal_declared_at',
                'signup_tickets_granted_at',
            ]);
        });
    }
};
