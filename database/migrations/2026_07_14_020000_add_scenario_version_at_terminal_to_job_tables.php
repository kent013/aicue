<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * analysis_jobs / render_jobs に失敗確定時の manual.scenario_version スナップショットを追加する。
     *
     * stale alert 判定用 (T032 / bug-hunt F-1-1)。「失敗確定後に scenario 保存が成立し
     * scenario_version が進んだ」失敗 job を server 側で stale と判定して alert を抑制する。
     * nullable: 既存行・非失敗行は null (null = not stale = 保守的に表示)。
     * scenario_version と同じ unsignedInteger。サービス内で明示代入のみ ($fillable 不含)。
     */
    public function up(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table): void {
            $table->unsignedInteger('scenario_version_at_terminal')->nullable()->after('error');
        });
        Schema::table('render_jobs', function (Blueprint $table): void {
            $table->unsignedInteger('scenario_version_at_terminal')->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table): void {
            $table->dropColumn('scenario_version_at_terminal');
        });
        Schema::table('render_jobs', function (Blueprint $table): void {
            $table->dropColumn('scenario_version_at_terminal');
        });
    }
};
