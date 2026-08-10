<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('render_jobs', function (Blueprint $table): void {
            // その動画が実際に含んだプレースホルダ (黒背景) クリップ数。
            // null = 「その動画について言えることが無い」(既存行 / queued / running / finalize 未到達の failed)。
            // **null を 0 と同一視しない** (0 = 黒背景ゼロで生成された、という積極的な事実)。
            // 索引は張らない (検索経路が無く、常に単一行の表示に使う)。
            $table->unsignedInteger('placeholder_cut_count')->nullable()->after('output_path');
        });
    }

    public function down(): void
    {
        Schema::table('render_jobs', function (Blueprint $table): void {
            $table->dropColumn('placeholder_cut_count');
        });
    }
};
