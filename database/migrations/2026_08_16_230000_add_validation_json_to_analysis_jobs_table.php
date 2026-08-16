<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * analysis_jobs.validation_json: 手順書に対する LLM の所見 (SopValidationData の保存 shape)。
 *
 * result_json (作業分解表の write-only 監査スナップショット) とは**別カラム**にする:
 * こちらは詳細画面が読む表示契約であり、write-only の監査値と寿命・契約が違う。
 * NULL = 所見なし (本機能より前のジョブ / decompose 段に到達しなかったジョブ)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table): void {
            $table->json('validation_json')->nullable()->after('result_json');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table): void {
            $table->dropColumn('validation_json');
        });
    }
};
