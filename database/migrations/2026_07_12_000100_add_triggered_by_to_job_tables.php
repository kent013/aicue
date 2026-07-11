<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * analysis_jobs / render_jobs にジョブ実行者 (triggered_by) を追加する。
     *
     * 通知宛先 (creator ∪ triggeredBy) の導出用。Auth からの明示代入のみ
     * (MassAssignmentProtectedKeys 登録済み = payload 直送は 422 / $fillable 不含)。
     * ユーザー削除時は nullOnDelete (ジョブ行は監査のため残す)。
     */
    public function up(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table): void {
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('render_jobs', function (Blueprint $table): void {
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('triggered_by');
        });
        Schema::table('render_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('triggered_by');
        });
    }
};
