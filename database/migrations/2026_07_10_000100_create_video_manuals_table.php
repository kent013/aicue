<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VideoManual: 動画マニュアル本体 (doc/10 §10.1 + §10.8)。Project 配下。
     *
     * - project_id / created_by は保護キー (payload から受けない)
     * - category_id は nullable + nullOnDelete (カテゴリ削除で未分類化 = §10.8-8)
     * - scenario_version はシナリオ一括保存の楽観ロック用 (§10.8-2。フェーズ1は default 0 固定)
     */
    public function up(): void
    {
        Schema::create('video_manuals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->string('status')->default('draft');
            $table->integer('scenario_version')->default(0);
            $table->integer('total_length_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_manuals');
    }
};
